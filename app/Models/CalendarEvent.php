<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event kept in step with a person's Google Calendar: "in" is theirs,
 * read from their primary calendar and shown only to them; "out" is a
 * reminder or deadline of the platform, written to the platform's own
 * calendar in their account.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $calendar_connection_id
 * @property int $user_id
 * @property string $direction
 * @property string $external_id
 * @property string|null $source_key
 * @property string|null $source_hash
 * @property string|null $title
 * @property string|null $location
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $all_day
 * @property string|null $html_link
 */
#[Fillable(['calendar_connection_id', 'user_id', 'direction', 'external_id', 'source_key', 'source_hash', 'title', 'location', 'starts_at', 'ends_at', 'all_day', 'html_link'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use BelongsToTenant, HasFactory;

    public const IN = 'in';

    public const OUT = 'out';

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }
}
