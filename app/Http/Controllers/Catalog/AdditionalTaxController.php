<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\AdditionalTaxCalculationType;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreAdditionalTaxRequest;
use App\Http\Requests\Catalog\UpdateAdditionalTaxRequest;
use App\Models\AdditionalTax;
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

class AdditionalTaxController extends Controller
{
    #[Middleware('permission:catalog.additional-taxes.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(AdditionalTax::query(), $request, ['code', 'name'], ['code', 'name', 'is_active'], 'code', 'asc')
            ->through(fn (AdditionalTax $tax) => [...$tax->toArray(), 'calculation_type_label' => $tax->calculation_type->label()]);

        return Inertia::render('catalog/additional-taxes/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.additional-taxes.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/additional-taxes/form', [
            'calculationTypes' => $this->calculationTypeOptions(),
        ]);
    }

    #[Middleware('permission:catalog.additional-taxes.create')]
    public function store(StoreAdditionalTaxRequest $request): RedirectResponse
    {
        AdditionalTax::create($request->validated());

        return redirect()->route('catalog.additional-taxes.index')->with('success', 'Impuesto adicional creado');
    }

    #[Middleware('permission:catalog.additional-taxes.update')]
    public function edit(AdditionalTax $additionalTax): Response
    {
        return Inertia::render('catalog/additional-taxes/form', [
            'additionalTax' => $additionalTax,
            'history' => AuditHistory::for($additionalTax),
            'calculationTypes' => $this->calculationTypeOptions(),
        ]);
    }

    #[Middleware('permission:catalog.additional-taxes.update')]
    public function update(UpdateAdditionalTaxRequest $request, AdditionalTax $additionalTax): RedirectResponse
    {
        $additionalTax->update($request->validated());

        return redirect()->route('catalog.additional-taxes.index')->with('success', 'Impuesto adicional actualizado');
    }

    #[Middleware('permission:catalog.additional-taxes.delete')]
    public function destroy(AdditionalTax $additionalTax): RedirectResponse
    {
        try {
            DB::transaction(fn () => $additionalTax->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.additional-taxes.index')->with('success', 'Impuesto adicional eliminado');
    }

    #[Middleware('permission:catalog.additional-taxes.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(AdditionalTax::query(), $request, ['code', 'name'], ['code', 'name', 'is_active'], 'code', 'asc');

        return TableExport::fromQuery($query, 'additional-taxes', [
            'Código' => 'code',
            'Nombre' => 'name',
            'Tipo de cálculo' => fn (AdditionalTax $t) => $t->calculation_type->label(),
            'Tasa %' => 'rate',
            'Monto' => 'amount',
            'Incluido en base ITBIS' => fn (AdditionalTax $t) => $t->included_in_itbis_base ? 'Sí' : 'No',
            'Código DGII' => 'dgii_code',
            'Activo' => fn (AdditionalTax $t) => $t->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<int, array{value: string, label: string}> */
    private function calculationTypeOptions(): array
    {
        return collect(AdditionalTaxCalculationType::cases())
            ->map(fn (AdditionalTaxCalculationType $case) => ['value' => $case->value, 'label' => $case->label()])
            ->values()
            ->all();
    }
}
