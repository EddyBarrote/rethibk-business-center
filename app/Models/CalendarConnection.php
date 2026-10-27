<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\CalendarConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person's own calendar account (docs/DECISOES.md, "Google Calendar por
 * pessoa"), connected with their consent. Tokens are encrypted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $provider
 * @property string|null $account_email
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $platform_calendar_id
 * @property string|null $sync_token
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property-read User $user
 */
#[Fillable(['user_id', 'provider', 'account_email', 'access_token', 'refresh_token', 'token_expires_at', 'platform_calendar_id', 'sync_token', 'last_synced_at', 'last_error'])]
#[Hidden(['access_token', 'refresh_token', 'sync_token'])]
class CalendarConnection extends Model
{
    /** @use HasFactory<CalendarConnectionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
