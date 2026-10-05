<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\Role;
use Database\Factories\AccessRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role in the access matrix (docs/DECISOES.md, realinhamento L9). The base
 * keeps the old owner/admin/manager/member level in step, for the few places
 * that still need it (only owners make owners).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $name
 * @property Role $base
 * @property list<string> $permissions
 * @property bool $is_system
 */
#[Fillable(['key', 'name', 'base', 'permissions', 'is_system'])]
class AccessRole extends Model
{
    /** @use HasFactory<AccessRoleFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return [
            'base' => Role::class,
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }
}
