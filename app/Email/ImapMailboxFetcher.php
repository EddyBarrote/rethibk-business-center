<?php

namespace App\Email;

use App\Models\Mailbox;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * IMAP over a plain socket (no ext-imap needed), with the mailbox's own
 * encrypted credentials. Messages are left unread on the server.
 */
final class ImapMailboxFetcher implements MailboxFetcher
{
    public function fetch(Mailbox $mailbox, ?int $afterUid, int $limit): iterable
    {
        $client = (new ClientManager)->make([
            'host' => $mailbox->imap_host,
            'port' => $mailbox->imap_port ?: 993,
            'encryption' => match ($mailbox->imap_encryption) {
                'none' => false,
                'tls' => 'starttls',
                default => 'ssl',
            },
            'validate_cert' => true,
            'username' => $mailbox->imap_username ?: $mailbox->address,
            'password' => $mailbox->imap_password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);

        $client->connect();

        try {
            $folder = $client->getFolderByPath($mailbox->imap_folder ?: 'INBOX');

            if ($folder === null) {
                return;
            }

            $query = $folder->query()->all()->leaveUnread()->setFetchOrderAsc()->limit($limit);

            // First run: start from the newest messages rather than the whole history.
            $messages = $afterUid === null
                ? $folder->query()->all()->leaveUnread()->setFetchOrderDesc()->limit($limit)->get()->reverse()
                : $query->getByUidGreater($afterUid);

            /** @var Message $message */
            foreach ($messages as $message) {
                yield ['uid' => (int) $message->getUid(), 'raw' => $message->getHeader()?->raw.$message->getRawBody()];
            }
        } finally {
            $client->disconnect();
        }
    }
}
