<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCurrencyRequest;
use App\Http\Requests\Finance\UpdateCurrencyRequest;
use App\Models\Currency;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    #[Middleware('permission:finance.currencies.manage')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(Currency::query(), $request, ['code', 'name'], ['code', 'name', 'is_active'], 'code', 'asc');

        return Inertia::render('finance/currencies/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:finance.currencies.manage')]
    public function create(): Response
    {
        return Inertia::render('finance/currencies/form');
    }

    #[Middleware('permission:finance.currencies.manage')]
    public function store(StoreCurrencyRequest $request): RedirectResponse
    {
        Currency::create($request->validated());

        return redirect()->route('finance.currencies.index')->with('success', 'Moneda creada');
    }

    #[Middleware('permission:finance.currencies.manage')]
    public function edit(Currency $currency): Response
    {
        return Inertia::render('finance/currencies/form', [
            'currency' => $currency,
            'history' => AuditHistory::for($currency),
        ]);
    }

    #[Middleware('permission:finance.currencies.manage')]
    public function update(UpdateCurrencyRequest $request, Currency $currency): RedirectResponse
    {
        $currency->update($request->validated());

        return redirect()->route('finance.currencies.index')->with('success', 'Moneda actualizada');
    }
}
