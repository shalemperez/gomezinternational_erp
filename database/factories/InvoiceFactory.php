<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Support\Documents\LineInput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'draft',
            'kind' => 'sale',
            'client_id' => Client::factory(),
            'salesperson_id' => Salesperson::factory(),
            'warehouse_id' => Warehouse::factory(),
            'currency_id' => fn () => Currency::query()->where('is_base', true)->value('id') ?? Currency::factory()->create(['code' => 'DOP', 'is_base' => true])->id,
            'exchange_rate' => '1.000000',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'credit',
            'credit_days' => 30,
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

    public function withLines(int $count): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($count): void {
            $inputs = [];
            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create(['itbis_rate' => '18.00']);
                $inputs[] = new LineInput($product->id, '1', '100');
            }
            $computed = app(DocumentTotalsCalculator::class)->compute($inputs);
            DocumentLines::write($invoice, $computed);
            $invoice->forceFill(DocumentLines::totals($computed, $invoice->exchange_rate))->save();
        });
    }
}
