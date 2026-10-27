<?php

namespace App\Support\Health;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Code\Sandbox;
use App\Ai\ProviderKeys;
use App\Ai\Web\WebSearcher;
use App\Email\Imap\ImapGateway;
use App\Enums\ErpConnectionStatus;
use App\Enums\MailboxStatus;
use App\Erp\ErpGateway;
use App\Models\CalendarConnection;
use App\Models\ErpConnection;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Definições › Estado da configuração (docs/DECISOES.md, "Verificação da
 * configuração", an idea from OpenDuet): each thing the agents depend on,
 * Pronta, Não verificada or Precisa de configuração, with how to fix it.
 * «Verificar de novo» runs the real tests (a one-word request to the model,
 * the internet, each mailbox's IMAP login, the ERP); their outcome is kept for
 * a day, so opening the page costs nothing.
 */
final class HealthCheck
{
    public const READY = 'ready';

    public const UNCHECKED = 'unchecked';

    public const NEEDS_SETUP = 'needs_setup';

    private const KEEP_HOURS = 24;

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, fix: string|null, href: string|null, checked_at: string|null}>
     */
    public function all(User $user): array
    {
        return array_map(fn (string $key) => $this->result($key, $user), ['ai', 'internet', 'mailboxes', 'erp', 'queue', 'sandbox', 'web', 'calendar']);
    }

    /** Runs the real tests and keeps their outcome. */
    public function run(User $user): void
    {
        foreach (['ai', 'internet', 'mailboxes', 'erp'] as $key) {
            Cache::put($this->cacheKey($key), [...$this->test($key, $user), 'checked_at' => now()->toIso8601String()], now()->addHours(self::KEEP_HOURS));
        }
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string, fix: string|null, href: string|null, checked_at: string|null}
     */
    private function result(string $key, User $user): array
    {
        $labels = [
            'ai' => 'Modelo de IA', 'internet' => 'Internet', 'mailboxes' => 'Caixas de correio', 'erp' => 'ERP',
            'queue' => 'Fila de trabalho', 'sandbox' => 'Contas e ficheiros (código)', 'web' => 'Pesquisa na internet', 'calendar' => 'Google Calendar',
        ];

        // The cheap ones are always checked live; the others say what the last real test found.
        $found = match ($key) {
            'queue' => [...$this->queue(), 'checked_at' => now()->toIso8601String()],
            'sandbox' => [...$this->sandbox(), 'checked_at' => now()->toIso8601String()],
            'web' => [...$this->web(), 'checked_at' => now()->toIso8601String()],
            'calendar' => [...$this->calendar($user), 'checked_at' => now()->toIso8601String()],
            default => Cache::get($this->cacheKey($key)) ?? [...$this->unchecked($key), 'checked_at' => null],
        };

        return ['key' => $key, 'label' => $labels[$key], 'fix' => null, 'href' => null, ...$found];
    }

    /**
     * @return array{status: string, detail: string, fix?: string|null, href?: string|null}
     */
    private function test(string $key, User $user): array
    {
        return match ($key) {
            'ai' => $this->ai(),
            'internet' => $this->internet(),
            'mailboxes' => $this->mailboxes(),
            'erp' => $this->erp($user),
            default => $this->unchecked($key),
        };
    }

    /**
     * Before any real test: what the configuration alone says.
     *
     * @return array{status: string, detail: string, fix?: string|null, href?: string|null}
     */
    private function unchecked(string $key): array
    {
        if ($key === 'ai' && ! $this->hasAiKey()) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'Não há chave do modelo de IA: os agentes não trabalham.', 'fix' => 'Cole a chave em Provedores de IA.', 'href' => '/settings/ai-providers'];
        }

        if ($key === 'mailboxes' && Mailbox::query()->where('status', MailboxStatus::Error)->exists()) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'Há caixas com erro na última sincronização.', 'fix' => 'Veja a caixa e a palavra-passe em Caixas de email.', 'href' => '/settings/mailboxes'];
        }

        if ($key === 'erp' && ErpConnection::query()->where('status', ErpConnectionStatus::Error)->exists()) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'O ERP não respondeu da última vez.', 'fix' => 'Teste a ligação em ERP.', 'href' => '/settings/integrations/erp'];
        }

        return ['status' => self::UNCHECKED, 'detail' => 'Ainda não foi testado. Carregue em «Verificar de novo».'];
    }

    private function hasAiKey(): bool
    {
        $provider = (string) config('ai.default');

        return filled(config("ai.providers.{$provider}.key")) || filled(ProviderKeys::fromEnv($provider));
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function ai(): array
    {
        if (! $this->hasAiKey()) {
            return $this->unchecked('ai');
        }

        $provider = (string) config('ai.default');
        $model = (string) config('agents.suggestions_model') ?: null;

        try {
            $response = (new Ping)->prompt('OK?', provider: $provider, model: $model, timeout: 20);
            app(BudgetGuard::class)->charge('platform.health', $response->meta->provider ?? $provider, $response->meta->model ?? $model, (int) $response->usage->inputTokens, (int) $response->usage->outputTokens);
        } catch (Throwable $e) {
            return [
                'status' => self::NEEDS_SETUP,
                'detail' => 'O modelo não respondeu: '.Str::limit($e->getMessage(), 200),
                'fix' => str_contains(mb_strtolower($e->getMessage()), 'quota') || str_contains(mb_strtolower($e->getMessage()), 'credit')
                    ? 'A conta do provedor ficou sem crédito ou quota: carregue a conta ou use outra chave.'
                    : 'Confirme a chave em Provedores de IA.',
                'href' => '/settings/ai-providers',
            ];
        }

        return ['status' => self::READY, 'detail' => 'O modelo respondeu ('.(config("agents.providers.{$provider}.label") ?? $provider).').'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function internet(): array
    {
        try {
            $ok = Http::timeout(6)->head('https://www.google.com/generate_204')->successful();
        } catch (Throwable) {
            $ok = false;
        }

        return $ok
            ? ['status' => self::READY, 'detail' => 'O servidor chega à internet.']
            : ['status' => self::NEEDS_SETUP, 'detail' => 'O servidor não chega à internet.', 'fix' => 'Sem internet não há modelo de IA, email nem pesquisa: verifique a rede ou a firewall do servidor.'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function mailboxes(): array
    {
        $boxes = Mailbox::query()->whereNotIn('status', [MailboxStatus::Disabled])->whereNotNull('imap_host')->get();

        if ($boxes->isEmpty()) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'Não há caixas de correio ligadas.', 'fix' => 'Ligue a caixa da triagem em Caixas de email.', 'href' => '/settings/mailboxes'];
        }

        $failed = [];

        foreach ($boxes as $mailbox) {
            try {
                app(ImapGateway::class)->open($mailbox)->close();
            } catch (Throwable $e) {
                $failed[] = "{$mailbox->address} (".Str::limit($e->getMessage(), 80).')';
            }
        }

        return $failed === []
            ? ['status' => self::READY, 'detail' => $boxes->count() === 1 ? 'A caixa abre por IMAP.' : "As {$boxes->count()} caixas abrem por IMAP."]
            : ['status' => self::NEEDS_SETUP, 'detail' => 'Não abrem: '.implode('; ', $failed).'.', 'fix' => 'Confirme o servidor, o utilizador e a palavra-passe da caixa.', 'href' => '/settings/mailboxes'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function erp(User $user): array
    {
        $connection = ErpConnection::query()->first();

        if ($connection === null) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'Não há ERP ligado.', 'fix' => 'Ligue o ERP em ERP.', 'href' => '/settings/integrations/erp'];
        }

        $connection = app(ErpGateway::class)->test($connection, $user);

        return $connection->status === ErpConnectionStatus::Ok
            ? ['status' => self::READY, 'detail' => 'O ERP respondeu, com '.count($connection->capabilities ?? []).' ferramentas.']
            : ['status' => self::NEEDS_SETUP, 'detail' => 'O ERP não respondeu: '.Str::limit((string) $connection->last_error, 200), 'fix' => 'Confirme o endereço e a chave em ERP.', 'href' => '/settings/integrations/erp'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function queue(): array
    {
        try {
            $running = collect(app(MasterSupervisorRepository::class)->all())->isNotEmpty();
            $waiting = (int) Redis::connection(config('queue.connections.redis.connection', 'default'))->llen('queues:default');
        } catch (Throwable) {
            return ['status' => self::UNCHECKED, 'detail' => 'Não foi possível ler o estado da fila.'];
        }

        return $running
            ? ['status' => self::READY, 'detail' => 'A fila está a correr'.($waiting > 0 ? ", com {$waiting} trabalhos à espera." : ', sem atrasos.')]
            : ['status' => self::NEEDS_SETUP, 'detail' => 'A fila não está a correr: os agentes não começam nada.', 'fix' => 'Arranque o Horizon no servidor (php artisan horizon).'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function sandbox(): array
    {
        return app(Sandbox::class)->available()
            ? ['status' => self::READY, 'detail' => 'Os agentes podem fazer contas e ler folhas de cálculo com código isolado.']
            : ['status' => self::NEEDS_SETUP, 'detail' => 'A execução de código não está instalada: as contas ficam por fazer.', 'fix' => 'Instale-a com php artisan agents:install-sandbox.'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function web(): array
    {
        return WebSearcher::available()
            ? ['status' => self::READY, 'detail' => 'Os agentes podem pesquisar na internet.']
            : ['status' => self::NEEDS_SETUP, 'detail' => 'A pesquisa na internet não está disponível (sem chave do modelo ou sem orçamento).', 'fix' => 'Veja Provedores de IA e Consumo de IA.', 'href' => '/settings/ai-providers'];
    }

    /** @return array{status: string, detail: string, fix?: string|null, href?: string|null} */
    private function calendar(User $user): array
    {
        if (blank(config('services.google_calendar.client_id'))) {
            return ['status' => self::NEEDS_SETUP, 'detail' => 'Falta o cliente OAuth da Google: ninguém pode ligar a agenda.', 'fix' => 'Crie o cliente «Aplicação Web» na Google Cloud e ponha GOOGLE_CALENDAR_CLIENT_ID e o segredo no .env.'];
        }

        $failing = CalendarConnection::query()->whereNotNull('last_error')->count();

        return $failing === 0
            ? ['status' => self::READY, 'detail' => 'A agenda Google pode ligar-se; '.CalendarConnection::query()->count().' pessoa(s) ligada(s).']
            : ['status' => self::NEEDS_SETUP, 'detail' => "{$failing} agenda(s) não sincronizaram da última vez.", 'fix' => 'Quem tem a agenda com erro liga-a de novo na Agenda.', 'href' => '/agenda'];
    }

    private function cacheKey(string $key): string
    {
        return 'health:'.(Tenant::current()->id ?? 0).':'.$key;
    }
}
