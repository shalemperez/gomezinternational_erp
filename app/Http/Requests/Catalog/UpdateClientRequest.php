<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class UpdateClientRequest extends StoreClientRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('clients', 'code')->ignore($this->route('client'))],
        ]);
    }
}
