<?php

namespace App\Enums;

/**
 * What the triage agent decided an email is (E03).
 */
enum EmailCategory: string
{
    case Lead = 'lead';
    case Tender = 'tender';
    case ClientRfq = 'client_rfq';
    case ClientRequest = 'client_request';
    case SupplierInvoice = 'supplier_invoice';
    case SupplierQuote = 'supplier_quote';
    case BankStatement = 'bank_statement';
    case JobApplication = 'job_application';
    case Internal = 'internal';
    case Newsletter = 'newsletter';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Oportunidade comercial',
            self::Tender => 'Concurso',
            self::ClientRfq => 'Pedido de cotação de cliente',
            self::ClientRequest => 'Pedido de cliente',
            self::SupplierInvoice => 'Factura de fornecedor',
            self::SupplierQuote => 'Cotação de fornecedor',
            self::BankStatement => 'Extracto bancário',
            self::JobApplication => 'Candidatura',
            self::Internal => 'Interno',
            self::Newsletter => 'Newsletter',
            self::Spam => 'Spam',
            self::Other => 'Outro',
        };
    }

    /**
     * The agent role that handles this kind of email after triage.
     */
    public function handlerRole(): ?string
    {
        return match ($this) {
            self::SupplierInvoice, self::BankStatement => 'finance',
            self::SupplierQuote => 'procurement',
            self::JobApplication => 'hr',
            self::ClientRequest, self::ClientRfq => 'client_manager',
            default => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
