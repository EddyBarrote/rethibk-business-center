<?php

namespace App\Models;

use App\Models\Concerns\DefinesConnector;
use Database\Factories\PlatformConnectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A connector the super admin offers to every tenant. Each tenant decides
 * whether to activate it; activating copies its tools into that tenant's
 * capability catalogue (docs/CAPACIDADES.md).
 */
#[Fillable(['key', 'name', 'description', 'kind', 'url', 'secret', 'http_method', 'input_schema', 'is_mutating', 'is_active'])]
class PlatformConnector extends Model
{
    /** @use HasFactory<PlatformConnectorFactory> */
    use DefinesConnector, HasFactory;

    public function capabilityPrefix(): string
    {
        return 'global.'.$this->key;
    }
}
