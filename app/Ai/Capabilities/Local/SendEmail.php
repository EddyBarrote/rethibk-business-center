<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Email\MailboxMailer;
use App\Enums\AuditResult;
use App\Enums\EmailStatus;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Send an email from the agent's own mailbox (section 9). Sending to a new
 * external entity falls under the absolute ceiling (section 12.3).
 */
final class SendEmail extends LocalCapability
{
    public function __construct(private readonly MailboxMailer $mailer) {}

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
        return 'Envia um email a partir da caixa do próprio agente. Texto simples. Nunca envia da caixa de uma pessoa: aí deixa um rascunho.';
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
            'reply_to_email_id' => $schema->integer()->description('Se for resposta, o email a que responde (mantém a conversa).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $arguments = $this->validate($arguments);
        $mailbox = $this->mailboxFor($context);

        if ($mailbox === null || ! $mailbox->canSend()) {
            return CapabilityResult::error('o agente não tem uma caixa de correio activa com SMTP configurado.');
        }

        $original = isset($arguments['reply_to_email_id']) ? EmailMessage::query()->readableBy($context->agent)->find($arguments['reply_to_email_id']) : null;

        try {
            $this->mailer->send($mailbox, $arguments['to'], $arguments['cc'] ?? [], $arguments['subject'], $arguments['body'], $original, $context->run->id);
        } catch (RuntimeException $e) {
            return CapabilityResult::error($e->getMessage());
        }

        return CapabilityResult::data(['sent' => true, 'from' => $mailbox->address, 'to' => $arguments['to'], 'cc' => $arguments['cc'] ?? []]);
    }

    /**
     * Section 12.3: "envio de comunicação para fora da organização [...] a uma
     * entidade nova". New means no email from this tenant ever reached it.
     * The amount threshold in the same line is not defined yet ([CONFIRMAR]).
     */
    public function ceilingReason(array $arguments, CapabilityContext $context): ?string
    {
        $recipients = collect([...(array) ($arguments['to'] ?? []), ...(array) ($arguments['cc'] ?? [])])
            ->map(fn ($address) => Str::lower(trim((string) $address)))
            ->filter()
            ->unique();

        $external = $recipients->reject(fn (string $address) => $this->isInternal($address, $context));
        $new = $external->reject(fn (string $address) => $this->wasContacted($address));

        if ($new->isNotEmpty()) {
            return 'Comunicação externa a entidade nova: '.$new->implode(', ');
        }

        // A commercial proposal with a price always goes to a person, even to a
        // known client (docs/DECISOES.md, "Fluxos de trabalho", decisão 3).
        if ($external->isNotEmpty() && self::isPriceProposal((string) ($arguments['subject'] ?? '').' '.(string) ($arguments['body'] ?? ''))) {
            return 'Proposta comercial com preço para fora da organização';
        }

        return null;
    }

    /**
     * Whether a text reads like a proposal or quotation with a price.
     */
    public static function isPriceProposal(string $text): bool
    {
        $proposal = self::mentionsProposal($text);
        $text = Str::lower(Str::ascii($text));
        $price = preg_match('/(\d[\d .,]*\s*(mzn|mt|meticais|usd|\$|eur|€))|((mzn|usd|eur|€|\$)\s*\d)|\b(preco|valor total|total|price)\b/', $text) === 1;

        return $proposal && $price;
    }

    /**
     * Whether a text talks about a proposal, a budget or a quotation.
     */
    public static function mentionsProposal(string $text): bool
    {
        return preg_match('/\b(proposta|orcamento|cotacao|quotation|quote|proposal)\b/', Str::lower(Str::ascii($text))) === 1;
    }

    public function summarise(array $arguments): string
    {
        return 'Enviar email a '.implode(', ', (array) ($arguments['to'] ?? [])).': '.($arguments['subject'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{to: list<string>, cc?: list<string>, subject: string, body: string, reply_to_email_id?: int|null}
     */
    private function validate(array $arguments): array
    {
        /** @var array{to: list<string>, cc?: list<string>, subject: string, body: string, reply_to_email_id?: int|null} */
        return Validator::make($arguments, [
            'to' => 'required|array|min:1|max:20',
            'to.*' => 'required|email',
            'cc' => 'nullable|array|max:20',
            'cc.*' => 'email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:50000',
            'reply_to_email_id' => 'nullable|integer',
        ])->validate();
    }

    private function mailboxFor(CapabilityContext $context): ?Mailbox
    {
        // Only the agent's own mailbox: never a person's (docs/DECISOES.md, "Caixas de email por pessoa").
        return Mailbox::query()->where('agent_id', $context->agent->id)->where('kind', Mailbox::AGENT)->first();
    }

    private function isInternal(string $address, CapabilityContext $context): bool
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
        return EmailMessage::query()->where('direction', 'outbound')->where('status', EmailStatus::Sent)->whereJsonContains('to', $address)->exists()
            || AuditLog::query()
                ->where('action', $this->key())
                ->where('result', AuditResult::Ok)
                ->where('payload', 'like', '%"'.str_replace(['%', '_'], ['\%', '\_'], $address).'"%')
                ->exists();
    }
}
