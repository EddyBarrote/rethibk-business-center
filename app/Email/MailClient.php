<?php

namespace App\Email;

use App\Email\Imap\ImapGateway;
use App\Email\Imap\ImapSession;
use App\Enums\EmailStatus;
use App\Models\EmailAttachment;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * What a person does in the email client (docs/DECISOES.md, "Email: um
 * cliente de email na plataforma"). On a mailbox with IMAP each action is
 * done on the server first, so Outlook or the phone see the same; the
 * platform only changes once the server agreed. Drafts live in the server's
 * Rascunhos and copies of what is sent in its Enviados.
 */
final class MailClient
{
    public function __construct(
        private readonly ImapGateway $imap,
        private readonly MailboxSync $sync,
        private readonly MailboxMailer $mailer,
    ) {}

    public function markRead(EmailMessage $message, bool $read): void
    {
        $this->onServer($message, fn (ImapSession $s, int $uid) => $read ? $s->addFlags([$uid], ['\\Seen']) : $s->removeFlags([$uid], ['\\Seen']));
        $message->forceFill(['read_at' => $read ? ($message->read_at ?? now()) : null])->save();
        $this->recount($message->folder);
    }

    public function flag(EmailMessage $message, bool $flagged): void
    {
        $this->onServer($message, fn (ImapSession $s, int $uid) => $flagged ? $s->addFlags([$uid], ['\\Flagged']) : $s->removeFlags([$uid], ['\\Flagged']));
        $message->forceFill(['is_flagged' => $flagged])->save();
    }

    /**
     * @throws RuntimeException
     */
    public function move(EmailMessage $message, EmailFolder $target): void
    {
        $from = $message->folder;
        abort_unless($target->mailbox_id === $message->mailbox_id, 422, 'A pasta é de outra caixa.');

        if ($from?->id === $target->id) {
            return;
        }

        $newUid = null;

        if ($from !== null && $from->onServer() && $message->imap_uid !== null && $target->onServer()) {
            $newUid = $this->session($message->mailbox, function (ImapSession $s) use ($from, $target, $message) {
                $s->select($from->path);
                $s->move([(int) $message->imap_uid], $target->path);
                $s->select($target->path);

                return filled($message->message_id_header) ? $s->findByMessageId((string) $message->message_id_header) : null;
            });
        }

        $message->forceFill(['email_folder_id' => $target->id, 'imap_uid' => $newUid, 'removed_at' => null])->save();
        $this->recount($from);
        $this->recount($target);
    }

    public function archive(EmailMessage $message): void
    {
        $this->move($message, $this->requireFolder($message->mailbox, EmailFolder::ARCHIVE));
    }

    /**
     * To the Lixo; from the Lixo or Spam, deleted for good.
     */
    public function delete(EmailMessage $message): void
    {
        $folder = $message->folder;

        if ($message->status === EmailStatus::Draft) {
            $this->discardDraft($message);

            return;
        }

        if ($folder === null || in_array($folder->role, [EmailFolder::TRASH, EmailFolder::JUNK], true)) {
            $this->onServer($message, fn (ImapSession $s, int $uid) => $s->delete([$uid]));
            // The row stays for the tasks that point to it; it leaves every folder.
            $message->forceFill(['email_folder_id' => null, 'imap_uid' => null, 'removed_at' => now()])->save();
            $this->recount($folder);

            return;
        }

        $this->move($message, $this->requireFolder($message->mailbox, EmailFolder::TRASH));
    }

    /**
     * A person's draft: new, or the one being edited, also in the server's
     * Rascunhos. Files come from the uploads of the composer (new) and from
     * the draft or the forwarded email (kept).
     *
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body: string}  $data
     * @param  list<int>  $uploads  ids of the person's uploads (message_attachments)
     * @param  list<int>  $keep  ids of email attachments to keep or bring along
     */
    public function saveDraft(Mailbox $mailbox, User $author, array $data, array $uploads = [], array $keep = [], ?EmailMessage $draft = null, ?EmailMessage $replyTo = null): EmailMessage
    {
        $draft ??= new EmailMessage;
        $first = ! $draft->exists;
        $messageId = $draft->message_id_header ?: '<'.Str::uuid().'@'.(Str::after($mailbox->address, '@') ?: 'micomoc.local').'>';
        $references = $replyTo !== null ? trim(($replyTo->references ?? '').' '.$replyTo->message_id_header) : $draft->references;

        DB::transaction(function () use ($draft, $mailbox, $author, $data, $uploads, $keep, $first, $messageId, $references, $replyTo) {
            $draft->fill([
                'mailbox_id' => $mailbox->id,
                'direction' => 'outbound',
                'status' => EmailStatus::Draft,
                'message_id_header' => $messageId,
                'dedup_hash' => hash('sha256', $messageId.'|'.$mailbox->id),
                'in_reply_to' => $replyTo !== null ? $replyTo->message_id_header : $draft->in_reply_to,
                'references' => $references ?: null,
                'thread_id' => $replyTo !== null ? $replyTo->thread_id : $draft->thread_id,
                'from_address' => $mailbox->address,
                'from_name' => $mailbox->display_name,
                'to' => $data['to'],
                'cc' => $data['cc'],
                'bcc' => $data['bcc'],
                'subject' => $data['subject'],
                'text_body' => $data['body'],
                'read_at' => now(),
            ]);

            if ($first) {
                $draft->written_by_user_id = $author->id;
            }

            $draft->save();

            $this->syncFiles($draft, $author, $uploads, $keep);
        });

        $this->pushDraft($draft->refresh());

        return $draft;
    }

    /**
     * Puts a draft in the server's Rascunhos (an agent's or a person's),
     * replacing the copy it had there. Best effort: the draft is safe here.
     */
    public function pushDraft(EmailMessage $draft): void
    {
        $mailbox = $draft->mailbox;

        if (blank($mailbox->imap_host) || $draft->status !== EmailStatus::Draft) {
            return;
        }

        try {
            $this->session($mailbox, function (ImapSession $s) use ($draft, $mailbox) {
                $folder = $this->sync->folderFor($mailbox, EmailFolder::DRAFTS, $s);

                if ($folder === null) {
                    return;
                }

                $old = $draft->email_folder_id === $folder->id ? $draft->imap_uid : null;
                $uid = $s->append($folder->path, $this->raw($draft), ['\\Draft', '\\Seen']);

                $s->select($folder->path);

                if ($old !== null) {
                    $s->delete([(int) $old]);
                }

                $uid ??= $s->findByMessageId((string) $draft->message_id_header);
                $draft->forceFill(['email_folder_id' => $folder->id, 'imap_uid' => $uid])->save();
                $folder->forceFill(['last_uid' => max((int) $folder->last_uid, (int) $uid) ?: null])->save();
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function discardDraft(EmailMessage $draft): void
    {
        abort_unless($draft->status === EmailStatus::Draft, 409, 'Este email já não é um rascunho.');

        try {
            $this->onServer($draft, fn (ImapSession $s, int $uid) => $s->delete([$uid]));
        } catch (RuntimeException $e) {
            // A draft that cannot leave the server still leaves the platform; the next sync brings it back if it is still there.
            report($e);
        }

        $draft->attachments()->get()->each(fn (EmailAttachment $a) => $this->deleteFile($a));
        $draft->delete();
    }

    /**
     * Sends a draft over SMTP, keeps a copy in the server's Enviados and takes
     * it out of the Rascunhos.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     *
     * @throws RuntimeException when it was not sent
     */
    public function send(EmailMessage $draft, User $sender, array $to, array $cc, array $bcc, string $subject, string $body): EmailMessage
    {
        abort_unless($draft->status === EmailStatus::Draft, 409, 'Este email já não é um rascunho.');

        $mailbox = $draft->mailbox;
        $original = $draft->in_reply_to ? EmailMessage::query()->where('mailbox_id', $mailbox->id)->where('message_id_header', $draft->in_reply_to)->first() : null;
        $draftFolder = $draft->folder;
        $draftUid = $draft->imap_uid;

        $sent = $this->mailer->send($mailbox, $to, $cc, $subject, $body, $original, draft: $draft, sender: $sender, bcc: $bcc);
        $raw = $this->mailer->lastRaw;

        if (filled($mailbox->imap_host)) {
            try {
                $this->session($mailbox, function (ImapSession $s) use ($mailbox, $sent, $raw, $draftFolder, $draftUid) {
                    if ($draftFolder !== null && $draftFolder->onServer() && $draftUid !== null) {
                        $s->select($draftFolder->path);
                        $s->delete([(int) $draftUid]);
                    }

                    $folder = $this->sync->folderFor($mailbox, EmailFolder::SENT, $s);

                    if ($folder === null || ! $this->keepsSentCopy($mailbox) || $raw === null) {
                        $sent->forceFill(['email_folder_id' => $folder?->id, 'imap_uid' => null])->save();

                        return;
                    }

                    $uid = $s->append($folder->path, $raw, ['\\Seen']);
                    $s->select($folder->path);
                    $uid ??= $s->findByMessageId((string) $sent->message_id_header);
                    $sent->forceFill(['email_folder_id' => $folder->id, 'imap_uid' => $uid])->save();
                    $folder->forceFill(['last_uid' => max((int) $folder->last_uid, (int) $uid) ?: null])->save();
                });
            } catch (Throwable $e) {
                // Sent is sent; the copy in Enviados is a convenience.
                $sent->forceFill(['email_folder_id' => null, 'imap_uid' => null])->save();
                report($e);
            }
        } else {
            $sent->forceFill(['email_folder_id' => $this->sync->folderFor($mailbox, EmailFolder::SENT)?->id, 'imap_uid' => null])->save();
        }

        return $sent;
    }

    /**
     * The draft as an RFC 822 message, with its files.
     */
    public function raw(EmailMessage $draft): string
    {
        $email = (new Email)
            ->from(new Address($draft->mailbox->address, $draft->mailbox->display_name))
            ->subject((string) $draft->subject)
            ->text((string) $draft->text_body);

        foreach (['to' => $draft->to, 'cc' => $draft->cc, 'bcc' => $draft->bcc] as $field => $addresses) {
            foreach (array_filter($addresses ?? [], fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false) as $address) {
                $email->{'add'.ucfirst($field)}($address);
            }
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', trim((string) $draft->message_id_header, '<>'));

        if (filled($draft->in_reply_to)) {
            $headers->addIdHeader('In-Reply-To', trim((string) $draft->in_reply_to, '<>'));
        }

        $references = array_values(array_filter(preg_split('/\s+/', (string) $draft->references) ?: []));

        if ($references !== []) {
            $headers->addIdHeader('References', array_map(fn (string $id) => trim($id, '<>'), $references));
        }

        foreach ($draft->attachments()->whereNotNull('path')->get() as $file) {
            $disk = Storage::disk($file->diskName());

            if ($disk->exists((string) $file->path)) {
                $email->attach((string) $disk->get((string) $file->path), $file->filename, $file->mime_type ?? 'application/octet-stream');
            }
        }

        return $email->toString();
    }

    /**
     * The draft's files after an edit: the kept ones stay, the uploads are
     * copied in, anything else is removed.
     *
     * @param  list<int>  $uploads
     * @param  list<int>  $keep
     */
    private function syncFiles(EmailMessage $draft, User $author, array $uploads, array $keep): void
    {
        $disk = (string) config('mail_ingest.disk');
        $base = "tenants/{$draft->tenant_id}/mail/out/{$draft->id}";

        foreach ($draft->attachments()->get() as $existing) {
            if (! in_array($existing->id, $keep, true)) {
                $this->deleteFile($existing);
                $existing->delete();
            }
        }

        // Files of another email the person may read (forwarding, or a reply that keeps them).
        $visible = EmailMessage::query()->visibleTo($author)->select('id');
        $brought = EmailAttachment::query()->whereIn('id', $keep)->where('email_message_id', '!=', $draft->id)->whereIn('email_message_id', $visible)->whereNotNull('path')->get();

        foreach ($brought as $file) {
            $path = "{$base}/".Str::random(8).'-'.$this->safeName($file->filename);
            Storage::disk($disk)->writeStream($path, Storage::disk($file->diskName())->readStream((string) $file->path));
            EmailAttachment::query()->create(['email_message_id' => $draft->id, 'filename' => $file->filename, 'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes, 'disk' => $disk, 'path' => $path, 'ocr_status' => 'skipped']);
        }

        $files = MessageAttachment::query()->whereIn('id', $uploads)->where('uploaded_by_user_id', $author->id)->whereNull('task_message_id')->ready()->get();

        foreach ($files as $upload) {
            $path = "{$base}/".Str::random(8).'-'.$this->safeName($upload->filename);
            Storage::disk($disk)->writeStream($path, Storage::disk($upload->disk)->readStream($upload->path));
            EmailAttachment::query()->create(['email_message_id' => $draft->id, 'filename' => $upload->filename, 'mime_type' => $upload->mime_type, 'size_bytes' => $upload->size_bytes, 'disk' => $disk, 'path' => $path, 'ocr_status' => 'skipped']);
            // The upload was only the way in: the email keeps its own copy.
            Storage::disk($upload->disk)->delete($upload->path);
            $upload->delete();
        }
    }

    /**
     * Gmail and Microsoft 365 put what goes out by SMTP in Enviados themselves;
     * a second copy would show twice.
     */
    private function keepsSentCopy(Mailbox $mailbox): bool
    {
        return $mailbox->save_sent_copy && preg_match('/gmail|googlemail|office365|outlook\.com/i', (string) $mailbox->smtp_host) !== 1;
    }

    private function safeName(string $filename): string
    {
        $extension = Str::lower(preg_replace('/[^a-z0-9]/i', '', pathinfo($filename, PATHINFO_EXTENSION)) ?? '');

        return (Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'anexo').($extension !== '' ? ".{$extension}" : '');
    }

    private function deleteFile(EmailAttachment $file): void
    {
        // Only the platform's own copies; an inbound email's files follow the retention rules.
        if ($file->path !== null && str_contains((string) $file->path, '/mail/out/')) {
            Storage::disk($file->diskName())->delete((string) $file->path);
        }
    }

    private function requireFolder(Mailbox $mailbox, string $role): EmailFolder
    {
        return $this->session($mailbox, fn (?ImapSession $s) => $this->sync->folderFor($mailbox, $role, $s), optional: true)
            ?? throw new RuntimeException('esta caixa não tem a pasta '.EmailFolder::ROLES[$role].'.');
    }

    private function recount(?EmailFolder $folder): void
    {
        $folder?->forceFill(['unread' => $folder->messages()->whereNull('read_at')->where('status', '!=', 'draft')->count()])->save();
    }

    /**
     * Runs an action on the message's copy on the server, if it has one.
     *
     * @param  callable(ImapSession, int): mixed  $action
     */
    private function onServer(EmailMessage $message, callable $action): void
    {
        $folder = $message->folder;

        if ($folder === null || ! $folder->onServer() || $message->imap_uid === null || blank($message->mailbox->imap_host)) {
            return;
        }

        $this->session($message->mailbox, function (ImapSession $s) use ($folder, $message, $action) {
            $s->select($folder->path);
            $action($s, (int) $message->imap_uid);
        });
    }

    /**
     * @template T
     *
     * @param  callable(ImapSession|null): T  $call
     * @return T
     *
     * @throws RuntimeException
     */
    private function session(Mailbox $mailbox, callable $call, bool $optional = false): mixed
    {
        if (blank($mailbox->imap_host)) {
            return $optional ? $call(null) : throw new RuntimeException('esta caixa não tem IMAP configurado.');
        }

        $session = $this->imap->open($mailbox);

        try {
            return $call($session);
        } finally {
            $session->close();
        }
    }
}
