<?php

namespace App\Http\Requests\Inventory;

use App\Models\Warehouse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('warehouses', 'code')->ignore($this->route('warehouse'))],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $warehouse = $this->route('warehouse');
            if ($warehouse instanceof Warehouse && $warehouse->is_default && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'El almacén predeterminado no se puede desactivar.');
            }
            if ($warehouse instanceof Warehouse && $warehouse->is_default && ! $this->boolean('is_default')) {
                $validator->errors()->add('is_default', 'Debe existir un almacén predeterminado: marca otro almacén como predeterminado.');
            }
        });
    }
}
