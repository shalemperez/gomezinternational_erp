<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Concerns\ValidatesDominicanTaxId;
use App\Support\Countries;
use App\Support\FiscalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierRequest extends FormRequest
{
    use ValidatesDominicanTaxId;

    /** @var list<string> */
    protected array $booleans = ['is_active', 'tax_id_override'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }

        $taxId = FiscalId::normalize((string) $this->input('tax_id'));
        $foreignTaxId = trim((string) $this->input('foreign_tax_id'));
        $email = $this->input('email');

        $this->merge([
            'tax_id' => $taxId === '' ? null : $taxId,
            'foreign_tax_id' => $foreignTaxId === '' ? null : $foreignTaxId,
            'email' => is_string($email) && $email !== '' ? strtolower($email) : null,
            'contacts' => $this->input('contacts', []),
        ]);

        foreach (['address', 'phone', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash', 'unique:suppliers,code'],
            'name' => ['required', 'string', 'max:200'],
            'country_code' => ['required', Rule::in(array_keys(Countries::OPTIONS))],
            'tax_id' => ['nullable', 'string'],
            'foreign_tax_id' => ['nullable', 'string', 'max:50'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'credit_days' => ['required', 'integer', 'between:0,365'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.position' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'tax_id_override' => ['boolean'],
            'tax_id_override_reason' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge($this->taxIdMessages(), [
            'contacts.*.id.exists' => 'El contacto no pertenece a este registro.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        // Suppliers have no explicit type: 9 digits = RNC, 11 digits = cédula (derived here for the shared trait).
        $taxId = (string) $this->input('tax_id');
        $this->merge(['tax_id_type' => strlen($taxId) === 11 ? 'cedula' : 'rnc']);
        $this->addTaxIdValidation($validator, 'suppliers', $this->route('supplier'), 'tax_id', 'tax_id_type', 'country_code');
        $validator->after(function (Validator $v): void {
            $primary = collect($this->array('contacts'))->filter(fn ($c) => filter_var($c['is_primary'] ?? false, FILTER_VALIDATE_BOOL))->count();
            if ($primary > 1) {
                $v->errors()->add('contacts', 'Solo un contacto puede ser el principal.');
            }
        });
    }
}
