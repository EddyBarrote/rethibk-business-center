<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Admin => 'Administrador',
            self::Manager => 'Gestor',
            self::Member => 'Membro',
        };
    }

    /**
     * Owners and admins manage users, departments and tenant settings.
     */
    public function canManageTenant(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
