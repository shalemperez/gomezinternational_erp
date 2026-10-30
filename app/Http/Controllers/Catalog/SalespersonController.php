<?php

namespace App\Http\Controllers\Catalog;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSalespersonRequest;
use App\Http\Requests\Catalog\UpdateSalespersonRequest;
use App\Models\Salesperson;
use App\Models\User;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalespersonController extends Controller
{
    #[Middleware('permission:catalog.salespeople.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(Salesperson::query()->with('user:id,name,email'), $request, ['code', 'name', 'email'], ['code', 'name', 'is_active'], 'code', 'asc');

        return Inertia::render('catalog/salespeople/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.salespeople.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/salespeople/form', [
            'users' => $this->userOptions(),
        ]);
    }

    #[Middleware('permission:catalog.salespeople.create')]
    public function store(StoreSalespersonRequest $request): RedirectResponse
    {
        Salesperson::create($request->validated());

        return redirect()->route('catalog.salespeople.index')->with('success', 'Vendedor creado');
    }

    #[Middleware('permission:catalog.salespeople.update')]
    public function edit(Salesperson $salesperson): Response
    {
        return Inertia::render('catalog/salespeople/form', [
            'salesperson' => $salesperson,
            'history' => AuditHistory::for($salesperson),
            'users' => $this->userOptions(),
        ]);
    }

    #[Middleware('permission:catalog.salespeople.update')]
    public function update(UpdateSalespersonRequest $request, Salesperson $salesperson): RedirectResponse
    {
        $salesperson->update($request->validated());

        return redirect()->route('catalog.salespeople.index')->with('success', 'Vendedor actualizado');
    }

    #[Middleware('permission:catalog.salespeople.delete')]
    public function destroy(Salesperson $salesperson): RedirectResponse
    {
        try {
            DB::transaction(fn () => $salesperson->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.salespeople.index')->with('success', 'Vendedor eliminado');
    }

    #[Middleware('permission:catalog.salespeople.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(Salesperson::query()->with('user:id,name,email'), $request, ['code', 'name', 'email'], ['code', 'name', 'is_active'], 'code', 'asc');

        return TableExport::fromQuery($query, 'salespeople', [
            'Código' => 'code',
            'Nombre' => 'name',
            'Correo' => 'email',
            'Teléfono' => 'phone',
            'Usuario' => 'user.email',
            'Activo' => fn (Salesperson $s) => $s->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<int, array{id: int, label: string}> */
    private function userOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'label' => "{$u->name} ({$u->email})"])
            ->values()
            ->all();
    }
}
