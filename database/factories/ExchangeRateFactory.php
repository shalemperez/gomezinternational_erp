<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['currency_id' => Currency::factory(), 'rate_date' => now()->toDateString(), 'rate' => '58.000000', 'source' => 'manual', 'fetched_at' => null, 'created_by' => null, 'notes' => null];
    }
}
