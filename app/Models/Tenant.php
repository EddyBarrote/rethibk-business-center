<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Tenancy\TenantManager;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $domain
 * @property TenantStatus $status
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $trial_ends_at
 */
#[Fillable(['name', 'slug', 'domain', 'status', 'settings', 'trial_ends_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public static function current(): ?self
    {
        return app(TenantManager::class)->current();
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', TenantStatus::Active);
    }

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
            'status' => TenantStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }
}
