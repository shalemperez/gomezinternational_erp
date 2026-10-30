<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use App\Support\Money\Decimal;
use Database\Factories\StockBalanceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['product_id', 'warehouse_id', 'lot_id', 'quantity', 'reserved_quantity', 'average_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'reserved_quantity' => 'decimal:4', 'average_cost' => 'decimal:4'];
    }

    /** @return Attribute<string, never> */
    protected function available(): Attribute
    {
        return Attribute::get(fn () => Decimal::qty(Decimal::of($this->quantity)->minus(Decimal::of($this->reserved_quantity))));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
