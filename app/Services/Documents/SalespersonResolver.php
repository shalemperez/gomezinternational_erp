<?php

namespace App\Services\Documents;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class SalespersonResolver
{
    /**
     * Users with the view-all permission choose the salesperson; everyone else is bound to their own.
     */
    public static function resolve(User $user, ?int $requested, string $viewAllPermission): int
    {
        if ($user->can($viewAllPermission)) {
            return $requested ?? throw ValidationException::withMessages(['salesperson_id' => 'Selecciona el vendedor.']);
        }

        $salesperson = $user->salesperson ?? throw ValidationException::withMessages(['salesperson_id' => 'Tu usuario no está vinculado a un vendedor.']);

        return $salesperson->id;
    }
}
