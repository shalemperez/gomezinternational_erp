<?php

namespace App\Http\Requests\Catalog;

use App\Models\AdditionalTax;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['tracks_lots', 'is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        foreach (['barcode', 'brand_id', 'category_id', 'weight_kg', 'volume_liters', 'alcohol_degree', 'tariff_code', 'description'] as $field) {
            if (in_array($this->input($field), ['', 'none'], true)) {
                $this->merge([$field => null]);
            }
        }
        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku'))),
            'minimum_stock' => $this->filled('minimum_stock') ? $this->input('minimum_stock') : '0',
            'additional_tax_ids' => $this->input('additional_tax_ids') ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'unit_of_measure_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'units_per_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_liters' => ['nullable', 'numeric', 'min:0'],
            'alcohol_degree' => ['nullable', 'numeric', 'between:0,100'],
            'itbis_rate' => ['required', Rule::in(['18.00', '16.00', '0.00'])],
            'tracks_lots' => ['boolean'],
            'tariff_code' => ['nullable', 'string', 'max:20'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'additional_tax_ids' => ['nullable', 'array'],
            'additional_tax_ids.*' => ['integer', 'exists:additional_taxes,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ids = $this->input('additional_tax_ids', []);
            if (! is_array($ids) || $ids === []) {
                return;
            }
            $types = AdditionalTax::query()->whereIn('id', $ids)->pluck('calculation_type')->map(fn ($t) => $t->value)->all();
            $volume = $this->input('volume_liters');
            $degree = $this->input('alcohol_degree');
            if (array_intersect($types, ['fixed_per_liter', 'fixed_per_degree_liter']) !== [] && ($volume === null || $volume === '')) {
                $v->errors()->add('additional_tax_ids', 'Los impuestos por litro requieren el volumen en litros del producto.');
            }
            if (in_array('fixed_per_degree_liter', $types, true) && ($degree === null || $degree === '')) {
                $v->errors()->add('additional_tax_ids', 'Los impuestos por grado alcohólico requieren el grado alcohólico del producto.');
            }
        });
    }
}
