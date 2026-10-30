<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\ImmutableWhenIssued;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use ImmutableWhenIssued, LogsModelActivity;

    protected $fillable = [
        'type', 'product_id', 'warehouse_id', 'lot_id', 'quantity', 'unit_cost', 'total_cost', 'balance_after',
        'reference_type', 'reference_id', 'moved_at', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class, 'quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2',
            'balance_after' => 'decimal:4', 'moved_at' => 'datetime',
        ];
    }

    protected function issuedAtColumn(): string
    {
        return 'moved_at';
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }
}
