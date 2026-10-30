<?php

namespace App\Http\Requests\Admin;

use App\Models\EcfAnnulmentRange;
use App\Models\NcfSequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Validator;

class UpdateNcfSequenceRequest extends StoreNcfSequenceRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $sequence = $this->route('ncfSequence');
        if ($sequence instanceof NcfSequence) {
            $this->merge(['environment' => $sequence->environment]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $v): void {
            $sequence = $this->route('ncfSequence');
            if (! $sequence instanceof NcfSequence || $sequence->next_number <= $sequence->range_from) {
                return;
            }
            if ($this->string('ncf_type')->toString() !== $sequence->ncf_type->value) {
                $v->errors()->add('ncf_type', 'No se puede cambiar el tipo de una secuencia usada.');
            }
            if ($this->integer('range_from') !== $sequence->range_from) {
                $v->errors()->add('range_from', 'No se puede cambiar el inicio de una secuencia usada.');
            }
            $lastIssued = $sequence->next_number - 1;
            if ($this->integer('range_to') < $lastIssued) {
                $v->errors()->add('range_to', "La secuencia ya emitió hasta el número {$lastIssued}.");
            }
        });

        $validator->after(function (Validator $v): void {
            $sequence = $this->route('ncfSequence');
            if (! $sequence instanceof NcfSequence || $this->integer('range_to') <= (int) $sequence->range_to) {
                return;
            }

            $tailUnderAnnulment = EcfAnnulmentRange::query()->where('ncf_sequence_id', $sequence->id)
                ->where(fn (Builder $query) => $query->whereNull('ecf_annulment_id')
                    ->orWhereHas('annulment', fn (Builder $annulment) => $annulment->where('status', 'accepted')))
                ->exists();
            if ($tailUnderAnnulment) {
                $v->errors()->add('range_to', 'El final de esta secuencia está en anulación ante la DGII; no se puede ampliar.');
            }
        });
    }
}
