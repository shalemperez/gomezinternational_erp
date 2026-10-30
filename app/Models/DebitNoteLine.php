<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $debit_note_id
 * @property int|null $invoice_line_id
 * @property int $line_number
 * @property int $product_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percent
 * @property string $line_total
 */
class DebitNoteLine extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'debit_note_id', 'invoice_line_id', 'line_number', 'product_id', 'description', 'quantity', 'unit_price', 'discount_percent',
        'line_subtotal', 'discount_amount', 'taxable_base', 'itbis_rate', 'itbis_amount', 'additional_taxes', 'additional_tax_total', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:4',
            'line_subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'taxable_base' => 'decimal:2', 'itbis_rate' => 'decimal:2',
            'itbis_amount' => 'decimal:2', 'additional_taxes' => 'array', 'additional_tax_total' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<DebitNote, $this> */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
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
}
