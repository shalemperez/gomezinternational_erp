<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierContact>
 */
class SupplierContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'name' => fake()->name(),
            'position' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'is_primary' => false,
        ];
    }
}
