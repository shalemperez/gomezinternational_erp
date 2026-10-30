<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\FiscalId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

class ClientSearchController extends Controller
{
    #[Middleware('permission:catalog.clients.view')]
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->string('q')->trim()->toString();
        $taxId = FiscalId::normalize($query);

        $clients = Client::query()->visibleTo($request->user())->where('is_active', true)
            ->where(fn (Builder $where) => $where->where('code', 'ILIKE', "%{$query}%")->orWhere('business_name', 'ILIKE', "%{$query}%")->orWhere('trade_name', 'ILIKE', "%{$query}%")
                ->when($taxId !== '', fn (Builder $where) => $where->orWhere('tax_id', 'ILIKE', "%{$taxId}%")))
            ->with('currency:id,code')->orderBy('business_name')->limit(20)->get();

        return response()->json(['data' => $clients->map(fn (Client $client) => [
            'id' => $client->id,
            'code' => $client->code,
            'business_name' => $client->business_name,
            'trade_name' => $client->trade_name,
            'tax_id' => $client->tax_id,
            'formatted_tax_id' => $client->getAttribute('formatted_tax_id'),
            'currency_code' => $client->currency?->code,
            'price_list_id' => $client->price_list_id,
            'credit_days' => $client->credit_days,
            'email' => $client->email,
            'salesperson_id' => $client->salesperson_id,
        ])->values()]);
    }
}
