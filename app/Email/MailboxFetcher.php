<?php

namespace App\Email;

use App\Models\Mailbox;

/**
 * Reads new raw messages from a mailbox. IMAP in production; tests bind a fake.
 */
interface MailboxFetcher
{
    /**
     * Messages with a UID above $afterUid, oldest first.
     *
     * @return iterable<int, array{uid: int, raw: string}>
     */
    public function fetch(Mailbox $mailbox, ?int $afterUid, int $limit): iterable;
}
