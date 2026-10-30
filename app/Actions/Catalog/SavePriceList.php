<?php

namespace App\Actions\Catalog;

use App\Models\PriceList;
use Illuminate\Support\Facades\DB;

class SavePriceList
{
    /** @param array<string, mixed> $data validated StorePriceListRequest/UpdatePriceListRequest data */
    public function handle(array $data, ?PriceList $list = null): PriceList
    {
        return DB::transaction(function () use ($data, $list): PriceList {
            $list ??= new PriceList;
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->whereKeyNot($list->getKey() ?? 0)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }
            $list->fill($data)->save();

            return $list;
        });
    }
}
