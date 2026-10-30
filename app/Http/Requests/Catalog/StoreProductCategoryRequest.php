<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductCategoryRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        $this->merge([
            'parent_id' => in_array($this->input('parent_id'), ['', 'none', null], true) ? null : $this->input('parent_id'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:product_categories,name'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'is_active' => ['boolean'],
        ];
    }
}
