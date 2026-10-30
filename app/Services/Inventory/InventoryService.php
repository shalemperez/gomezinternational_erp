<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InventoryException;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingEventRecorder;
use App\Support\Inventory\StockLine;
use App\Support\Money\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private readonly AccountingEventRecorder $events) {}

    public function receive(StockLine $line, StockMovementType $type, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        $this->assertPositive($line->quantity);
        if ($type->direction() !== 1) {
            throw new InventoryException('El tipo de movimiento no es una entrada');
        }

        return DB::transaction(function () use ($line, $type, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $lot = $this->resolveLot($product, $line, required: $product->tracks_lots);
            $qty = Decimal::qty($line->quantity);
            $unitCost = Decimal::qty($line->unitCost ?? '0');

            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $oldQty = Decimal::of($warehouseLevel->quantity);
            $newQty = $oldQty->plus(Decimal::of($qty));
            $newAvg = $newQty->isZero() ? '0.0000' : (string) $oldQty->multipliedBy(Decimal::of($warehouseLevel->average_cost))
                ->plus(Decimal::of($qty)->multipliedBy(Decimal::of($unitCost)))
                ->dividedBy($newQty, 4, RoundingMode::HalfUp);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty($newQty), 'average_cost' => $newAvg])->save();

            $balanceAfter = (string) $warehouseLevel->quantity;
            if ($lot !== null) {
                $lotLevel = $this->balance($product->id, $line->warehouseId, $lot->id, lock: true);
                $lotLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($lotLevel->quantity)->plus(Decimal::of($qty))), 'average_cost' => $newAvg])->save();
                $balanceAfter = (string) $lotLevel->quantity;
            }

            return $this->movement($type, $product, $line->warehouseId, $lot?->id, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function dispatch(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        $this->assertPositive($line->quantity);

        return DB::transaction(function () use ($line, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $warehouse = Warehouse::query()->findOrFail($line->warehouseId);
            if ($product->tracks_lots && $line->lotId === null) {
                throw new InventoryException("El producto {$product->sku} controla lotes: indica el lote a despachar");
            }
            $qty = Decimal::qty($line->quantity);
            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $target = $line->lotId !== null ? $this->balance($product->id, $line->warehouseId, $line->lotId, lock: true) : $warehouseLevel;
            if (Decimal::cmp($target->quantity, $qty) < 0) {
                throw new InventoryException("Existencia insuficiente de {$product->sku} en {$warehouse->name}: disponible ".Decimal::qty($target->quantity));
            }
            $unitCost = Decimal::qty($warehouseLevel->average_cost);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($warehouseLevel->quantity)->minus(Decimal::of($qty)))])->save();
            $balanceAfter = (string) $warehouseLevel->quantity;
            if ($line->lotId !== null) {
                $target->forceFill(['quantity' => Decimal::qty(Decimal::of($target->quantity)->minus(Decimal::of($qty)))])->save();
                $balanceAfter = (string) $target->quantity;
            }

            return $this->movement(StockMovementType::Dispatch, $product, $line->warehouseId, $line->lotId, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function returnStock(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        $this->assertPositive($line->quantity);

        return DB::transaction(function () use ($line, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $lot = $this->resolveLot($product, $line, required: $product->tracks_lots);
            $qty = Decimal::qty($line->quantity);
            $unitCost = Decimal::qty($line->unitCost ?? '0');
            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($warehouseLevel->quantity)->plus(Decimal::of($qty)))])->save(); // average unchanged
            $balanceAfter = (string) $warehouseLevel->quantity;
            if ($lot !== null) {
                $lotLevel = $this->balance($product->id, $line->warehouseId, $lot->id, lock: true);
                $lotLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($lotLevel->quantity)->plus(Decimal::of($qty)))])->save();
                $balanceAfter = (string) $lotLevel->quantity;
            }

            return $this->movement(StockMovementType::Return, $product, $line->warehouseId, $lot?->id, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function reserve(Model $reservable, int $productId, int $warehouseId, string $quantity): StockReservation
    {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($reservable, $productId, $warehouseId, $quantity): StockReservation {
            $product = Product::query()->findOrFail($productId);
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            $qty = Decimal::qty($quantity);
            $balance = $this->balance($productId, $warehouseId, null, lock: true);
            $available = $this->available($productId, $warehouseId);
            if (Decimal::cmp($available, $qty) < 0) {
                throw new InventoryException("Disponible insuficiente de {$product->sku} en {$warehouse->name}: disponible {$available}, solicitado {$qty}");
            }
            $balance->forceFill(['reserved_quantity' => Decimal::qty(Decimal::of($balance->reserved_quantity)->plus(Decimal::of($qty)))])->save();

            return StockReservation::create(['reservable_type' => $reservable::class, 'reservable_id' => $reservable->getKey(), 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $qty]);
        });
    }

    public function release(StockReservation $reservation, ?string $quantity = null): void
    {
        DB::transaction(function () use ($reservation, $quantity): void {
            /** @var StockReservation $locked */
            $locked = StockReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->released_at !== null) {
                throw new InventoryException('La reserva ya fue liberada');
            }
            $qty = Decimal::qty($quantity ?? $locked->quantity);
            $this->assertPositive($qty);
            if (Decimal::cmp($qty, $locked->quantity) > 0) {
                throw new InventoryException("No se puede liberar {$qty}: la reserva tiene {$locked->quantity}");
            }
            $balance = $this->balance($locked->product_id, $locked->warehouse_id, null, lock: true);
            $balance->forceFill(['reserved_quantity' => Decimal::qty(Decimal::of($balance->reserved_quantity)->minus(Decimal::of($qty)))])->save();
            $remaining = Decimal::qty(Decimal::of($locked->quantity)->minus(Decimal::of($qty)));
            $locked->forceFill(['quantity' => $remaining, 'released_at' => Decimal::isZero($remaining) ? now() : null])->save();
            $reservation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function available(int $productId, int $warehouseId): string
    {
        $row = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->whereNull('lot_id')->first();

        return $row === null ? '0.0000' : $row->available;
    }

    /** @return list<array{lot_id: int, code: string, expires_on: ?string, quantity: string}> */
    public function suggestLots(int $productId, int $warehouseId, string $quantity): array
    {
        $needed = Decimal::of($quantity);
        $out = [];
        $rows = StockBalance::query()->with('lot')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->whereNotNull('lot_id')->where('quantity', '>', 0)->get()
            ->sortBy([fn (StockBalance $a, StockBalance $b) => ($a->lot->expires_on?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->lot->expires_on?->getTimestamp() ?? PHP_INT_MAX) ?: $a->lot_id <=> $b->lot_id]);
        foreach ($rows as $row) {
            if ($needed->isNegativeOrZero()) {
                break;
            }
            $take = Decimal::of($row->quantity)->isGreaterThan($needed) ? $needed : Decimal::of($row->quantity);
            $out[] = ['lot_id' => $row->lot_id, 'code' => $row->lot->code, 'expires_on' => $row->lot->expires_on?->toDateString(), 'quantity' => Decimal::qty($take)];
            $needed = $needed->minus($take);
        }

        return $out;
    }

    /** @return Collection<int, Product> */
    public function lowStock(): Collection
    {
        return Product::query()->active()->where('minimum_stock', '>', 0)
            ->withSum(['balances as stock_total' => fn ($q) => $q->whereNull('lot_id')], 'quantity')
            ->get()->filter(fn (Product $p) => Decimal::cmp((string) ($p->stock_total ?? '0'), (string) $p->minimum_stock) < 0)->values();
    }

    /** @return Collection<int, StockBalance> */
    public function expiringLots(int $days): Collection
    {
        return StockBalance::query()->with(['lot', 'product', 'warehouse'])->whereNotNull('lot_id')->where('quantity', '>', 0)
            ->whereHas('lot', fn ($q) => $q->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days)->toDateString()))->get();
    }

    private function resolveLot(Product $product, StockLine $line, bool $required): ?Lot
    {
        if ($line->lotId !== null) {
            return Lot::query()->findOrFail($line->lotId);
        }
        if ($line->lotCode !== null && $line->lotCode !== '') {
            return Lot::query()->firstOrCreate(['product_id' => $product->id, 'code' => $line->lotCode], ['expires_on' => $line->lotExpiresOn]);
        }
        if ($required) {
            throw new InventoryException("El producto {$product->sku} controla lotes: indica lote y fecha de vencimiento");
        }

        return null;
    }

    private function balance(int $productId, int $warehouseId, ?int $lotId, bool $lock = false): StockBalance
    {
        $query = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId);
        $lotId === null ? $query->whereNull('lot_id') : $query->where('lot_id', $lotId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? StockBalance::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId, 'quantity' => '0', 'reserved_quantity' => '0', 'average_cost' => '0']);
    }

    private function movement(StockMovementType $type, Product $product, int $warehouseId, ?int $lotId, string $qty, string $unitCost, string $balanceAfter, Model $reference, User $actor, ?string $notes): StockMovement
    {
        $movement = StockMovement::create([
            'type' => $type, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId, 'quantity' => $qty, 'unit_cost' => $unitCost,
            'total_cost' => Decimal::mul($qty, $unitCost, Decimal::MONEY), 'balance_after' => $balanceAfter, 'reference_type' => $reference::class, 'reference_id' => $reference->getKey(),
            'moved_at' => now(), 'user_id' => $actor->id, 'notes' => $notes,
        ]);
        $this->events->record('inventory_movement', $movement, [
            'type' => $type->value, 'product_id' => $product->id, 'sku' => $product->sku, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId,
            'quantity' => $qty, 'unit_cost' => $unitCost, 'total_cost' => $movement->total_cost, 'currency' => 'DOP', 'exchange_rate' => '1.000000',
        ]);

        return $movement;
    }

    /**
     * Rejects zero or negative quantities on the service API (forms validate too, but this API is the last line).
     */
    private function assertPositive(string $quantity): void
    {
        if (Decimal::cmp($quantity, '0') <= 0) {
            throw new InventoryException('La cantidad debe ser mayor que cero');
        }
    }
}
