<?php

namespace App\Http\Requests\Sales;

use App\Models\Proforma;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InvoiceFromProformaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $proforma = $this->route('proforma');
            $quantities = $this->input('quantities');
            if (! $proforma instanceof Proforma || ! is_array($quantities) || $validator->errors()->has('quantities')) {
                return;
            }

            $anyPositive = false;
            foreach ($quantities as $lineId => $quantity) {
                $line = $proforma->lines->firstWhere('id', (int) $lineId);
                if ($line === null) {
                    $validator->errors()->add('quantities', 'Línea inválida.');

                    return;
                }
                $remaining = $line->pendingQuantity();
                if (Decimal::cmp((string) $quantity, $remaining) > 0) {
                    $validator->errors()->add("quantities.{$lineId}", "Cantidad pendiente: {$remaining}.");
                }
                $anyPositive = $anyPositive || Decimal::cmp((string) $quantity, '0') > 0;
            }

            if (! $anyPositive) {
                $validator->errors()->add('quantities', 'Indica al menos una cantidad a facturar.');
            }
        });
    }
}
