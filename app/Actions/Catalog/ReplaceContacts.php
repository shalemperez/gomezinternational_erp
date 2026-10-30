<?php

namespace App\Actions\Catalog;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplaceContacts
{
    /**
     * Syncs the contacts of a parent: updates rows by id, creates rows without id, deletes rows that are missing (all through the models, so they are audited).
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     * @template TParent of \Illuminate\Database\Eloquent\Model
     *
     * @param  HasMany<TRelated, TParent>  $relation
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function handle(HasMany $relation, array $contacts): void
    {
        $existing = $relation->get()->keyBy(fn ($contact) => (int) $contact->getKey());
        $keep = [];
        foreach ($contacts as $contact) {
            $attributes = [
                'name' => $contact['name'],
                'position' => $contact['position'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'is_primary' => filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOL),
            ];
            $id = isset($contact['id']) && $contact['id'] !== '' ? (int) $contact['id'] : null;
            if ($id !== null && isset($existing[$id])) {
                $existing[$id]->fill($attributes)->save(); // no-op (and no audit row) when nothing changed
                $keep[] = $id;

                continue;
            }
            $keep[] = (int) $relation->create($attributes)->getKey();
        }
        foreach ($existing as $id => $contact) {
            if (! in_array($id, $keep, true)) {
                $contact->delete();
            }
        }
    }
}
