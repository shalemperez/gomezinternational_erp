<?php

namespace Database\Factories;

use App\Models\AdditionalTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdditionalTax>
 */
class AdditionalTaxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ISC-??#')),
            'name' => fake()->words(2, true),
            'calculation_type' => 'percent_of_base',
            'rate' => '10.0000',
            'amount' => null,
            'included_in_itbis_base' => true,
            'dgii_code' => null,
            'is_active' => true,
        ];
    }
}
