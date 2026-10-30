<?php

namespace App\Imports;

use App\Actions\Catalog\SavePriceListItems;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PriceListItemsImporter extends BaseImporter
{
    public static function type(): string
    {
        return 'price_list_items';
    }

    public function label(): string
    {
        return 'Precios de lista';
    }

    public function permission(): string
    {
        return 'catalog.price-lists.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'sku', 'label' => 'SKU', 'required' => true, 'example' => 'RON-750'],
            ['key' => 'price', 'label' => 'Precio', 'required' => true, 'example' => '150.50'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        $row['sku'] = isset($row['sku']) ? strtoupper($row['sku']) : null;

        return $row;
    }

    /** @return array<string, mixed> */
    public function metaRules(): array
    {
        return ['price_list_id' => ['required', 'integer', Rule::exists('price_lists', 'id')->where('is_active', true)]];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function rules(array $row, array $meta): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'exists:products,sku'],
            'price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['sku'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        $productId = Product::query()->where('sku', $row['sku'])->value('id');
        if ($productId === null) {
            return null;
        }

        return PriceListItem::query()->where('price_list_id', $meta['price_list_id'])->where('product_id', $productId)->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        $productId = (int) Product::query()->where('sku', $row['sku'])->value('id');
        $list = PriceList::query()->whereKey($meta['price_list_id'])->firstOrFail();
        app(SavePriceListItems::class)->handle($list, [['product_id' => $productId, 'price' => $row['price']]]);

        return PriceListItem::query()->where('price_list_id', $list->id)->where('product_id', $productId)->firstOrFail();
    }
}
