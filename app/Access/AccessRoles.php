<?php

namespace App\Access;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\User;

/**
 * The default roles of the access matrix and the permissions each base level
 * has when a person has no role row yet (docs/DECISOES.md, realinhamento L9).
 */
final class AccessRoles
{
    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $base): array
    {
        $all = array_map(fn (Permission $p) => $p->value, Permission::cases());

        return match ($base) {
            Role::Owner => $all,
            Role::Admin => array_values(array_diff($all, [Permission::RequestConversationReports->value])),
            Role::Manager => [Permission::ManageWork->value, Permission::GrantAgentAccess->value],
            Role::Member => [],
        };
    }

    /**
     * The four system roles, created once per tenant.
     */
    public function ensure(): void
    {
        foreach ([
            'ceo' => ['CEO', Role::Owner],
            'admin' => ['Administrador', Role::Admin],
            'chefia' => ['Chefia', Role::Manager],
            'funcionario' => ['Funcionário', Role::Member],
        ] as $key => [$name, $base]) {
            AccessRole::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'base' => $base,
                'permissions' => self::defaultsFor($base),
                'is_system' => true,
            ]);
        }

        // People from before the matrix get the system role of their level.
        foreach (AccessRole::query()->where('is_system', true)->get() as $role) {
            User::query()->whereNull('access_role_id')->where('role', $role->base)->update(['access_role_id' => $role->id]);
        }
    }

    public function forBase(Role $base): AccessRole
    {
        $this->ensure();

        return AccessRole::query()->where('is_system', true)->where('base', $base)->firstOrFail();
    }

    /**
     * The base level a custom role keeps the rest of the platform in step with.
     *
     * @param  list<string>  $permissions
     */
    public static function baseFor(array $permissions): Role
    {
        return match (true) {
            in_array(Permission::ManageCompany->value, $permissions, true) => Role::Admin,
            in_array(Permission::ManageWork->value, $permissions, true) => Role::Manager,
            default => Role::Member,
        };
    }
}
