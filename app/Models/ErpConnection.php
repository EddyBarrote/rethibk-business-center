<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ErpConnectionStatus;
use App\Enums\ErpTransport;
use Database\Factories\ErpConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Connection from a tenant to the Rethink ERP's MCP server (section 5.8).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ErpTransport $transport
 * @property string|null $base_url
 * @property string $auth_type
 * @property array<string, mixed>|null $credentials
 * @property ErpConnectionStatus $status
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property list<array{name: string, title: string|null, description: string|null, read_only: bool}>|null $capabilities
 */
#[Fillable(['name', 'transport', 'base_url', 'auth_type', 'credentials', 'status'])]
#[Hidden(['credentials'])]
class ErpConnection extends Model
{
    /** @use HasFactory<ErpConnectionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The tenant's usable connection (already tenant-scoped; excludes disabled).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function currentTenant(Builder $query): void
    {
        $query->where('status', '!=', ErpConnectionStatus::Disabled)->oldest('id');
    }

    public function decryptedToken(): ?string
    {
        $token = $this->credentials['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function hasToken(): bool
    {
        return $this->decryptedToken() !== null;
    }

    protected function casts(): array
    {
        return [
            'transport' => ErpTransport::class,
            'status' => ErpConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
