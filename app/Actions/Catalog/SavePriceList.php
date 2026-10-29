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
            $list->fill($data)->save();
            if ($list->is_default) {
                PriceList::query()->whereKeyNot($list->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }

            return $list;
        });
    }
}
