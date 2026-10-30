<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Dispatch;
use App\Models\InitialStockLoad;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money\Decimal;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockMovementController extends Controller
{
    #[Middleware('permission:inventory.movements.view')]
    public function index(Request $request): Response
    {
        $canViewCosts = $this->actor($request)->can('inventory.stock.view-costs');
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['notes'], ['moved_at', 'quantity', 'balance_after'], 'moved_at', 'desc')
            ->through(fn (StockMovement $movement) => $this->movementRow($movement, $canViewCosts));
        $productId = $request->integer('product_id');

        return Inertia::render('inventory/movements/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'product_id', 'warehouse_id', 'type', 'from', 'to']),
            'product' => $productId > 0 ? Product::query()->whereKey($productId)->first(['id', 'sku', 'name']) : null,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'types' => array_map(fn (StockMovementType $type) => ['value' => $type->value, 'label' => $type->label()], StockMovementType::cases()),
            'canViewCosts' => $canViewCosts,
        ]);
    }

    #[Middleware('permission:inventory.movements.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $canViewCosts = $this->actor($request)->can('inventory.stock.view-costs');
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['notes'], ['moved_at', 'quantity', 'balance_after'], 'moved_at', 'desc');

        $columns = [
            'Fecha' => fn (StockMovement $movement) => $movement->moved_at?->format('d/m/Y H:i'),
            'Tipo' => fn (StockMovement $movement) => $movement->type->label(),
            'SKU' => 'product.sku',
            'Producto' => 'product.name',
            'Almacén' => 'warehouse.name',
            'Lote' => 'lot.code',
            'Cantidad' => fn (StockMovement $movement) => $this->signedQuantity($movement),
            'Saldo' => 'balance_after',
            'Referencia' => fn (StockMovement $movement) => $this->referenceLabel($movement),
            'Usuario' => 'user.name',
            'Notas' => 'notes',
        ];
        if ($canViewCosts) {
            $columns['Costo unit.'] = 'unit_cost';
            $columns['Costo total'] = 'total_cost';
        }

        return TableExport::fromQuery($query, 'movements', $columns, $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<StockMovement> */
    private function filteredQuery(Request $request): Builder
    {
        return StockMovement::query()
            ->with(['product:id,sku,name', 'warehouse:id,name', 'lot:id,code', 'user:id,name', 'reference'])
            ->filtered([
                'product_id' => $request->integer('product_id'),
                'warehouse_id' => $request->integer('warehouse_id'),
                'type' => $request->string('type')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ]);
    }

    /** @return array<string, mixed> */
    private function movementRow(StockMovement $movement, bool $canViewCosts): array
    {
        $row = [
            'id' => $movement->id,
            'moved_at' => $movement->moved_at?->toIso8601String(),
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'sku' => $movement->product?->sku,
            'name' => $movement->product?->name,
            'warehouse' => $movement->warehouse?->name,
            'lot_code' => $movement->lot?->code,
            'signed_quantity' => $this->signedQuantity($movement),
            'balance_after' => $movement->balance_after,
            'reference_label' => $this->referenceLabel($movement),
            'user_name' => $movement->user?->name,
            'notes' => $movement->notes,
        ];
        if ($canViewCosts) {
            $row['unit_cost'] = $movement->unit_cost;
            $row['total_cost'] = $movement->total_cost;
        }

        return $row;
    }

    private function signedQuantity(StockMovement $movement): string
    {
        return Decimal::qty(Decimal::of($movement->quantity)->multipliedBy($movement->type->direction()));
    }

    private function referenceLabel(StockMovement $movement): string
    {
        $reference = $movement->reference;

        return match (true) {
            $reference instanceof Invoice => 'Factura '.$reference->number,
            $reference instanceof Dispatch => 'Conduce '.$reference->number,
            $reference instanceof CreditNote => 'Nota de crédito '.$reference->number,
            $reference instanceof InitialStockLoad => 'Carga inicial #'.$reference->id,
            default => class_basename((string) $movement->reference_type),
        };
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
