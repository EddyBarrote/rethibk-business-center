<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client or supplier contract, watched for expiry and renewal (E06, E08).
 *
 * @property int $id
 * @property int $tenant_id
 * @property PartyType $party_type
 * @property string|null $party_ref
 * @property string $party_name
 * @property string|null $party_domain
 * @property string $title
 * @property string|null $reference
 * @property float|null $value
 * @property string $currency
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $notice_days
 * @property bool $auto_renews
 * @property int|null $sla_response_hours
 * @property int|null $owner_user_id
 * @property ContractStatus $status
 * @property string|null $notes
 * @property Carbon|null $alerted_at
 * @property Carbon $created_at
 */
#[Fillable(['party_type', 'party_ref', 'party_name', 'party_domain', 'title', 'reference', 'value', 'currency', 'starts_at', 'ends_at', 'notice_days', 'auto_renews', 'sla_response_hours', 'owner_user_id', 'status', 'notes', 'alerted_at'])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function casts(): array
    {
        return [
            'party_type' => PartyType::class,
            'value' => 'float',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'auto_renews' => 'boolean',
            'status' => ContractStatus::class,
            'alerted_at' => 'datetime',
        ];
    }
}
