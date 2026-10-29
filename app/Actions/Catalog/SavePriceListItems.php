<?php

namespace App\Actions\Catalog;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class SavePriceListItems
{
    /** @param list<array{product_id: int, price: ?string}> $items */
    public function handle(PriceList $list, array $items): int
    {
        return DB::transaction(function () use ($list, $items): int {
            $changed = 0;
            foreach ($items as $item) {
                $existing = PriceListItem::query()->where('price_list_id', $list->id)->where('product_id', $item['product_id'])->first();
                $price = $item['price'] ?? null;
                if ($price === null || $price === '') {
                    if ($existing !== null) {
                        $existing->delete();
                        $changed++;
                    }

                    continue;
                }
                $price = Decimal::qty((string) $price);
                if ($existing === null) {
                    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $item['product_id'], 'price' => $price]);
                    $changed++;
                } elseif ($existing->price !== $price) {
                    $existing->update(['price' => $price]);
                    $changed++;
                }
            }
            if ($changed > 0) {
                activity('price_lists')->performedOn($list)->event('items_updated')->withProperties(['changed' => $changed])->log("Precios actualizados ({$changed} cambios)");
            }

            return $changed;
        });
    }
}
