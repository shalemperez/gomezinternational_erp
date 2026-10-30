<?php

namespace App\Http\Requests\Admin;

use App\Enums\NcfType;
use App\Models\NcfSequence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNcfSequenceRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        foreach (['expires_on', 'authorization_number', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ncf_type' => ['required', Rule::enum(NcfType::class)],
            'range_from' => ['required', 'integer', 'between:1,99999999'],
            'range_to' => ['required', 'integer', 'between:1,99999999', 'gte:range_from'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'authorization_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = NcfType::tryFrom($this->string('ncf_type')->toString());
            if ($type === null) {
                return;
            }
            if ($type->expires() && blank($this->input('expires_on'))) {
                $v->errors()->add('expires_on', 'Este tipo de comprobante requiere fecha de vencimiento (Norma General 06-2018, art. 6).');
            }
            if ($v->errors()->has('range_from') || $v->errors()->has('range_to')) {
                return;
            }
            $current = $this->route('ncfSequence');
            $currentId = $current instanceof NcfSequence ? $current->getKey() : null;
            $overlaps = NcfSequence::query()
                ->where('ncf_type', $type->value)
                ->where(fn ($query) => $query->where('range_from', '<=', $this->integer('range_to'))->where('range_to', '>=', $this->integer('range_from')))
                ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
                ->exists();
            if ($overlaps) {
                $v->errors()->add('range_from', 'El rango se solapa con otra secuencia del mismo tipo.');
            }
        });
    }
}
