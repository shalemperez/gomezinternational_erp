<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int $line_number
 * @property int $product_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percent
 * @property string $line_subtotal
 * @property string $discount_amount
 * @property string $taxable_base
 * @property string $itbis_rate
 * @property string $itbis_amount
 * @property array<int, array{code: string, name: string, amount: string}> $additional_taxes
 * @property string $additional_tax_total
 * @property string $line_total
 * @property string $dispatched_quantity
 * @property string $returned_quantity
 * @property string $credited_quantity
 * @property string $dispatched_cost
 */
class InvoiceLine extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'invoice_id', 'line_number', 'product_id', 'description', 'quantity', 'unit_price', 'discount_percent', 'line_subtotal', 'discount_amount',
        'taxable_base', 'itbis_rate', 'itbis_amount', 'additional_taxes', 'additional_tax_total', 'line_total', 'dispatched_quantity',
        'returned_quantity', 'credited_quantity', 'dispatched_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:4', 'dispatched_quantity' => 'decimal:4',
            'returned_quantity' => 'decimal:4', 'credited_quantity' => 'decimal:4',
            'line_subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'taxable_base' => 'decimal:2', 'itbis_rate' => 'decimal:2',
            'itbis_amount' => 'decimal:2', 'additional_taxes' => 'array', 'additional_tax_total' => 'decimal:2', 'line_total' => 'decimal:2',
            'dispatched_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return MorphMany<StockReservation, $this> */
    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reservable');
    }

    public function remainingToDispatch(): string
    {
        return Decimal::qty(Decimal::of($this->quantity)->minus(Decimal::of($this->dispatched_quantity))->minus(Decimal::of($this->credited_quantity)));
    }
}
