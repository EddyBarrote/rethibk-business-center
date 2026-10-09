<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * The part of the business an approval touches, read from its action type
 * ("erp.invoices.issue" is Finanças). The approvals page filters and counts by it.
 */
enum ApprovalArea: string
{
    case Communication = 'comunicacao';
    case Finance = 'financas';
    case Sales = 'comercial';
    case Procurement = 'compras';
    case People = 'pessoas';
    case Projects = 'projectos';
    case Contracts = 'contratos';
    case Platform = 'plataforma';
    case Other = 'outras';

    public function label(): string
    {
        return match ($this) {
            self::Communication => 'Comunicação',
            self::Finance => 'Finanças',
            self::Sales => 'Comercial',
            self::Procurement => 'Compras',
            self::People => 'Pessoas',
            self::Projects => 'Projectos',
            self::Contracts => 'Contratos',
            self::Platform => 'Agentes e orçamento',
            self::Other => 'Outras',
        };
    }

    /**
     * The action types that belong here, by how they start.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Communication => ['comms.', 'email.'],
            self::Finance => ['erp.expenses.', 'erp.invoices.', 'erp.payments.', 'erp.credit_notes.', 'erp.finance.', 'payments.'],
            self::Sales => ['erp.leads.', 'erp.crm.', 'erp.customers.', 'erp.proposals.', 'erp.estimates.'],
            self::Procurement => ['erp.procurement.', 'erp.purchase', 'erp.vendors.'],
            self::People => ['erp.hr.', 'hr.'],
            self::Projects => ['erp.projects.', 'erp.tasks.', 'tasks.'],
            self::Contracts => ['erp.contracts.', 'contracts.'],
            self::Platform => ['agents.', 'budget.'],
            self::Other => [],
        };
    }

    public static function of(string $actionType): self
    {
        foreach (self::cases() as $area) {
            foreach ($area->prefixes() as $prefix) {
                if (str_starts_with($actionType, $prefix)) {
                    return $area;
                }
            }
        }

        return self::Other;
    }

    /**
     * Keeps the approvals of this area; "Outras" is whatever no other area claims.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function scope(Builder $query): void
    {
        $like = fn (string $prefix) => str_replace(['%', '_'], ['\%', '\_'], $prefix).'%';

        if ($this === self::Other) {
            foreach (self::cases() as $area) {
                foreach ($area->prefixes() as $prefix) {
                    $query->where('action_type', 'not like', $like($prefix));
                }
            }

            return;
        }

        $query->where(function (Builder $q) use ($like) {
            foreach ($this->prefixes() as $prefix) {
                $q->orWhere('action_type', 'like', $like($prefix));
            }
        });
    }
}
