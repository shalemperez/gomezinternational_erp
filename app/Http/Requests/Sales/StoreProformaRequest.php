<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Sales\Concerns\ValidatesDocumentLines;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProformaRequest extends FormRequest
{
    use ValidatesDocumentLines;

    public function authorize(): bool
    {
        $proforma = $this->route('proforma');

        return $proforma === null || ($this->user()?->can('update', $proforma) ?? false);
    }

    protected function prepareForValidation(): void
    {
        foreach (['salesperson_id', 'price_list_id', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'salesperson_id' => ['nullable', 'integer', Rule::exists('salespeople', 'id')->where('is_active', true)],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->lineRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.exists' => 'El cliente no existe o está inactivo.',
            'salesperson_id.exists' => 'El vendedor no existe o está inactivo.',
            'currency_id.exists' => 'La moneda no existe o está inactiva.',
            'price_list_id.exists' => 'La lista de precios no existe o está inactiva.',
            'valid_until.after_or_equal' => 'La validez no puede ser anterior a la fecha de emisión.',
            ...$this->lineMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientId = $this->integer('client_id');
            $user = $this->user();
            if ($clientId > 0 && ! $validator->errors()->has('client_id') && $user instanceof User && ! Client::query()->visibleTo($user)->whereKey($clientId)->exists()) {
                $validator->errors()->add('client_id', 'No puedes crear documentos para clientes de otros vendedores.');
            }
        });
    }
}
