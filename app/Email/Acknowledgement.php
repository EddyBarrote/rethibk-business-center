<?php

namespace App\Email;

use App\Enums\EmailCategory;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The «we received it and are on it» reply of a company mailbox
 * (docs/DECISOES.md, "Confirmação automática de recepção"). A fixed text the
 * admin wrote, never a model's: it promises nothing but that the email
 * arrived. Sent once per conversation and per sender a day, only to real
 * senders outside the company, only for the kinds of email chosen, and only
 * for email that just arrived.
 */
final class Acknowledgement
{
    /** What a company address usually acknowledges. */
    public const DEFAULT_CATEGORIES = [EmailCategory::ClientRfq, EmailCategory::ClientRequest, EmailCategory::Lead, EmailCategory::Tender];

    public const DEFAULT_BODY = "Olá {nome},\n\nRecebemos o seu email «{assunto}» e já estamos a tratar do assunto. Voltaremos ao contacto brevemente.\n\nCom os melhores cumprimentos,\n{empresa}";

    /** Addresses that are machines, not people. */
    private const AUTOMATED = '/^(no-?reply|noreply|do-?not-?reply|mailer-daemon|postmaster|bounces?|notifications?|alerts?|newsletter)([+.\-_@]|$)/i';

    public const FLAG = 'acknowledged';

    public function __construct(private readonly MailboxMailer $mailer) {}

    /**
     * Why this email gets no acknowledgement, or null when it does.
     */
    public function skipReason(EmailMessage $email): ?string
    {
        $mailbox = $email->mailbox;
        $from = Str::lower((string) $email->from_address);
        $category = $email->classification instanceof EmailCategory ? $email->classification : EmailCategory::tryFrom((string) $email->classification);

        return match (true) {
            ! $mailbox->auto_ack => 'a caixa não confirma a recepção',
            $email->direction !== 'inbound' => 'não é um email recebido',
            $category === null || ! in_array($category, self::categories($mailbox), true) => 'este tipo de email não é confirmado',
            $email->hasFlag(self::FLAG) => 'já foi confirmado',
            $email->received_at !== null && $email->received_at->lt(now()->subDays(2)) => 'chegou há mais de dois dias',
            $from === '' || preg_match(self::AUTOMATED, $from) === 1 => 'o remetente é automático',
            $this->internal($from, $mailbox) => 'o remetente é da empresa',
            $this->alreadyThisConversation($email) => 'a conversa já foi confirmada',
            $this->alreadyToday($email) => 'o remetente já recebeu uma confirmação hoje',
            ! $mailbox->canSend() => 'a caixa não tem envio configurado',
            default => null,
        };
    }

    /**
     * Sends it, in reply, from the email's own mailbox. Returns the sent email, or null when it is skipped.
     */
    public function send(EmailMessage $email): ?EmailMessage
    {
        if ($this->skipReason($email) !== null) {
            return null;
        }

        $mailbox = $email->mailbox;
        // Marked first, so a retry never sends it twice.
        $email->forceFill(['flags' => [...($email->flags ?? []), self::FLAG]])->save();

        $subject = Str::startsWith(Str::lower((string) $email->subject), ['re:', 'res:']) ? (string) $email->subject : 'Re: '.($email->subject ?: 'o seu email');
        $sent = $this->mailer->send($mailbox, [(string) $email->from_address], [], $subject, self::render($mailbox, $email), $email, acknowledgement: true);
        $sent->forceFill(['flags' => [self::FLAG]])->save();

        AuditLog::record(null, 'email.acknowledged', ['email_id' => $email->id, 'to' => $email->from_address, 'mailbox' => $mailbox->address], subject: $sent);

        return $sent;
    }

    /**
     * @return list<EmailCategory>
     */
    public static function categories(Mailbox $mailbox): array
    {
        $chosen = $mailbox->auto_ack_categories;

        return $chosen === null ? self::DEFAULT_CATEGORIES : array_values(array_filter(array_map(fn ($value) => EmailCategory::tryFrom((string) $value), $chosen)));
    }

    public static function render(Mailbox $mailbox, EmailMessage $email): string
    {
        $name = trim((string) Str::of((string) $email->from_name)->before('<')->trim(" \"'"));
        $text = strtr(filled($mailbox->auto_ack_body) ? (string) $mailbox->auto_ack_body : self::DEFAULT_BODY, [
            '{nome}' => $name,
            '{assunto}' => (string) ($email->subject ?: 'sem assunto'),
            '{empresa}' => (string) (Tenant::current()->name ?? $mailbox->display_name),
        ]);

        // «Olá ,» when the sender has no name.
        return (string) preg_replace('/ +([,.!])/', '$1', $text);
    }

    private function internal(string $from, Mailbox $mailbox): bool
    {
        $domain = Str::after(Str::lower($mailbox->address), '@');

        return Str::after($from, '@') === $domain
            || Mailbox::query()->whereRaw('lower(address) = ?', [$from])->exists()
            || User::query()->whereRaw('lower(email) = ?', [$from])->exists();
    }

    private function alreadyThisConversation(EmailMessage $email): bool
    {
        return $email->thread_id !== null && EmailMessage::query()
            ->where('thread_id', $email->thread_id)
            ->whereKeyNot($email->id)
            ->whereJsonContains('flags', self::FLAG)
            ->exists();
    }

    private function alreadyToday(EmailMessage $email): bool
    {
        return EmailMessage::query()
            ->where('mailbox_id', $email->mailbox_id)
            ->where('direction', 'outbound')
            ->whereJsonContains('flags', self::FLAG)
            ->whereJsonContains('to', Str::lower((string) $email->from_address))
            ->where('sent_at', '>=', now()->subDay())
            ->exists();
    }
}
