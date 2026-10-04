<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\PurchaseRequestStatus;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A purchase requisition (E06): it starts the RFQ, comparison and draft PO flow.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $requested_by_user_id
 * @property int|null $department_id
 * @property string|null $project_ref
 * @property string $title
 * @property string|null $description
 * @property list<array<string, mixed>> $items
 * @property Carbon|null $needed_by
 * @property float|null $budget
 * @property PurchaseRequestStatus $status
 * @property string|null $erp_rfq_id
 * @property string|null $erp_po_id
 * @property string|null $notes
 * @property Carbon $created_at
 */
#[Fillable(['requested_by_user_id', 'department_id', 'project_ref', 'title', 'description', 'items', 'needed_by', 'budget', 'status', 'erp_rfq_id', 'erp_po_id', 'notes'])]
class PurchaseRequest extends Model
{
    /** @use HasFactory<PurchaseRequestFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'needed_by' => 'date',
            'budget' => 'float',
            'status' => PurchaseRequestStatus::class,
        ];
    }
}
