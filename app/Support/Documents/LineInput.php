<?php

namespace App\Support\Documents;

final readonly class LineInput
{
    public function __construct(
        public int $productId,
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent = '0',
        public ?string $description = null,
        public ?string $itbisRate = null,
    ) {}

    /** @param array{product_id: int|string, quantity: string, unit_price: string, discount_percent?: string|null, description?: string|null, itbis_rate?: string|null} $row */
    public static function fromArray(array $row): self
    {
        $discount = $row['discount_percent'] ?? '0';

        return new self((int) $row['product_id'], (string) $row['quantity'], (string) $row['unit_price'], $discount === '' ? '0' : (string) $discount, $row['description'] ?? null, isset($row['itbis_rate']) && $row['itbis_rate'] !== '' ? (string) $row['itbis_rate'] : null);
    }
}
