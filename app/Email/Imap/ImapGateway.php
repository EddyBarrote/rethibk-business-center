<?php

namespace App\Email\Imap;

use App\Models\Mailbox;

/**
 * Opens an IMAP session with a mailbox's own encrypted credentials. Webklex
 * over a plain socket in production; tests bind an in-memory server.
 */
interface ImapGateway
{
    public function open(Mailbox $mailbox): ImapSession;
}
