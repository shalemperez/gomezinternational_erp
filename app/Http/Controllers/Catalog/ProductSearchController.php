<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Product;
use App\Services\Documents\DocumentTotalsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

class ProductSearchController extends Controller
{
    #[Middleware('permission:catalog.products.view')]
    public function __invoke(Request $request, DocumentTotalsCalculator $calculator): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $clientId = $request->integer('client_id');
        $client = $clientId > 0 ? Client::query()->whereKey($clientId)->first() : null;

        $products = Product::query()->active()
            ->where(fn ($where) => $where->where('sku', 'ILIKE', "%{$query}%")->orWhere('name', 'ILIKE', "%{$query}%")->orWhere('barcode', 'ILIKE', "%{$query}%"))
            ->orderBy('name')->limit(20)->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'itbis_rate' => (string) $product->itbis_rate,
                'tracks_lots' => $product->tracks_lots,
                'unit_price' => $calculator->priceFor($product, $client),
            ]);

        return response()->json(['data' => $products]);
    }
}
