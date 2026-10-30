<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'code' => strtoupper(fake()->unique()->bothify('LOT-####')), 'expires_on' => now()->addYear()->toDateString()];
    }
}
