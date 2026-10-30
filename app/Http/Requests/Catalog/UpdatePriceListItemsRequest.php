<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePriceListItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:5000'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }
            $ids = array_values(array_filter(array_map(fn ($i) => isset($i['product_id']) ? (int) $i['product_id'] : null, $items)));
            $known = Product::query()->whereIn('id', array_unique($ids))->pluck('id')->flip();
            $seen = [];
            foreach ($items as $index => $item) {
                $id = (int) ($item['product_id'] ?? 0);
                if (! isset($known[$id])) {
                    $v->errors()->add("items.$index.product_id", 'El producto no existe.');
                } elseif (isset($seen[$id])) {
                    $v->errors()->add("items.$index.product_id", 'Producto repetido en la lista.');
                }
                $seen[$id] = true;
            }
        });
    }
}
