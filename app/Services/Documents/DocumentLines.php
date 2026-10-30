<?php

namespace App\Services\Documents;

use App\Models\Contracts\HasDocumentLines;
use App\Support\Documents\ComputedTotals;
use App\Support\Money\Decimal;

class DocumentLines
{
    /**
     * Replaces the document's lines through the models so every removed and inserted line is audited.
     *
     * @param  array<int, array<string, mixed>>  $extraPerLine  keyed by line index
     */
    public static function write(HasDocumentLines $document, ComputedTotals $computed, array $extraPerLine = []): void
    {
        $document->lines()->get()->each->delete();

        foreach ($computed->lines as $index => $line) {
            $document->lines()->create([
                'line_number' => $index + 1,
                ...$line->toArray(),
                ...($extraPerLine[$index] ?? []),
            ]);
        }
    }

    /** @return array{subtotal: string, discount_total: string, taxable_total: string, itbis_total: string, additional_tax_total: string, total: string, total_base: string} */
    public static function totals(ComputedTotals $computed, string $exchangeRate): array
    {
        return [
            'subtotal' => $computed->subtotal,
            'discount_total' => $computed->discountTotal,
            'taxable_total' => $computed->taxableTotal,
            'itbis_total' => $computed->itbisTotal,
            'additional_tax_total' => $computed->additionalTaxTotal,
            'total' => $computed->total,
            'total_base' => Decimal::mul($computed->total, $exchangeRate, Decimal::MONEY),
        ];
    }
}
