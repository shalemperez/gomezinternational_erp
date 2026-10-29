<?php

namespace App\Actions\Catalog;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplaceContacts
{
    /**
     * Deletes the current contacts through the models (so they are audited) and creates the given ones.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     * @template TParent of \Illuminate\Database\Eloquent\Model
     *
     * @param  HasMany<TRelated, TParent>  $relation
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function handle(HasMany $relation, array $contacts): void
    {
        $relation->get()->each->delete();
        foreach ($contacts as $contact) {
            $relation->create([
                'name' => $contact['name'],
                'position' => $contact['position'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'is_primary' => filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOL),
            ]);
        }
    }
}
