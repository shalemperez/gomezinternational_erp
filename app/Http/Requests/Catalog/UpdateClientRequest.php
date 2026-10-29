<?php

namespace App\Http\Requests\Catalog;

use App\Models\Client;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends StoreClientRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && (bool) $this->user()?->can('update', $client);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('clients', 'code')->ignore($this->route('client'))],
        ]);
    }
}
