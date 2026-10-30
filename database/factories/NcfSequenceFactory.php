<?php

namespace Database\Factories;

use App\Models\NcfSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NcfSequence>
 */
class NcfSequenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['ncf_type' => 'B01', 'range_from' => 1, 'range_to' => 100, 'next_number' => 1, 'expires_on' => now()->addYear()->endOfYear()->toDateString(), 'authorization_number' => fake()->numerify('70########'), 'is_active' => true, 'exhausted_at' => null, 'notes' => null];
    }
}
