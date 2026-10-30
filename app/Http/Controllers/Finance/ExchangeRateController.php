<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExchangeRateRequest;
use App\Http\Requests\Finance\UpdateExchangeRateRequest;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Finance\ExchangeRateService;
use App\Services\Settings;
use App\Support\Money\Decimal;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class ExchangeRateController extends Controller
{
    #[Middleware('permission:finance.exchange-rates.view')]
    public function index(Request $request, Settings $settings): Response
    {
        $currencies = Currency::query()->active()->where('is_base', false)->orderBy('code')->get()
            ->map(function (Currency $currency): array {
                $latest = $currency->rates()->orderByDesc('rate_date')->first();

                return [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'symbol' => $currency->symbol,
                    'latest_rate' => $latest ? ['rate_date' => $latest->rate_date->format('Y-m-d'), 'rate' => (string) $latest->rate, 'source' => $latest->source->value] : null,
                ];
            })
            ->values()
            ->all();

        $query = ExchangeRate::query()->with(['currency:id,code', 'creator:id,name']);
        if ($request->integer('currency_id') > 0) {
            $query->where('currency_id', $request->integer('currency_id'));
        }

        $rows = TableQuery::apply($query, $request, [], ['rate_date', 'rate'], 'rate_date', 'desc')
            ->through(fn (ExchangeRate $r) => ['id' => $r->id, 'rate_date' => $r->rate_date->format('Y-m-d'), 'rate' => (string) $r->rate, 'source' => $r->source->value, 'source_label' => $r->source->label(), 'fetched_at' => $r->fetched_at?->toIso8601String(), 'notes' => $r->notes, 'currency' => ['id' => $r->currency->id, 'code' => $r->currency->code], 'creator' => $r->creator ? ['name' => $r->creator->name] : null]);

        return Inertia::render('finance/exchange-rates/index', [
            'currencies' => $currencies,
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'currency_id']),
            'sourceType' => $settings->get('exchange_rates.source', 'manual'),
            'lastFetchError' => $settings->get('exchange_rates.last_fetch_error'),
            'today' => now()->toDateString(),
            'canManage' => $request->user()->can('finance.exchange-rates.manage'),
        ]);
    }

    #[Middleware('permission:finance.exchange-rates.manage')]
    public function store(StoreExchangeRateRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        ExchangeRate::updateOrCreate(
            ['currency_id' => $validated['currency_id'], 'rate_date' => $validated['rate_date']],
            ['rate' => Decimal::rate($validated['rate']), 'source' => 'manual', 'fetched_at' => null, 'created_by' => $request->user()->id, 'notes' => $validated['notes'] ?? null],
        );

        return redirect()->route('finance.exchange-rates.index')->with('success', 'Tasa registrada');
    }

    #[Middleware('permission:finance.exchange-rates.manage')]
    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        $validated = $request->validated();

        $exchangeRate->update(['rate' => Decimal::rate($validated['rate']), 'notes' => $validated['notes'] ?? null, 'source' => 'manual', 'created_by' => $request->user()->id]);

        return redirect()->route('finance.exchange-rates.index')->with('success', 'Tasa actualizada');
    }

    #[Middleware('permission:finance.exchange-rates.manage')]
    public function fetch(ExchangeRateService $service): RedirectResponse
    {
        if ($service->source()->name() === 'manual') {
            return back()->with('error', 'La fuente configurada es manual; no hay nada que obtener');
        }

        try {
            $count = count($service->fetchToday());

            return back()->with('success', "Tasas obtenidas: {$count}");
        } catch (\Throwable $e) {
            $service->recordFailure($e->getMessage());

            return back()->with('error', "No se pudo obtener la tasa: {$e->getMessage()}");
        }
    }
}
