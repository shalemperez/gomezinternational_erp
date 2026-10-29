<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitOfMeasureRequest extends FormRequest
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
            'code' => strtoupper(trim((string) $this->input('code'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10', 'alpha_num', 'unique:units_of_measure,code'],
            'name' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }
}
