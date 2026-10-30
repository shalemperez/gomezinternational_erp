<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.receipts.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_received' => ['nullable', 'numeric'],
            'retention_itbis' => ['nullable', 'numeric'],
            'retention_isr' => ['nullable', 'numeric'],
            'applications' => ['present', 'array', 'max:100'],
            'applications.*.amount' => ['nullable', 'numeric'],
        ];
    }
}
