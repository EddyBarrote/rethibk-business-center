<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AiProviderKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company's key for an AI provider (docs/DECISOES.md, "Chaves dos
 * provedores de IA na plataforma"). Encrypted at rest and never sent to a
 * page: people see only its last four characters.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $provider
 * @property string $key
 * @property string $last_four
 * @property int|null $set_by_user_id
 * @property Carbon $updated_at
 */
#[Fillable(['provider', 'key', 'last_four', 'set_by_user_id'])]
#[Hidden(['key'])]
class AiProviderKey extends Model
{
    /** @use HasFactory<AiProviderKeyFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    protected function casts(): array
    {
        return ['key' => 'encrypted'];
    }
}
