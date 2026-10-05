<?php

namespace App\Models;

use App\Access\AccessRoles;
use App\Concerns\BelongsToTenant;
use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $job_title
 * @property int|null $department_id
 * @property int|null $reports_to_user_id
 * @property int|null $reports_to_agent_id
 * @property Role $role
 * @property int|null $access_role_id
 * @property array<string, bool>|null $permission_overrides
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'job_title', 'email', 'password', 'department_id', 'reports_to_user_id', 'reports_to_agent_id', 'role', 'access_role_id', 'permission_overrides', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The person this one reports to in the org chart.
     *
     * @return BelongsTo<User, $this>
     */
    public function reportsToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_user_id');
    }

    /**
     * An agent can be a person's manager too (docs/DECISOES.md, realinhamento L3).
     *
     * @return BelongsTo<Agent, $this>
     */
    public function reportsToAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'reports_to_agent_id');
    }

    /**
     * @return BelongsTo<AccessRole, $this>
     */
    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class);
    }

    /**
     * The access matrix (realinhamento L9): the person's own exceptions first,
     * then their role; without a role row, the defaults of their base level.
     */
    public function hasPermission(Permission $permission): bool
    {
        // A model made in memory may not have these columns yet.
        $overrides = array_key_exists('permission_overrides', $this->attributes) ? $this->permission_overrides : null;
        $override = $overrides[$permission->value] ?? null;

        if (is_bool($override)) {
            return $override;
        }

        return in_array($permission->value, $this->rolePermissions(), true);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter(array_map(fn (Permission $p) => $this->hasPermission($p) ? $p->value : null, Permission::cases())));
    }

    /**
     * @return list<string>
     */
    private function rolePermissions(): array
    {
        $roleId = $this->attributes['access_role_id'] ?? null;

        if ($roleId === null) {
            return AccessRoles::defaultsFor($this->role);
        }

        // One query per request, not per check; never a lazy load in a list.
        $role = $this->relationLoaded('accessRole') ? $this->accessRole : AccessRole::query()->find($roleId);
        $this->setRelation('accessRole', $role);

        return $role->permissions ?? AccessRoles::defaultsFor($this->role);
    }

    /**
     * Administers the company: people, roles, agents, catalogue and settings.
     */
    public function canManageTenant(): bool
    {
        return $this->hasPermission(Permission::ManageCompany);
    }

    /**
     * Leads work: creates tasks and projects and sees their area's work.
     */
    public function isManager(): bool
    {
        return $this->hasPermission(Permission::ManageWork) || $this->canManageTenant();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'permission_overrides' => 'array',
        ];
    }
}
