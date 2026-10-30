<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Salesperson;
use App\Models\User;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Support\Documents\LineInput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proforma>
 */
class ProformaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'COT-'.str_pad((string) fake()->unique()->numberBetween(1, 899999), 6, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'client_id' => Client::factory(),
            'salesperson_id' => Salesperson::factory(),
            'currency_id' => fn () => Currency::query()->where('is_base', true)->value('id') ?? Currency::factory()->create(['code' => 'DOP', 'is_base' => true])->id,
            'exchange_rate' => '1.000000',
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(),
            'subtotal' => '0',
            'discount_total' => '0',
            'taxable_total' => '0',
            'itbis_total' => '0',
            'additional_tax_total' => '0',
            'total' => '0',
            'total_base' => '0',
            'created_by' => User::factory(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => 'sent', 'sent_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }

    public function withLines(int $count): static
    {
        return $this->afterCreating(function (Proforma $proforma) use ($count): void {
            $inputs = [];
            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create(['itbis_rate' => '18.00']);
                $inputs[] = new LineInput($product->id, '1', '100');
            }
            $computed = app(DocumentTotalsCalculator::class)->compute($inputs);
            DocumentLines::write($proforma, $computed);
            $proforma->forceFill(DocumentLines::totals($computed, $proforma->exchange_rate))->save();
        });
    }
}
