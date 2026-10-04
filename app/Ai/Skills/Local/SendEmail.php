<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\AuditResult;
use App\Mail\AgentMessage;
use App\Models\AuditLog;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Send an email from the agent's own mailbox (section 9). Sending to a new
 * external entity falls under the absolute ceiling (section 12.3).
 */
final class SendEmail extends LocalSkill
{
    public function key(): string
    {
        return 'comms.send_email';
    }

    public function name(): string
    {
        return 'Enviar email';
    }

    public function description(): string
    {
        return 'Envia um email a partir da caixa do agente. Texto simples.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->array()->items($schema->string())->description('Endereços de destino.')->required(),
            'cc' => $schema->array()->items($schema->string()),
            'subject' => $schema->string()->required(),
            'body' => $schema->string()->description('Corpo em texto simples.')->required(),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $arguments = $this->validate($arguments);
        $mailbox = $this->mailboxFor($context);

        if ($mailbox === null || ! $mailbox->canSend()) {
            return SkillResult::error('o agente não tem uma caixa de correio activa com SMTP configurado.');
        }

        try {
            Mail::build(array_filter([
                'transport' => 'smtp',
                'host' => $mailbox->smtp_host,
                'port' => $mailbox->smtp_port,
                'username' => $mailbox->smtp_username,
                'password' => $mailbox->smtp_password,
                'scheme' => $mailbox->smtp_encryption === 'ssl' ? 'smtps' : null,
            ], fn ($value) => $value !== null))->send(new AgentMessage(
                $mailbox->address,
                $mailbox->display_name,
                $arguments['to'],
                $arguments['cc'] ?? [],
                $arguments['subject'],
                $arguments['body'],
            ));
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();

            return SkillResult::error('o envio falhou: '.Str::limit($e->getMessage(), 200));
        }

        $mailbox->forceFill(['last_outbound_at' => now(), 'last_error' => null])->save();

        return SkillResult::data(['sent' => true, 'from' => $mailbox->address, 'to' => $arguments['to'], 'cc' => $arguments['cc'] ?? []]);
    }

    /**
     * Section 12.3: "envio de comunicação para fora da organização [...] a uma
     * entidade nova". New means no email from this tenant ever reached it.
     * The amount threshold in the same line is not defined yet ([CONFIRMAR]).
     */
    public function ceilingReason(array $arguments, SkillContext $context): ?string
    {
        $recipients = collect([...(array) ($arguments['to'] ?? []), ...(array) ($arguments['cc'] ?? [])])
            ->map(fn ($address) => Str::lower(trim((string) $address)))
            ->filter()
            ->unique();

        $new = $recipients->reject(fn (string $address) => $this->isInternal($address, $context) || $this->wasContacted($address));

        return $new->isEmpty() ? null : 'Comunicação externa a entidade nova: '.$new->implode(', ');
    }

    public function summarise(array $arguments): string
    {
        return 'Enviar email a '.implode(', ', (array) ($arguments['to'] ?? [])).': '.($arguments['subject'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{to: list<string>, cc?: list<string>, subject: string, body: string}
     */
    private function validate(array $arguments): array
    {
        /** @var array{to: list<string>, cc?: list<string>, subject: string, body: string} */
        return Validator::make($arguments, [
            'to' => 'required|array|min:1|max:20',
            'to.*' => 'required|email',
            'cc' => 'nullable|array|max:20',
            'cc.*' => 'email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:50000',
        ])->validate();
    }

    private function mailboxFor(SkillContext $context): ?Mailbox
    {
        return Mailbox::query()->where('agent_id', $context->agent->id)->first();
    }

    private function isInternal(string $address, SkillContext $context): bool
    {
        $domain = Str::after($address, '@');
        $tenant = Tenant::current();
        $ownDomains = collect([$tenant?->domain, ...Mailbox::query()->pluck('address')->map(fn (string $a) => Str::after($a, '@'))])
            ->filter()
            ->map(fn (string $d) => Str::lower($d));

        return $ownDomains->contains($domain) || User::query()->where('email', $address)->exists();
    }

    private function wasContacted(string $address): bool
    {
        return AuditLog::query()
            ->where('action', $this->key())
            ->where('result', AuditResult::Ok)
            ->where('payload', 'like', '%"'.str_replace(['%', '_'], ['\%', '\_'], $address).'"%')
            ->exists();
    }
}
