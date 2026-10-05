<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * Read one email of the tenant, or a whole conversation. External content is
 * fenced as untrusted data (section 9.4).
 */
final class ReadEmail extends LocalCapability
{
    public function key(): string
    {
        return 'email.read';
    }

    public function name(): string
    {
        return 'Ler email';
    }

    public function description(): string
    {
        return 'Lê um email (ou a conversa inteira) pelo número, com a lista de anexos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email_id' => $schema->integer()->required(),
            'whole_thread' => $schema->boolean()->description('Ler todas as mensagens da conversa.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $message = EmailMessage::query()->readableBy($context->agent)->with('attachments')->find((int) ($arguments['email_id'] ?? 0));

        if ($message === null) {
            return CapabilityResult::error('email não encontrado.');
        }

        $messages = ($arguments['whole_thread'] ?? false) && $message->thread_id !== null
            ? EmailMessage::query()->readableBy($context->agent)->with('attachments')->where('thread_id', $message->thread_id)->orderBy('received_at')->orderBy('id')->get()
            : collect([$message]);

        return CapabilityResult::text($messages->map(fn (EmailMessage $m) => self::render($m))->implode("\n\n"));
    }

    public static function render(EmailMessage $m, int $limit = 8000): string
    {
        $attachments = $m->attachments->map(fn (EmailAttachment $a) => "  - anexo #{$a->id}: {$a->filename} ({$a->mime_type})")->implode("\n");
        $direction = $m->direction === 'inbound' ? 'recebido' : 'enviado';
        $triage = $m->classification ? "Triagem: {$m->classification->label()}".($m->summary ? " — {$m->summary}" : '') : 'Sem triagem.';
        $flags = $m->flags ? ' Sinais: '.implode(', ', $m->flags).'.' : '';

        return "Email #{$m->id} ({$direction}, {$m->status->label()}). {$triage}{$flags}\n"
            ."<email_externo_nao_confiavel>\n"
            ."De: {$m->from_name} <{$m->from_address}>\nPara: ".implode(', ', $m->to ?? [])."\n"
            ."Data: {$m->received_at?->toIso8601String()}\nAssunto: {$m->subject}\n\n"
            .Str::limit($m->plainText(), $limit)
            ."\n</email_externo_nao_confiavel>"
            .($attachments !== '' ? "\nAnexos:\n{$attachments}" : '');
    }
}
