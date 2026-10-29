<?php

namespace App\Actions\Inventory;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class SaveWarehouse
{
    /** @param array<string, mixed> $data validated StoreWarehouseRequest/UpdateWarehouseRequest data */
    public function handle(array $data, ?Warehouse $warehouse = null): Warehouse
    {
        return DB::transaction(function () use ($data, $warehouse): Warehouse {
            $warehouse ??= new Warehouse;
            $warehouse->fill($data)->save();
            if ($warehouse->is_default) {
                Warehouse::query()->whereKeyNot($warehouse->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }

            return $warehouse;
        });
    }
}
