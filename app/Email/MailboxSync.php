<?php

namespace App\Email;

use App\Email\Imap\ImapGateway;
use App\Email\Imap\ImapSession;
use App\Enums\EmailStatus;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Keeps a mailbox's folders in step with its IMAP server (docs/DECISOES.md,
 * "Email: um cliente de email na plataforma"): the folder list, the new
 * messages of each folder, what was read, flagged, moved or deleted in
 * another client. Only the folder the agents watch (INBOX by default) hands
 * new email to them; the others are only filed.
 */
final class MailboxSync
{
    /** Flags read again on each sync, from the newest message back. */
    private const FLAG_WINDOW = 300;

    public function __construct(
        private readonly ImapGateway $imap,
        private readonly InboundEmailIngestor $ingestor,
    ) {}

    /**
     * The folders of a mailbox, from the server when it has IMAP and the
     * list is old, the standard ones otherwise.
     *
     * @return Collection<int, EmailFolder>
     */
    public function folders(Mailbox $mailbox, bool $refresh = false): Collection
    {
        if (blank($mailbox->imap_host)) {
            return $this->localFolders($mailbox);
        }

        $known = EmailFolder::query()->where('mailbox_id', $mailbox->id)->where('local', false)->get();

        if ($known->isNotEmpty() && ! $refresh) {
            return $known;
        }

        $session = $this->imap->open($mailbox);

        try {
            return $this->listFolders($mailbox, $session);
        } finally {
            $session->close();
        }
    }

    /**
     * One pass over a mailbox: the folder list every few minutes, then each
     * folder (or only $only). The watched folder's new messages go to the
     * agents, at most $batch per pass.
     *
     * @throws RuntimeException when the server cannot be reached
     */
    public function run(Mailbox $mailbox, ?EmailFolder $only = null, ?int $batch = null): void
    {
        if (blank($mailbox->imap_host)) {
            return;
        }

        $session = $this->imap->open($mailbox);

        try {
            $stale = $mailbox->folders_synced_at === null || $mailbox->folders_synced_at->lt(now()->subMinutes(10));
            $folders = $only !== null ? collect([$only]) : ($stale ? $this->listFolders($mailbox, $session) : $this->serverFolders($mailbox));

            foreach ($folders as $folder) {
                $this->syncFolder($mailbox, $session, $folder, $batch ?? (int) config('mail_ingest.batch_size'));
            }
        } finally {
            $session->close();
        }
    }

    /**
     * Messages older than the oldest one read, for "Carregar mais antigos".
     */
    public function older(Mailbox $mailbox, EmailFolder $folder, int $count = 50): int
    {
        if ($folder->local || $folder->first_uid === null) {
            return 0;
        }

        $session = $this->imap->open($mailbox);

        try {
            $session->select($folder->path);
            $older = array_values(array_filter($session->uids(), fn (int $uid) => $uid < $folder->first_uid));
            $take = array_slice($older, -$count);
            $this->file($mailbox, $session, $folder, $take, false);

            $folder->forceFill(['first_uid' => $take === [] ? $folder->first_uid : min($take), 'has_older' => count($older) > count($take)])->save();

            return count($take);
        } finally {
            $session->close();
        }
    }

