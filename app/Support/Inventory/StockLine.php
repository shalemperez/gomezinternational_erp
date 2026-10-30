<?php

namespace App\Support\Inventory;

final readonly class StockLine
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public string $quantity,
        public ?string $unitCost = null,
        public ?int $lotId = null,
        public ?string $lotCode = null,
        public ?string $lotExpiresOn = null,
    ) {}
}
