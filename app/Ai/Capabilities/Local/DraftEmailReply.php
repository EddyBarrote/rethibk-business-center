<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A reply left as a draft in the conversation for a person to review and
 * send from the inbox. Nothing leaves the company.
 */
final class DraftEmailReply extends LocalCapability
{
    public function key(): string
    {
        return 'email.draft_reply';
    }

    public function name(): string
    {
        return 'Redigir resposta';
    }

    public function description(): string
    {
        return 'Deixa um rascunho de resposta a um email, para uma pessoa rever e enviar. Não envia.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email_id' => $schema->integer()->description('O email a que se responde.')->required(),
            'body' => $schema->string()->description('Texto da resposta, em português.')->required(),
            'subject' => $schema->string()->description('Por omissão: "Re: " + assunto original.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, ['email_id' => 'required|integer', 'body' => 'required|string|max:50000', 'subject' => 'nullable|string|max:255'])->validate();
        $original = EmailMessage::query()->readableBy($context->agent)->with('mailbox')->find($data['email_id']);

        if ($original === null) {
            return CapabilityResult::error('email não encontrado.');
        }

        // A reply to a person's email is drafted in their mailbox, for them to send; the agent never does.
        $mailbox = $original->mailbox->isPersonal()
            ? $original->mailbox
            : (Mailbox::query()->where('agent_id', $context->agent->id)->first() ?? $original->mailbox);
        $subject = $data['subject'] ?? (Str::startsWith(Str::lower((string) $original->subject), 're:') ? $original->subject : 'Re: '.$original->subject);

        $draft = EmailMessage::query()->create([
            'mailbox_id' => $mailbox->id,
            'direction' => 'outbound',
            'thread_id' => $original->thread_id,
            'in_reply_to' => $original->message_id_header,
            'references' => trim(($original->references ?? '').' '.$original->message_id_header),
            'from_address' => $mailbox->address,
            'from_name' => $mailbox->display_name,
            'to' => [$original->reply_to ?: $original->from_address],
            'subject' => $subject,
            'text_body' => $data['body'],
            'status' => EmailStatus::Draft,
            'agent_run_id' => $context->run->id,
        ]);

        return CapabilityResult::data(['draft_id' => $draft->id, 'to' => $draft->to, 'subject' => $subject, 'review_at' => "/inbox/{$original->id}"]);
    }

    public function summarise(array $arguments): string
    {
        return 'Rascunho de resposta ao email #'.($arguments['email_id'] ?? '?');
    }
}
