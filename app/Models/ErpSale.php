<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\ErpSaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One ERP invoice, quote or catalogue item, copied with its lines so agents
 * can search past sales by what was sold (App\Sales\SalesHistory).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property string $erp_id
 * @property string|null $number
 * @property string|null $customer
 * @property string|null $customer_id
 * @property Carbon|null $date
 * @property string|null $status
 * @property string|null $currency
 * @property string|null $total
 * @property list<array<string, mixed>>|null $lines
 * @property string|null $note
 * @property string|null $search_text
 * @property string $signature
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['kind', 'erp_id', 'number', 'customer', 'customer_id', 'date', 'status', 'currency', 'total', 'lines', 'note', 'search_text', 'signature'])]
class ErpSale extends Model
{
    /** @use HasFactory<ErpSaleFactory> */
    use BelongsToTenant, HasFactory;

    public const INVOICE = 'invoice';

    public const ESTIMATE = 'estimate';

    public const ITEM = 'item';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'lines' => 'array',
        ];
    }
}
