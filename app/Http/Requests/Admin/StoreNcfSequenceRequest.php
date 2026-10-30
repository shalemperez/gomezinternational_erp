<?php

namespace App\Http\Requests\Admin;

use App\Enums\NcfType;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Services\Fiscal\FiscalNumberingService;
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
        $type = NcfType::tryFrom($this->string('ncf_type')->toString());
        $this->merge(['environment' => $type?->isElectronic() ? app(FiscalNumberingService::class)->environment() : 'prod']);
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
            'environment' => ['required', Rule::in(['test', 'cert', 'prod'])],
            'range_from' => ['required', 'integer', 'min:1'],
            'range_to' => ['required', 'integer', 'min:1', 'gte:range_from'],
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
            foreach (['range_from', 'range_to'] as $field) {
                if ($this->integer($field) > $type->maxSequence()) {
                    $v->errors()->add($field, "El rango de {$type->value} admite hasta {$type->sequenceDigits()} dígitos.");
                }
            }
            if ($v->errors()->has('range_from') || $v->errors()->has('range_to')) {
                return;
            }
            $current = $this->route('ncfSequence');
            $currentId = $current instanceof NcfSequence ? $current->getKey() : null;
            $overlaps = NcfSequence::query()
                ->where('ncf_type', $type->value)
                ->where('environment', $this->string('environment')->toString())
                ->where(fn ($query) => $query->where('range_from', '<=', $this->integer('range_to'))->where('range_to', '>=', $this->integer('range_from')))
                ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
                ->exists();
            if ($overlaps) {
                $v->errors()->add('range_from', 'El rango se solapa con otra secuencia del mismo tipo.');
            }
            $used = $this->firstUsedNcfInRange($type, $this->integer('range_from'), $this->integer('range_to'), $currentId);
            if ($used !== null) {
                $v->errors()->add('range_from', "El rango incluye el NCF {$used}, ya emitido; inicia la secuencia después del último NCF usado.");
            }
        });
    }

    /** Lowest NCF of this type inside the range that was not issued by this sequence (historical, imported or another sequence). */
    private function firstUsedNcfInRange(NcfType $type, int $from, int $to, ?int $excludeSequenceId): ?string
    {
        $found = [];
        foreach ([Invoice::class, CreditNote::class, DebitNote::class] as $model) {
            $ncf = $model::query()->where('ncf_type', $type->value)
                ->whereRaw('CAST(SUBSTR(ncf, 4) AS BIGINT) BETWEEN ? AND ?', [$from, $to])
                ->when($excludeSequenceId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('ncf_sequence_id')->orWhere('ncf_sequence_id', '!=', $excludeSequenceId)))
                ->orderBy('ncf')->value('ncf');
            if ($ncf !== null) {
                $found[] = (string) $ncf;
            }
        }
        sort($found);

        return $found[0] ?? null;
    }
}
