<?php

namespace App\Enums;

enum PurchaseRequestStatus: string
{
    case Submitted = 'submitted';
    case Rfq = 'rfq';
    case Quoting = 'quoting';
    case Compared = 'compared';
    case PoDraft = 'po_draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submetida',
            self::Rfq => 'Pedido de cotação',
            self::Quoting => 'A receber cotações',
            self::Compared => 'Mapa comparativo',
            self::PoDraft => 'Rascunho de encomenda',
            self::Ordered => 'Encomendada',
            self::Received => 'Recebida',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
