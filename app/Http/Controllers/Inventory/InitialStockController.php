<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\LoadInitialStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInitialStockRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class InitialStockController extends Controller
{
    #[Middleware('permission:inventory.initial-stock.import')]
    public function create(): Response
    {
        return Inertia::render('inventory/initial-stock/form', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultWarehouseId' => Warehouse::default()?->id,
        ]);
    }

    #[Middleware('permission:inventory.initial-stock.import')]
    public function store(StoreInitialStockRequest $request, LoadInitialStock $loadInitialStock): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $movements = $loadInitialStock->handle($request->validated('lines'), $user, $request->string('note')->toString());

        return redirect()->route('inventory.stock.index')->with('success', 'Existencias iniciales cargadas: '.count($movements).' líneas');
    }
}
