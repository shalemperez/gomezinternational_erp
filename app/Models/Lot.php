<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_on
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['product_id', 'code', 'expires_on'];

    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<StockBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
