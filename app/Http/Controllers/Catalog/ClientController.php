<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SaveClient;
use App\Enums\TaxIdType;
use App\Enums\TaxpayerType;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreClientRequest;
use App\Http\Requests\Catalog\UpdateClientRequest;
use App\Models\Client;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\Salesperson;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClientController extends Controller
{
    #[Middleware('permission:catalog.clients.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->scopedQuery($request), $request, ['clients.code', 'clients.business_name', 'clients.trade_name', 'clients.tax_id', 'clients.email'], ['code', 'business_name', 'credit_limit', 'is_active'], 'business_name', 'asc');

        return Inertia::render('catalog/clients/index', [
            'rows' => $rows->through(fn (Client $client) => [...$client->toArray(), 'formatted_tax_id' => $client->getAttribute('formatted_tax_id')]),
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'salesperson_id', 'status']),
            'salespeople' => Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.clients.create')]
    public function create(Request $request): Response
    {
        return Inertia::render('catalog/clients/form', $this->formOptions($request));
    }

    #[Middleware('permission:catalog.clients.create')]
    public function store(StoreClientRequest $request, SaveClient $saveClient): RedirectResponse
    {
        $saveClient->handle($request->validated(), $request->user());

        return redirect()->route('catalog.clients.index')->with('success', 'Cliente creado');
    }

    #[Middleware('permission:catalog.clients.update')]
    public function edit(Request $request, Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('catalog/clients/form', [
            'client' => [
                ...$client->toArray(),
                'formatted_tax_id' => $client->getAttribute('formatted_tax_id'),
                'contacts' => $client->contacts->toArray(),
                'tax_id_override_by_name' => $client->taxIdOverrideBy?->name,
            ],
            'history' => AuditHistory::for($client),
            ...$this->formOptions($request),
        ]);
    }

    #[Middleware('permission:catalog.clients.update')]
    public function update(UpdateClientRequest $request, Client $client, SaveClient $saveClient): RedirectResponse
    {
        Gate::authorize('update', $client);
        $saveClient->handle($request->validated(), $request->user(), $client);

        return redirect()->route('catalog.clients.index')->with('success', 'Cliente actualizado');
    }

    #[Middleware('permission:catalog.clients.delete')]
    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);

        try {
            DB::transaction(fn () => $client->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.clients.index')->with('success', 'Cliente eliminado');
    }

    #[Middleware('permission:catalog.clients.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->scopedQuery($request), $request, ['clients.code', 'clients.business_name', 'clients.trade_name', 'clients.tax_id', 'clients.email'], ['code', 'business_name', 'credit_limit', 'is_active'], 'business_name', 'asc');

        return TableExport::fromQuery($query, 'clients', [
            'Código' => 'code',
            'Razón social' => 'business_name',
            'Nombre comercial' => 'trade_name',
            'Tipo ID' => fn (Client $c) => $c->tax_id_type->label(),
            'RNC/Cédula' => 'tax_id',
            'Tipo contribuyente' => fn (Client $c) => $c->taxpayer_type->label(),
            'Dirección' => 'address',
            'Ciudad' => 'city',
            'Teléfono' => 'phone',
            'Correo' => 'email',
            'Vendedor' => 'salesperson.name',
            'Días de crédito' => 'credit_days',
            'Límite de crédito' => 'credit_limit',
            'Lista de precios' => 'priceList.name',
            'Moneda' => 'currency.code',
            'ID forzada' => fn (Client $c) => $c->tax_id_validation_overridden ? 'Sí' : 'No',
            'Activo' => fn (Client $c) => $c->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<Client> */
    private function scopedQuery(Request $request): Builder
    {
        return Client::query()
            ->visibleTo($request->user())
            ->with(['salesperson:id,name', 'currency:id,code', 'priceList:id,name'])
            ->when($request->filled('salesperson_id'), fn (Builder $query) => $query->where('salesperson_id', $request->integer('salesperson_id')))
            ->when($request->string('status')->toString() === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn (Builder $query) => $query->where('is_active', false));
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'salespeople' => Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'priceLists' => PriceList::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'taxIdTypes' => array_map(fn (TaxIdType $type) => ['value' => $type->value, 'label' => $type->label()], TaxIdType::cases()),
            'taxpayerTypes' => array_map(fn (TaxpayerType $type) => ['value' => $type->value, 'label' => $type->label()], TaxpayerType::cases()),
            'defaultCurrencyId' => Currency::base()->id,
            'lockSalesperson' => ! $request->user()->can('catalog.clients.view-all'),
            'canOverride' => $request->user()->can('catalog.tax-ids.override-validation'),
        ];
    }
}
