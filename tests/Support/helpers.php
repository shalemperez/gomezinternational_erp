<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

/** Creates (or reuses) roles/permissions, creates a user with the given role and authenticates it. */
function actingAsRole(string $role, array $attributes = []): User
{
    if (! Role::query()->where('name', $role)->exists()) {
        (new PermissionSeeder)->run();
    }
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

function validBrandPayload(array $over = []): array
{
    return array_merge(['name' => 'Marca Nueva', 'is_active' => 'on'], $over);
}

function validCategoryPayload(array $over = []): array
{
    return array_merge(['name' => 'Categoría Nueva', 'parent_id' => '', 'is_active' => 'on'], $over);
}

function validUnitPayload(array $over = []): array
{
    return array_merge(['code' => 'DOC', 'name' => 'Docena', 'is_active' => 'on'], $over);
}

function validAdditionalTaxPayload(array $over = []): array
{
    return array_merge(['code' => 'ISC-ADV', 'name' => 'ISC ad valorem', 'calculation_type' => 'percent_of_base', 'rate' => '10', 'amount' => '', 'included_in_itbis_base' => 'on', 'dgii_code' => '', 'is_active' => 'on'], $over);
}

function validSalespersonPayload(array $over = []): array
{
    return array_merge(['code' => 'VEN-01', 'name' => 'Carlos Vendedor', 'email' => 'carlos@empresa.test', 'phone' => '809-555-0101', 'user_id' => '', 'is_active' => 'on'], $over);
}
