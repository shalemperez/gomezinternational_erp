<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveProduct
{
    /** @param array<string, mixed> $data validated StoreProductRequest/UpdateProductRequest data */
    public function handle(array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product): Product {
            $product ??= new Product;
            $attributes = Arr::except($data, ['additional_tax_ids']);
            $attributes['minimum_stock'] = $attributes['minimum_stock'] ?? '0';
            $product->fill($attributes)->save();

            $before = $product->additionalTaxes()->pluck('code')->sort()->values()->all();
            $product->additionalTaxes()->sync($data['additional_tax_ids'] ?? []);
            $after = $product->additionalTaxes()->pluck('code')->sort()->values()->all();
            if ($before !== $after) {
                activity('products')->performedOn($product)->event('additional_taxes_synced')->withProperties(['before' => $before, 'after' => $after])->log('Impuestos adicionales actualizados');
            }

            return $product;
        });
    }
}
