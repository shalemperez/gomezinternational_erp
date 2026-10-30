<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class UpdateSalespersonRequest extends StoreSalespersonRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('salespeople', 'code')->ignore($this->route('salesperson'))],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('salespeople', 'user_id')->ignore($this->route('salesperson'))],
            'is_active' => ['boolean'],
        ];
    }
}
