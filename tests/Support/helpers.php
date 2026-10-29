<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;

/** Creates (or reuses) roles/permissions, creates a user with the given role and authenticates it. */
function actingAsRole(string $role, array $attributes = []): User
{
    (new PermissionSeeder)->run();
    $user = User::factory()->create($attributes);
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function validUserPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Luis Mena',
        'email' => 'luis@empresa.test',
        'roles' => ['sales'],
    ], $over);
}

function validCurrencyPayload(array $over = []): array
{
    return array_merge(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => 'on'], $over);
}
