<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $proforma_id
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
 * @property string $invoiced_quantity
 */
class ProformaLine extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'proforma_id', 'line_number', 'product_id', 'description', 'quantity', 'unit_price', 'discount_percent', 'line_subtotal',
        'discount_amount', 'taxable_base', 'itbis_rate', 'itbis_amount', 'additional_taxes', 'additional_tax_total', 'line_total', 'invoiced_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:4', 'invoiced_quantity' => 'decimal:4',
            'line_subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'taxable_base' => 'decimal:2', 'itbis_rate' => 'decimal:2',
            'itbis_amount' => 'decimal:2', 'additional_taxes' => 'array', 'additional_tax_total' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Proforma, $this> */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
