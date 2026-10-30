<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'warehouse_id' => Warehouse::factory(), 'lot_id' => null, 'quantity' => '0', 'reserved_quantity' => '0', 'average_cost' => '0'];
    }
}
