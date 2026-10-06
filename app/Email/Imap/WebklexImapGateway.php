<?php

namespace App\Email\Imap;

use App\Models\Mailbox;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

final class WebklexImapGateway implements ImapGateway
{
    public function open(Mailbox $mailbox): ImapSession
    {
        if (blank($mailbox->imap_host)) {
            throw new RuntimeException('esta caixa não tem IMAP configurado.');
        }

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

        try {
            $client->connect();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        return new WebklexImapSession($client);
    }
}
