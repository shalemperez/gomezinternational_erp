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
            'is_default' => ['boolean', 'declined_if:is_active,false'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'is_default.declined_if' => 'La lista predeterminada debe estar activa.',
        ];
    }
}
