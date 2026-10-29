<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StorePriceListRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['is_default', 'is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:price_lists,name'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
