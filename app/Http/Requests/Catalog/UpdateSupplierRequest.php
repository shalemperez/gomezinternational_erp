<?php

namespace App\Http\Requests\Catalog;

use App\Models\Supplier;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier && (bool) $this->user()?->can('catalog.suppliers.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplier = $this->route('supplier');

        return array_merge(parent::rules(), [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('suppliers', 'code')->ignore($this->route('supplier'))],
            'contacts.*.id' => ['nullable', 'integer', Rule::exists('supplier_contacts', 'id')->where('supplier_id', $supplier instanceof Supplier ? $supplier->getKey() : 0)],
        ]);
    }
}
