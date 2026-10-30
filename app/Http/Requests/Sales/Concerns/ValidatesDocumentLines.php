<?php

namespace App\Http\Requests\Sales\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesDocumentLines
{
    /** @return array<string, mixed> */
    protected function lineRules(int $max = 200): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', "max:{$max}"],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.itbis_rate' => ['nullable', Rule::in(['0.00', '16.00', '18.00'])],
        ];
    }

    /** @return array<string, string> */
    protected function lineMessages(): array
    {
        return [
            'lines.required' => 'Agrega al menos una línea.',
            'lines.*.product_id.exists' => 'El producto no existe o está inactivo.',
            'lines.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
