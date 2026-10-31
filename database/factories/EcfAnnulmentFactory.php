<?php

namespace Database\Factories;

use App\Models\EcfAnnulment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EcfAnnulment>
 */
class EcfAnnulmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => 'test',
            'status' => 'pending',
            'created_by' => User::factory(),
        ];
    }
}
