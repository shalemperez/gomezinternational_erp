<?php

namespace Database\Factories;

use App\Models\EcfDocument;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EcfDocument>
 */
class EcfDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => Invoice::class,
            'document_id' => Invoice::factory(),
            'ncf_type' => 'E31',
            'encf' => 'E31'.str_pad((string) fake()->unique()->numberBetween(1, 9999999), 10, '0', STR_PAD_LEFT),
            'environment' => 'test',
            'provider' => 'fake',
            'flow' => 'ecf',
            'status' => 'pending',
        ];
    }
}
