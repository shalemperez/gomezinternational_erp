<?php

namespace App\Http\Requests\Admin;

use App\Models\NcfSequence;
use Illuminate\Validation\Validator;

class UpdateNcfSequenceRequest extends StoreNcfSequenceRequest
{
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
    }
}
