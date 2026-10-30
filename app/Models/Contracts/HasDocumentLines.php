<?php

namespace App\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document whose lines are rewritten through DocumentLines::write().
 */
interface HasDocumentLines
{
    /** @return HasMany<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model> */
    public function lines(): HasMany;
}
