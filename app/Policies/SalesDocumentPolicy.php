<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class SalesDocumentPolicy
{
    abstract protected function viewAllPermission(): string;

    public function view(User $user, Model $document): bool
    {
        return $this->owns($user, $document);
    }

    public function update(User $user, Model $document): bool
    {
        return $this->owns($user, $document);
    }

    public function delete(User $user, Model $document): bool
    {
        return $this->owns($user, $document);
    }

    private function owns(User $user, Model $document): bool
    {
        return $user->can($this->viewAllPermission()) || ($user->salesperson !== null && $user->salesperson->id === $document->getAttribute('salesperson_id'));
    }
}
