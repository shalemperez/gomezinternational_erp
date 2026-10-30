<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-#####')),
            'barcode' => null,
            'name' => fake()->words(3, true),
            'brand_id' => Brand::factory(),
            'category_id' => null,
            'unit_of_measure_id' => UnitOfMeasure::factory(),
            'units_per_box' => 12,
            'weight_kg' => null,
            'volume_liters' => null,
            'alcohol_degree' => null,
            'itbis_rate' => '18.00',
            'tracks_lots' => false,
            'tariff_code' => null,
            'minimum_stock' => '0',
            'description' => null,
            'is_active' => true,
        ];
    }
}
