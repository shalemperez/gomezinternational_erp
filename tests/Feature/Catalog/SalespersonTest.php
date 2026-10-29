<?php

use App\Models\Salesperson;
use App\Models\User;

crudResourceTests([
    'label' => 'salespeople', 'routePrefix' => 'catalog.salespeople', 'component' => 'catalog/salespeople/index', 'model' => Salesperson::class, 'table' => 'salespeople', 'routeParam' => 'salesperson',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'warehouse', 'deniedStoreRole' => 'sales',
    'payload' => fn () => validSalespersonPayload(), 'required' => ['code', 'name'], 'assertColumn' => 'code',
    'update' => ['field' => 'name', 'value' => 'Carlos Editado'], 'exportTable' => 'salespeople',
    'created' => 'Vendedor creado', 'updated' => 'Vendedor actualizado', 'deleted' => 'Vendedor eliminado',
]);

test('a user can be linked to only one salesperson', function () {
    actingAsRole('owner');
    $user = User::factory()->create();
    $this->post(route('catalog.salespeople.store'), validSalespersonPayload(['user_id' => $user->id]))->assertRedirect(route('catalog.salespeople.index'));
    $this->post(route('catalog.salespeople.store'), validSalespersonPayload(['code' => 'VEN-02', 'user_id' => $user->id]))->assertSessionHasErrors(['user_id']);
    expect($user->fresh()->salesperson->code)->toBe('VEN-01');
});
