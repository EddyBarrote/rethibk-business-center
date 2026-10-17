<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\AgentDirectory;
use App\Email\AgentDrafts;
use App\Enums\AutonomyLevel;
use App\Enums\EmailStatus;
use App\Jobs\PushDraftToServer;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A reply left as a draft for a person to review and send from Email (in
 * their Rascunhos, also on the server). Nothing leaves the company. One open
 * draft per email: drafting again rewrites it, unless a person wrote or
 * changed it.
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

        // Triage reads and hands on what a person's mailbox sends it; the area that takes the task drafts the answer.
        if ($original->mailbox->isPersonal() && $context->agent->id === app(AgentDirectory::class)->forRole('triage')?->id) {
            return CapabilityResult::error('a triagem não responde nem prepara respostas a emails de caixas de pessoas; quem recebe a tarefa trata da resposta.');
        }

        // A reply to a person's email is drafted in their mailbox, for them to send; the agent never does.
        $mailbox = $original->mailbox->isPersonal()
            ? $original->mailbox
            : (Mailbox::query()->where('agent_id', $context->agent->id)->first() ?? $original->mailbox);
        $subject = $data['subject'] ?? (Str::startsWith(Str::lower((string) $original->subject), 're:') ? $original->subject : 'Re: '.$original->subject);
        $to = [$original->reply_to ?: $original->from_address];
        $drafts = app(AgentDrafts::class);

        // One open draft per email: drafting again (a retry, a second task, a revision) rewrites it (docs/DECISOES.md, "Um só rascunho por email").
        $open = $drafts->replyTo($mailbox, $original);

        if ($open !== null && $drafts->isPersons($open)) {
            return CapabilityResult::data([
                'draft_id' => $open->id,
                'changed' => false,
                'next' => 'Já há um rascunho de resposta a este email que uma pessoa escreveu ou alterou; não o substituí nem criei outro. Diz à pessoa, em poucas linhas, o que mudarias no rascunho dela.',
            ]);
        }

        $draft = $open !== null
            ? $drafts->rewrite($open, $context->run, ['to' => $to, 'subject' => $subject, 'text_body' => $data['body']])
            : $this->create($mailbox, $original, $to, $subject, $data['body'], $context);

        // Into the owner's Rascunhos on the server too, for review in any email client (the old copy is replaced).
        if (filled($mailbox->imap_host)) {
            PushDraftToServer::dispatch($draft->tenant_id, $draft->id);
        }

        return CapabilityResult::data(array_filter([
            'draft_id' => $draft->id,
            'to' => $draft->to,
            'subject' => $subject,
            'review_at' => "/inbox/{$original->id}",
            'updated' => $open !== null ? 'Já havia um rascunho de resposta a este email: actualizei esse, não há outro.' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  list<string|null>  $to
     */
    private function create(Mailbox $mailbox, EmailMessage $original, array $to, string $subject, string $body, CapabilityContext $context): EmailMessage
    {
        $messageId = '<'.Str::uuid().'@'.(Str::after($mailbox->address, '@') ?: 'micomoc.local').'>';

        return EmailMessage::query()->create([
            'mailbox_id' => $mailbox->id,
            'direction' => 'outbound',
            'thread_id' => $original->thread_id,
            'in_reply_to' => $original->message_id_header,
            'references' => trim(($original->references ?? '').' '.$original->message_id_header),
            'from_address' => $mailbox->address,
            'from_name' => $mailbox->display_name,
            'to' => $to,
            'subject' => $subject,
            'text_body' => $body,
            'status' => EmailStatus::Draft,
            'agent_run_id' => $context->run->id,
            'message_id_header' => $messageId,
            'dedup_hash' => hash('sha256', $messageId.'|'.$mailbox->id),
            'read_at' => now(),
        ]);
    }

    public function summarise(array $arguments): string
    {
        return 'Rascunho de resposta ao email #'.($arguments['email_id'] ?? '?');
    }
}
