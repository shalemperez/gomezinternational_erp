<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PRV-'.fake()->unique()->numerify('#####'),
            'name' => fake()->company(),
            'country_code' => 'US',
            'tax_id' => null,
            'foreign_tax_id' => fake()->bothify('??-########'),
            'currency_id' => Currency::query()->where('code', 'USD')->value('id') ?? Currency::factory()->create(['code' => 'USD'])->id,
            'credit_days' => 60,
            'address' => null,
            'phone' => null,
            'email' => fake()->unique()->companyEmail(),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
