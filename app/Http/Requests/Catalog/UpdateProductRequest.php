<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'unit_of_measure_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'units_per_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999.9999', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'volume_liters' => ['nullable', 'numeric', 'min:0', 'max:999999.9999', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'alcohol_degree' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'itbis_rate' => ['required', Rule::in(['18.00', '16.00', '0.00'])],
            'tracks_lots' => ['boolean'],
            'tariff_code' => ['nullable', 'string', 'max:20'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'additional_tax_ids' => ['nullable', 'array'],
            'additional_tax_ids.*' => ['integer', 'exists:additional_taxes,id'],
            'is_active' => ['boolean'],
        ];
    }
}
