<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\InitialStockLoad;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Illuminate\Support\Facades\DB;

class LoadInitialStock
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  list<array{product_id: int, warehouse_id: int, quantity: string, unit_cost: string, lot_code?: ?string, lot_expires_on?: ?string}>  $lines
     * @return list<StockMovement>
     */
    public function handle(array $lines, User $actor, string $note): array
    {
        return DB::transaction(function () use ($lines, $actor, $note): array {
            $load = InitialStockLoad::create(['note' => $note, 'user_id' => $actor->id, 'lines_count' => count($lines)]);

            $movements = [];
            foreach ($lines as $line) {
                $movements[] = $this->inventory->receive(
                    new StockLine($line['product_id'], $line['warehouse_id'], $line['quantity'], $line['unit_cost'], null, ($line['lot_code'] ?? null) ?: null, ($line['lot_expires_on'] ?? null) ?: null),
                    StockMovementType::Initial,
                    $load,
                    $actor,
                    $note,
                );
            }

            return $movements;
        });
    }
}
