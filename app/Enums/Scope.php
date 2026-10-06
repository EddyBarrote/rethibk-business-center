<?php

namespace App\Enums;

/**
 * Who owns a capability or a skill (docs/CAPACIDADES.md).
 */
enum Scope: string
{
    /** Created by the super admin, offered to every tenant. */
    case Global = 'global';

    /** Created by the tenant's own admins, private to it. */
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::Tenant => 'Da empresa',
        };
    }
}
