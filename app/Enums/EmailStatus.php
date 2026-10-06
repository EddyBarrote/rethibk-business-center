<?php

namespace App\Enums;

enum EmailStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Draft = 'draft';
    case Queued = 'queued';
    case Sent = 'sent';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recebido',
            self::Processing => 'Em triagem',
            self::Processed => 'Triado',
            self::Failed => 'Falhou',
            self::Draft => 'Rascunho',
            self::Queued => 'Em envio',
            self::Sent => 'Enviado',
            self::Bounced => 'Devolvido',
        };
    }
}
