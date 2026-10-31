<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\NcfType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEcfAnnulmentRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('fiscal.ecf.annul') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxSequence = NcfType::E31->maxSequence();

        return [
            'ncf_type' => ['required', Rule::in(collect(NcfType::cases())->filter(fn (NcfType $type) => $type->isElectronic())->map(fn (NcfType $type) => $type->value)->all())],
            'range_from' => ['required', 'integer', 'min:1', "max:{$maxSequence}"],
            'range_to' => ['required', 'integer', 'min:1', "max:{$maxSequence}", 'gte:range_from'],
            'reason' => ['required', 'string', 'max:100'],
        ];
    }
}
