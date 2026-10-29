<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Currency;
use App\Support\FiscalId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CLI-'.fake()->unique()->numerify('#####'),
            'business_name' => fake()->company(),
            'trade_name' => null,
            'tax_id_type' => 'rnc',
            'tax_id' => $this->rnc(),
            'taxpayer_type' => 'juridica',
            'address' => fake()->streetAddress(),
            'city' => 'Santo Domingo',
            'phone' => null,
            'email' => fake()->unique()->safeEmail(),
            'salesperson_id' => null,
            'credit_days' => 30,
            'credit_limit' => '100000.00',
            'price_list_id' => null,
            'currency_id' => Currency::query()->where('is_base', true)->value('id') ?? Currency::factory()->create(['code' => 'DOP', 'is_base' => true])->id,
            'notes' => null,
            'is_active' => true,
        ];
    }

    private function rnc(): string
    {
        $first = '1'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        return $first.FiscalId::rncCheckDigit($first);
    }
}