    /**
     * The folder a role lives in, created on the server when it has none
     * (an Archive the first time someone archives, for example).
     */
    public function folderFor(Mailbox $mailbox, string $role, ?ImapSession $session = null): ?EmailFolder
    {
        $folders = blank($mailbox->imap_host) ? $this->localFolders($mailbox) : $this->serverFolders($mailbox);
        $folder = $folders->firstWhere('role', $role);

        if ($folder !== null || blank($mailbox->imap_host) || ! in_array($role, [EmailFolder::ARCHIVE, EmailFolder::TRASH, EmailFolder::SENT, EmailFolder::DRAFTS], true)) {
            return $folder;
        }

        $inbox = $folders->firstWhere('role', EmailFolder::INBOX);
        // Servers like Dovecot keep folders under INBOX (INBOX.Archive); follow what the others do.
        $prefix = $folders->contains(fn (EmailFolder $f) => $f->delimiter !== null && Str::startsWith($f->path, 'INBOX'.$f->delimiter)) && $inbox?->delimiter !== null
            ? 'INBOX'.$inbox->delimiter
            : '';
        $path = $prefix.match ($role) {
            EmailFolder::ARCHIVE => 'Archive',
            EmailFolder::TRASH => 'Trash',
            EmailFolder::SENT => 'Sent',
            default => 'Drafts',
        };

        $own = $session === null;
        $session ??= $this->imap->open($mailbox);

        try {
            $session->create($path);
        } finally {
            if ($own) {
                $session->close();
            }
        }

        return EmailFolder::query()->create([
            'mailbox_id' => $mailbox->id, 'path' => $path, 'name' => $path, 'role' => $role, 'delimiter' => $inbox?->delimiter,
        ]);
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    private function listFolders(Mailbox $mailbox, ImapSession $session): Collection
    {
        $listed = collect($session->folders())
            // Gmail's All Mail repeats every message of the other folders.
            ->reject(fn (array $f) => in_array('\\All', $f['attributes'], true));
        $roles = $this->roles($listed->all());
        $kept = [];

        foreach ($listed as $info) {
            $folder = EmailFolder::query()->firstOrNew(['mailbox_id' => $mailbox->id, 'path' => $info['path']]);
            $folder->fill([
                'name' => $info['name'],
                'role' => array_search($info['path'], $roles, true) ?: null,
                'delimiter' => $info['delimiter'],
                'local' => false,
            ]);

            // The INBOX of a mailbox that was already being read continues where the agents stopped.
            if (! $folder->exists && $folder->role === EmailFolder::INBOX && $mailbox->imap_last_uid !== null && $info['path'] === ($mailbox->imap_folder ?: 'INBOX')) {
                $folder->last_uid = $mailbox->imap_last_uid;
            }

            $folder->save();
            $kept[] = $folder->id;

            if ($folder->role === EmailFolder::INBOX) {
                $this->adoptInbox($mailbox, $folder);
            }
        }

        // Folders deleted on the server: their messages leave the views, the rows stay for what points to them.
        $gone = EmailFolder::query()->where('mailbox_id', $mailbox->id)->where('local', false)->whereNotIn('id', $kept)->pluck('id');
        EmailMessage::query()->whereIn('email_folder_id', $gone)->update(['removed_at' => now(), 'email_folder_id' => null, 'imap_uid' => null]);
        EmailFolder::query()->whereIn('id', $gone)->delete();

        $mailbox->forceFill(['folders_synced_at' => now()])->save();

        return $this->serverFolders($mailbox);
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    private function serverFolders(Mailbox $mailbox): Collection
    {
        return EmailFolder::query()->where('mailbox_id', $mailbox->id)->where('local', false)->get();
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    private function localFolders(Mailbox $mailbox): Collection
    {
        $folders = EmailFolder::query()->where('mailbox_id', $mailbox->id)->get();

        foreach (EmailFolder::ROLES as $role => $name) {
            if (! $folders->contains('role', $role)) {
                $folders->push(EmailFolder::query()->create(['mailbox_id' => $mailbox->id, 'path' => $role, 'name' => $name, 'role' => $role, 'local' => true]));
            }
        }

        return $folders;
    }

    /**
     * Email the agents read before folders existed came from the INBOX: it
     * belongs there, with the UID it was read with.
     */
    private function adoptInbox(Mailbox $mailbox, EmailFolder $inbox): void
    {
        EmailMessage::query()->where('mailbox_id', $mailbox->id)->whereNull('email_folder_id')->whereNull('removed_at')
            ->where('direction', 'inbound')->where('provider_message_id', 'like', 'imap:%')
            ->each(function (EmailMessage $message) use ($inbox): void {
                $message->forceFill(['email_folder_id' => $inbox->id, 'imap_uid' => (int) Str::after((string) $message->getAttribute('provider_message_id'), 'imap:')])->save();
            });

        if ($inbox->first_uid === null && ($oldest = EmailMessage::query()->where('email_folder_id', $inbox->id)->min('imap_uid')) !== null) {
            $inbox->forceFill(['first_uid' => (int) $oldest])->save();
        }
    }

    /**
     * Which folder plays which role: the server's SPECIAL-USE attributes
     * first, then the usual names in English and Portuguese.
     *
     * @param  array<int, array{path: string, name: string, delimiter: string|null, attributes: list<string>}>  $folders
     * @return array<string, string> path by role
     */
    private function roles(array $folders): array
    {
        $attributes = ['\\Sent' => EmailFolder::SENT, '\\Drafts' => EmailFolder::DRAFTS, '\\Trash' => EmailFolder::TRASH, '\\Junk' => EmailFolder::JUNK, '\\Archive' => EmailFolder::ARCHIVE];
        $names = [
            EmailFolder::SENT => ['sent', 'sent items', 'sent messages', 'sent mail', 'enviados', 'itens enviados', 'mensagens enviadas', 'enviadas'],
            EmailFolder::DRAFTS => ['drafts', 'draft', 'rascunhos', 'rascunho'],
            EmailFolder::TRASH => ['trash', 'deleted items', 'deleted messages', 'bin', 'lixo', 'lixeira', 'reciclagem', 'itens eliminados', 'itens excluídos'],
            EmailFolder::JUNK => ['junk', 'spam', 'junk e-mail', 'junk email', 'lixo electrónico', 'lixo eletrônico', 'correio não solicitado'],
            EmailFolder::ARCHIVE => ['archive', 'archives', 'arquivo', 'arquivos', 'arquivados'],
        ];
        $roles = [];

        foreach ($folders as $folder) {
            if (strcasecmp($folder['path'], 'INBOX') === 0) {
                $roles[EmailFolder::INBOX] = $folder['path'];
            }

            foreach ($folder['attributes'] as $attribute) {
                foreach ($attributes as $flag => $role) {
                    if (strcasecmp($attribute, $flag) === 0) {
                        $roles[$role] ??= $folder['path'];
                    }
                }
            }
        }

        foreach ($folders as $folder) {
            foreach ($names as $role => $candidates) {
                if (! isset($roles[$role]) && in_array(Str::lower($folder['name']), $candidates, true)) {
                    $roles[$role] = $folder['path'];
                }
            }
        }

        return $roles;
    }

    private function syncFolder(Mailbox $mailbox, ImapSession $session, EmailFolder $folder, int $batch): void
    {
        $status = $session->select($folder->path);

        // A new UIDVALIDITY means the server renumbered the folder: start it again.
        if ($folder->uidvalidity !== null && $status['uidvalidity'] !== null && $folder->uidvalidity !== $status['uidvalidity']) {
            EmailMessage::query()->where('email_folder_id', $folder->id)->update(['imap_uid' => null]);
            $folder->forceFill(['last_uid' => null, 'first_uid' => null])->save();
        }

        $uids = $session->uids();
        $present = array_flip($uids);

        // Gone from this folder in another client: out of the view (or back with its new UID when filed again below).
        EmailMessage::query()->where('email_folder_id', $folder->id)->whereNotNull('imap_uid')->get(['id', 'imap_uid'])
            ->reject(fn (EmailMessage $m) => isset($present[(int) $m->getAttribute('imap_uid')]))
            ->each(fn (EmailMessage $m) => $m->forceFill(['email_folder_id' => null, 'imap_uid' => null, 'removed_at' => now()])->save());

        $watched = $folder->role === EmailFolder::INBOX && $folder->path === ($mailbox->imap_folder ?: 'INBOX');
        $new = $folder->last_uid === null
            ? array_slice($uids, -$batch)
            : array_slice(array_values(array_filter($uids, fn (int $uid) => $uid > $folder->last_uid)), 0, $batch);

        $this->file($mailbox, $session, $folder, $new, $watched);

        $this->syncFlags($session, $folder);

        $filed = EmailMessage::query()->where('email_folder_id', $folder->id)->whereNull('removed_at');
        $first = $folder->first_uid ?? ($new === [] ? null : min($new));
        $folder->forceFill([
            'uidvalidity' => $status['uidvalidity'],
            'last_uid' => max([(int) $folder->last_uid, ...$new]) ?: null,
            'first_uid' => $first,
            'has_older' => $first !== null && $uids !== [] && $uids[0] < $first,
            'total' => count($uids),
            'unread' => (clone $filed)->whereNull('read_at')->count(),
            'synced_at' => now(),
        ])->save();

        if ($watched) {
            $mailbox->forceFill(['imap_last_uid' => $folder->last_uid])->save();
        }
    }

    /**
     * @param  list<int>  $uids
     */
    private function file(Mailbox $mailbox, ImapSession $session, EmailFolder $folder, array $uids, bool $process): void
    {
        foreach (array_chunk($uids, 20) as $chunk) {
            $flags = $session->flags($chunk);

            foreach ($session->raw($chunk) as $uid => $raw) {
                try {
                    $this->ingestor->ingest($mailbox, $raw, 'imap:'.$uid, $folder, $uid, $process, $flags[$uid] ?? []);
                } catch (Throwable $e) {
                    // One unreadable message never stops the folder.
                    report($e);
                }
            }
        }
    }

    /**
     * Read, unread and flagged as the server has them, for the newest messages.
     */
    private function syncFlags(ImapSession $session, EmailFolder $folder): void
    {
        $messages = EmailMessage::query()->where('email_folder_id', $folder->id)->whereNotNull('imap_uid')
            ->orderByDesc('imap_uid')->limit(self::FLAG_WINDOW)->get(['id', 'imap_uid', 'read_at', 'is_flagged', 'status']);

        if ($messages->isEmpty()) {
            return;
        }

        $flags = $session->flags($messages->map(fn (EmailMessage $m) => (int) $m->getAttribute('imap_uid'))->all());

        foreach ($messages as $message) {
            $list = $flags[(int) $message->getAttribute('imap_uid')] ?? null;

            if ($list === null) {
                continue;
            }

            $seen = in_array('\\Seen', $list, true);
            $flagged = in_array('\\Flagged', $list, true);
            $changes = [];

            if ($seen !== ($message->read_at !== null)) {
                $changes['read_at'] = $seen ? now() : null;
            }

            if ($flagged !== (bool) $message->getAttribute('is_flagged')) {
                $changes['is_flagged'] = $flagged;
            }

            if ($changes !== [] && $message->status !== EmailStatus::Draft) {
                $message->forceFill($changes)->save();
            }
        }
    }
}
