<?php

namespace App\Email;

use App\Enums\EmailStatus;
use App\Mail\AgentMessage;
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
     * @param  list<string>  $to
     * @param  list<string>  $cc
     *
     * @throws RuntimeException when the mailbox cannot send or SMTP fails
     */
    public function send(Mailbox $mailbox, array $to, array $cc, string $subject, string $body, ?EmailMessage $original = null, ?int $runId = null, ?EmailMessage $draft = null, ?User $sender = null): EmailMessage
    {
        if ($mailbox->isPersonal() && ($sender === null || ! $mailbox->isOwnedBy($sender))) {
            throw new RuntimeException('só os donos desta caixa enviam a partir dela.');
        }

        if (! $mailbox->canSend()) {
            throw new RuntimeException('a caixa de correio não está activa ou não tem SMTP configurado.');
        }

        $references = $original ? trim(($original->references ?? '').' '.$original->message_id_header) : null;

        try {
            Mail::mailer($this->configure($mailbox))->send(new AgentMessage(
                $mailbox->address,
                $mailbox->display_name,
                $to,
                $cc,
                $subject,
                $body,
                $original?->message_id_header,
                $references ?: null,
            ));
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
            'in_reply_to' => $original?->message_id_header,
            'references' => $references ?: null,
            'from_address' => $mailbox->address,
            'from_name' => $mailbox->display_name,
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'text_body' => $body,
            'status' => EmailStatus::Sent,
            'agent_run_id' => $draft->agent_run_id ?? $runId,
            'sent_at' => now(),
        ])->save();

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
