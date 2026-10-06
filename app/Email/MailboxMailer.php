<?php

namespace App\Email;

use App\Enums\EmailStatus;
use App\Mail\AgentMessage;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends from a mailbox over its own SMTP settings and records the message in
 * the inbox, so the conversation shows what was sent. Used by the SendEmail
 * capability (an agent's own mailbox) and by people sending a draft. A
 * person's mailbox sends only for one of its owners: agents never send in a
 * person's name (docs/DECISOES.md, "Caixas de email por pessoa").
 */
final class MailboxMailer
{
    /**
     * The raw message as sent, when the mailer kept it (not under Mail::fake).
     */
    public ?string $lastRaw = null;

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     *
     * @throws RuntimeException when the mailbox cannot send or SMTP fails
     */
    public function send(Mailbox $mailbox, array $to, array $cc, string $subject, string $body, ?EmailMessage $original = null, ?int $runId = null, ?EmailMessage $draft = null, ?User $sender = null, array $bcc = []): EmailMessage
    {
        $this->lastRaw = null;

        if ($mailbox->isPersonal() && ($sender === null || ! $mailbox->isOwnedBy($sender))) {
            throw new RuntimeException('só os donos desta caixa enviam a partir dela.');
        }

        if (! $mailbox->canSend()) {
            throw new RuntimeException('a caixa de correio não está activa ou não tem SMTP configurado.');
        }

        $references = $original ? trim(($original->references ?? '').' '.$original->message_id_header) : ($draft?->references ?: null);
        $inReplyTo = $original->message_id_header ?? $draft?->in_reply_to;
        // A draft keeps the Message-ID it had on the server, so the copy in Enviados is the same message.
        $messageId = $draft?->message_id_header ?: '<'.Str::uuid().'@'.(Str::after($mailbox->address, '@') ?: 'micomoc.local').'>';
        $files = $draft === null ? [] : $draft->attachments()->whereNotNull('path')->get()
            ->map(fn (EmailAttachment $a) => ['disk' => $a->diskName(), 'path' => (string) $a->path, 'name' => $a->filename, 'mime' => $a->mime_type])->values()->all();

        try {
            $sent = Mail::mailer($this->configure($mailbox))->send(new AgentMessage(
                $mailbox->address,
                $mailbox->display_name,
                $to,
                $cc,
                $subject,
                $body,
                $inReplyTo,
                $references ?: null,
                $bcc,
                $files,
                $messageId,
            ));
            $this->lastRaw = $sent?->toString();
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();

            throw new RuntimeException('o envio falhou: '.Str::limit($e->getMessage(), 200), previous: $e);
        }

        $mailbox->forceFill(['last_outbound_at' => now(), 'last_error' => null])->save();

        $message = $draft ?? new EmailMessage;
        $message->fill([
            'mailbox_id' => $mailbox->id,
            'direction' => 'outbound',
            'thread_id' => $original->thread_id ?? $draft?->thread_id,
            'message_id_header' => $messageId,
            // The same key as ingestion, so the copy read back from Enviados is this row.
            'dedup_hash' => hash('sha256', $messageId.'|'.$mailbox->id),
            'in_reply_to' => $inReplyTo,
            'references' => $references ?: null,
            'from_address' => $mailbox->address,
            'from_name' => $mailbox->display_name,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'text_body' => $body,
            'status' => EmailStatus::Sent,
            'agent_run_id' => $draft->agent_run_id ?? $runId,
            'sent_at' => now(),
            'read_at' => now(),
            'written_by_user_id' => $draft->written_by_user_id ?? $sender?->id,
        ])->save();

        if ($message->thread_id === null) {
            $message->forceFill(['thread_id' => app(ThreadResolver::class)->resolve($mailbox, null, [], $subject, [...$to, ...$cc])->id])->save();
        }

        return $message;
    }

    /**
     * A mailer per mailbox, built from its encrypted SMTP settings.
     */
    private function configure(Mailbox $mailbox): string
    {
        $name = 'mailbox_'.$mailbox->id;

        config(["mail.mailers.{$name}" => array_filter([
            'transport' => 'smtp',
            'host' => $mailbox->smtp_host,
            'port' => $mailbox->smtp_port,
            'username' => $mailbox->smtp_username,
            'password' => $mailbox->smtp_password,
            'scheme' => $mailbox->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'timeout' => 30,
        ], fn ($value) => $value !== null)]);

        app('mail.manager')->purge($name);

        return $name;
    }
}
