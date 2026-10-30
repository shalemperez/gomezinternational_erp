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
            if (($data['is_default'] ?? false) === true) {
                Warehouse::query()->whereKeyNot($warehouse->getKey() ?? 0)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }
            $warehouse->fill($data)->save();

            return $warehouse;
        });
    }
}
