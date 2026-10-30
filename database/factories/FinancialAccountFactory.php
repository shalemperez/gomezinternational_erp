<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['type' => 'bank', 'name' => 'Cuenta '.fake()->unique()->word(), 'bank_name' => 'Banco Popular', 'account_number' => fake()->unique()->numerify('##########'), 'currency_id' => Currency::query()->where('is_base', true)->value('id') ?? Currency::factory()->create(['code' => 'DOP', 'is_base' => true])->id, 'is_active' => true];
    }
}
