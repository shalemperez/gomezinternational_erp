<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
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
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:brands,name'],
            'is_active' => ['boolean'],
        ];
    }
}
