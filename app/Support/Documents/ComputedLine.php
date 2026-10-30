<?php

namespace App\Support\Documents;

final readonly class ComputedLine
{
    /** @param list<array{code: string, name: string, amount: string}> $additionalTaxes */
    public function __construct(
        public int $productId,
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent,
        public string $lineSubtotal,
        public string $discountAmount,
        public string $taxableBase,
        public string $itbisRate,
        public string $itbisAmount,
        public array $additionalTaxes,
        public string $additionalTaxTotal,
        public string $lineTotal,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'discount_percent' => $this->discountPercent,
            'line_subtotal' => $this->lineSubtotal,
            'discount_amount' => $this->discountAmount,
            'taxable_base' => $this->taxableBase,
            'itbis_rate' => $this->itbisRate,
            'itbis_amount' => $this->itbisAmount,
            'additional_taxes' => $this->additionalTaxes,
            'additional_tax_total' => $this->additionalTaxTotal,
            'line_total' => $this->lineTotal,
        ];
    }
}
