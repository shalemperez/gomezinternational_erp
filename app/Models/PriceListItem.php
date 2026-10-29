<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['price_list_id', 'product_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:4'];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
