<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Lista '.fake()->unique()->word(), 'currency_id' => Currency::factory(), 'is_default' => false, 'is_active' => true];
    }
}
