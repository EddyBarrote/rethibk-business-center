<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
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
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'job_title', 'email', 'password', 'department_id', 'reports_to_user_id', 'reports_to_agent_id', 'role', 'is_active'])]
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

    public function canManageTenant(): bool
    {
        return $this->role->canManageTenant();
    }

    /**
     * Owners, admins and department managers see their area's work.
     */
    public function isManager(): bool
    {
        return $this->role->canManageTenant() || $this->role === Role::Manager;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }
}
