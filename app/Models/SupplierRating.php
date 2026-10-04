<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\SupplierRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A supplier evaluation after a delivery (E06), 1 to 5 per criterion.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $supplier_ref
 * @property string $supplier_name
 * @property string|null $po_ref
 * @property int $on_time
 * @property int $quality
 * @property int $price
 * @property string|null $notes
 * @property string $rated_by_type
 * @property int|null $rated_by_id
 * @property Carbon $created_at
 */
#[Fillable(['supplier_ref', 'supplier_name', 'po_ref', 'on_time', 'quality', 'price', 'notes', 'rated_by_type', 'rated_by_id'])]
class SupplierRating extends Model
{
    /** @use HasFactory<SupplierRatingFactory> */
    use BelongsToTenant, HasFactory;
}
