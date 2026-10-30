<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableDocumentException;

/**
 * Models override `mutableWhenIssued()` (columns that may still change after issue) and, when the
 * issue timestamp is not `issued_at`, `issuedAtColumn()`. Methods (not properties) because PHP forbids
 * redeclaring a trait property with a different default value.
 */
trait ImmutableWhenIssued
{
    public static function bootImmutableWhenIssued(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal($model->issuedAtColumn()) === null) {
                return; // the transition that sets issued_at is allowed
            }
            $dirty = array_keys($model->getDirty());
            $allowed = [...$model->mutableWhenIssued(), 'updated_at'];
            if (array_diff($dirty, $allowed) !== []) {
                throw new ImmutableDocumentException;
            }
        });

        static::deleting(function (self $model): void {
            if ($model->getOriginal($model->issuedAtColumn()) !== null) {
                throw new ImmutableDocumentException;
            }
        });
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return [];
    }

    protected function issuedAtColumn(): string
    {
        return 'issued_at';
    }
}
