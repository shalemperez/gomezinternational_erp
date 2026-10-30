<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dispatch_id
 * @property int $invoice_line_id
 * @property int $product_id
 * @property int|null $lot_id
 * @property string $quantity
 * @property string $unit_cost
 * @property string $total_cost
 * @property Lot|null $lot
 */
class DispatchLine extends Model
{
    use LogsModelActivity;

    protected $fillable = ['dispatch_id', 'invoice_line_id', 'product_id', 'lot_id', 'quantity', 'unit_cost', 'total_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2'];
    }

    /** @return BelongsTo<Dispatch, $this> */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
