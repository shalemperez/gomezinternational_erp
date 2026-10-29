<?php

namespace Database\Factories;

use App\Models\Salesperson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salesperson>
 */
class SalespersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('VEN-##')),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'user_id' => null,
            'is_active' => true,
        ];
    }
}
