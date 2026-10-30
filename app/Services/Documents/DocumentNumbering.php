<?php

namespace App\Services\Documents;

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumbering
{
    public function next(DocumentKind $kind): string
    {
        return DB::transaction(function () use ($kind): string {
            $sequence = DocumentSequence::query()->where('kind', $kind->value)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return sprintf('%s-%06d', $sequence->prefix, $number);
        });
    }
}
