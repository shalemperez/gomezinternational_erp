<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class UpdateUnitOfMeasureRequest extends StoreUnitOfMeasureRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10', 'alpha_num', Rule::unique('units_of_measure', 'code')->ignore($this->route('unit'))],
            'name' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }
}
