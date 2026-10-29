<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinancialAccountType;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialAccountRequest;
use App\Http\Requests\Finance\UpdateFinancialAccountRequest;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Support\AuditHistory;
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

class FinancialAccountController extends Controller
{
    #[Middleware('permission:finance.accounts.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['name', 'bank_name', 'account_number'], ['name', 'type', 'is_active'], 'name', 'asc');

        return Inertia::render('finance/accounts/index', [
            'rows' => $rows->through(fn (FinancialAccount $account) => [...$account->toArray(), 'type_label' => $account->type->label()]),
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'type']),
        ]);
    }

    #[Middleware('permission:finance.accounts.manage')]
    public function create(): Response
    {
        return Inertia::render('finance/accounts/form', $this->formOptions());
    }

    #[Middleware('permission:finance.accounts.manage')]
    public function store(StoreFinancialAccountRequest $request): RedirectResponse
    {
        FinancialAccount::create($request->validated());

        return redirect()->route('finance.accounts.index')->with('success', 'Cuenta creada');
    }

    #[Middleware('permission:finance.accounts.manage')]
    public function edit(FinancialAccount $financialAccount): Response
    {
        return Inertia::render('finance/accounts/form', [
            'financialAccount' => $financialAccount,
            'history' => AuditHistory::for($financialAccount),
            ...$this->formOptions(),
        ]);
    }

    #[Middleware('permission:finance.accounts.manage')]
    public function update(UpdateFinancialAccountRequest $request, FinancialAccount $financialAccount): RedirectResponse
    {
        $financialAccount->update($request->validated());

        return redirect()->route('finance.accounts.index')->with('success', 'Cuenta actualizada');
    }

    #[Middleware('permission:finance.accounts.manage')]
    public function destroy(FinancialAccount $financialAccount): RedirectResponse
    {
        try {
            DB::transaction(fn () => $financialAccount->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('finance.accounts.index')->with('success', 'Cuenta eliminada');
    }

    #[Middleware('permission:finance.accounts.view')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['name', 'bank_name', 'account_number'], ['name', 'type', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'accounts', [
            'Tipo' => fn (FinancialAccount $a) => $a->type->label(),
            'Nombre' => 'name',
            'Banco' => 'bank_name',
            'Número de cuenta' => 'account_number',
            'Moneda' => 'currency.code',
            'Activa' => fn (FinancialAccount $a) => $a->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<FinancialAccount> */
    private function filteredQuery(Request $request): Builder
    {
        $query = FinancialAccount::query()->with('currency:id,code');
        $type = FinancialAccountType::tryFrom($request->string('type')->toString());

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'types' => collect(FinancialAccountType::cases())->map(fn (FinancialAccountType $type) => ['value' => $type->value, 'label' => $type->label()])->values(),
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
