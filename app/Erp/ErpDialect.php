<?php

namespace App\Erp;

/**
 * Which tool vocabulary the tenant's ERP speaks. The fake ERP implements the
 * contract (docs/ERP-MCP-CONTRACT.md, "crm.get_account"); the live Rethink ERP
 * is Perfex-based and exposes its own names ("get_customer").
 */
enum ErpDialect: string
{
    case Contract = 'contract';
    case Perfex = 'perfex';

    /**
     * @param  list<string>  $toolNames
     */
    public static function fromToolNames(array $toolNames): self
    {
        return in_array('list_customers', $toolNames, true) && ! in_array('crm.get_account', $toolNames, true)
            ? self::Perfex
            : self::Contract;
    }
}
