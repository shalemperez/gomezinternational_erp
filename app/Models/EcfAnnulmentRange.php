<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcfAnnulmentRange extends Model
{
    protected $fillable = ['ecf_annulment_id', 'ncf_type', 'environment', 'range_from', 'range_to', 'quantity', 'reason', 'ecf_document_id'];

    /** @return BelongsTo<EcfAnnulment, $this> */
    public function annulment(): BelongsTo
    {
        return $this->belongsTo(EcfAnnulment::class, 'ecf_annulment_id');
    }

    /** @return BelongsTo<EcfDocument, $this> */
    public function ecfDocument(): BelongsTo
    {
        return $this->belongsTo(EcfDocument::class, 'ecf_document_id');
    }
}
