<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SaveSupplier;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSupplierRequest;
use App\Http\Requests\Catalog\UpdateSupplierRequest;
use App\Models\Currency;
use App\Models\Supplier;
use App\Support\AuditHistory;
use App\Support\Countries;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierController extends Controller
{
    #[Middleware('permission:catalog.suppliers.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['suppliers.code', 'suppliers.name', 'suppliers.tax_id', 'suppliers.foreign_tax_id', 'suppliers.email'], ['code', 'name', 'is_active'], 'name', 'asc');

        return Inertia::render('catalog/suppliers/index', [
            'rows' => $rows->through(fn (Supplier $supplier) => [...$supplier->toArray(), 'country_label' => Countries::OPTIONS[$supplier->country_code] ?? $supplier->country_code]),
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'country_code', 'status']),
            'countries' => $this->countryOptions(),
        ]);
    }

    #[Middleware('permission:catalog.suppliers.create')]
    public function create(Request $request): Response
    {
        return Inertia::render('catalog/suppliers/form', $this->formOptions($request));
    }

    #[Middleware('permission:catalog.suppliers.create')]
    public function store(StoreSupplierRequest $request, SaveSupplier $saveSupplier): RedirectResponse
    {
        $saveSupplier->handle($request->validated(), $request->user());

        return redirect()->route('catalog.suppliers.index')->with('success', 'Proveedor creado');
    }

    #[Middleware('permission:catalog.suppliers.update')]
    public function edit(Request $request, Supplier $supplier): Response
    {
        return Inertia::render('catalog/suppliers/form', [
            'supplier' => [
                ...$supplier->toArray(),
                'contacts' => $supplier->contacts->toArray(),
                'tax_id_override_by_name' => $supplier->taxIdOverrideBy?->name,
            ],
            'history' => AuditHistory::for($supplier),
            ...$this->formOptions($request, $supplier),
        ]);
    }

    #[Middleware('permission:catalog.suppliers.update')]
    public function update(UpdateSupplierRequest $request, Supplier $supplier, SaveSupplier $saveSupplier): RedirectResponse
    {
        $saveSupplier->handle($request->validated(), $request->user(), $supplier);

        return redirect()->route('catalog.suppliers.index')->with('success', 'Proveedor actualizado');
    }

    #[Middleware('permission:catalog.suppliers.delete')]
    public function destroy(Supplier $supplier): RedirectResponse
    {
        try {
            DB::transaction(fn () => $supplier->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.suppliers.index')->with('success', 'Proveedor eliminado');
    }

    #[Middleware('permission:catalog.suppliers.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['suppliers.code', 'suppliers.name', 'suppliers.tax_id', 'suppliers.foreign_tax_id', 'suppliers.email'], ['code', 'name', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'suppliers', [
            'Código' => 'code',
            'Nombre' => 'name',
            'País' => fn (Supplier $supplier) => Countries::OPTIONS[$supplier->country_code] ?? $supplier->country_code,
            'RNC/Cédula' => 'tax_id',
            'ID fiscal extranjera' => 'foreign_tax_id',
            'Moneda' => 'currency.code',
            'Días de crédito' => 'credit_days',
            'Dirección' => 'address',
            'Teléfono' => 'phone',
            'Correo' => 'email',
            'ID forzada' => fn (Supplier $supplier) => $supplier->tax_id_validation_overridden ? 'Sí' : 'No',
            'Activo' => fn (Supplier $supplier) => $supplier->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<Supplier> */
    private function filteredQuery(Request $request): Builder
    {
        return Supplier::query()
            ->with('currency:id,code')
            ->when($request->filled('country_code'), fn (Builder $query) => $query->where('country_code', $request->string('country_code')->toString()))
            ->when($request->string('status')->toString() === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn (Builder $query) => $query->where('is_active', false));
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request, ?Supplier $supplier = null): array
    {
        return [
            'countries' => $this->countryOptions(),
            'currencies' => Currency::query()->where(fn (Builder $query) => $query->active()->orWhereKey($supplier?->currency_id))->orderBy('code')->get(['id', 'code', 'name']),
            'canOverride' => $request->user()->can('catalog.tax-ids.override-validation'),
        ];
    }

    /** @return array<int, array{value: string, label: string}> */
    private function countryOptions(): array
    {
        return collect(Countries::OPTIONS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
