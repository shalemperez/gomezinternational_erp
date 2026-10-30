<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockReservation extends Model
{
    use LogsModelActivity;

    protected $fillable = ['reservable_type', 'reservable_id', 'product_id', 'warehouse_id', 'quantity', 'released_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'released_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function reservable(): MorphTo
    {
        return $this->morphTo('reservable', 'reservable_type', 'reservable_id');
    }
}
