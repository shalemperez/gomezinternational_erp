<?php

namespace App\Support\Documents;

final readonly class ComputedTotals
{
    /** @param list<ComputedLine> $lines */
    public function __construct(
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $taxableTotal,
        public string $itbisTotal,
        public string $additionalTaxTotal,
        public string $total,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (ComputedLine $line) => $line->toArray(), $this->lines),
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount_total' => $this->discountTotal,
                'taxable_total' => $this->taxableTotal,
                'itbis_total' => $this->itbisTotal,
                'additional_tax_total' => $this->additionalTaxTotal,
                'total' => $this->total,
            ],
        ];
    }
}
