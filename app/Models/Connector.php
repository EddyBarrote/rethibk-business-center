<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Concerns\DefinesConnector;
use Database\Factories\ConnectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A connector a tenant's admins added: a remote MCP server or an HTTP action
 * whose tools become capabilities private to that tenant.
 *
 * @property int $tenant_id
 * @property int|null $created_by_user_id
 */
#[Fillable(['key', 'name', 'description', 'kind', 'url', 'secret', 'http_method', 'input_schema', 'is_mutating', 'is_active'])]
class Connector extends Model
{
    /** @use HasFactory<ConnectorFactory> */
    use BelongsToTenant, DefinesConnector, HasFactory;

    public function capabilityPrefix(): string
    {
        return 'conn.'.$this->key;
    }
}
