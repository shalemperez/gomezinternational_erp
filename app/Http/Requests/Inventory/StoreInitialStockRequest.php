<?php

namespace App\Http\Requests\Inventory;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInitialStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:50'],
            'lines.*.lot_expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->array('lines') as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $tracksLots = Product::query()->whereKey($line['product_id'] ?? 0)->value('tracks_lots');
                if ($tracksLots && blank($line['lot_code'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.lot_code", 'El producto controla lotes: indica el lote.');
                }
            }
        });
    }
}
