<?php

use App\Models\Client;
use App\Models\Salesperson;
use Spatie\Activitylog\Models\Activity;

crudResourceTests([
    'label' => 'clients', 'routePrefix' => 'catalog.clients', 'component' => 'catalog/clients/index', 'model' => Client::class, 'table' => 'clients', 'routeParam' => 'client',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'warehouse', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validClientPayload(['tax_id' => fakeRnc()]), 'required' => ['business_name', 'tax_id_type', 'taxpayer_type', 'credit_days', 'credit_limit', 'currency_id'], 'assertColumn' => 'business_name',
    'update' => ['field' => 'business_name', 'value' => 'Razón social editada'], 'exportTable' => 'clients',
    'created' => 'Cliente creado', 'updated' => 'Cliente actualizado', 'deleted' => 'Cliente eliminado',
]);

test('client tax id is stored normalised', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '1-01-85004-3']))->assertRedirect(route('catalog.clients.index'));
    $client = Client::where('business_name', 'Distribuidora Ejemplo SRL')->firstOrFail();
    expect($client->tax_id)->toBe('101850043')->and($client->formatted_tax_id)->toBe('1-01-85004-3');
});

test('invalid rnc, duplicate tax id and juridica without rnc are rejected', function () {
    actingAsRole('owner');
    Client::factory()->create(['tax_id' => '101850043']);
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '101850042']))->assertSessionHasErrors(['tax_id' => 'El RNC debe tener 9 dígitos y un dígito verificador válido.']);
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '101850043']))->assertSessionHasErrors(['tax_id']);
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id_type' => 'cedula', 'tax_id' => '00113918205', 'taxpayer_type' => 'juridica']))
        ->assertSessionHasErrors(['tax_id_type' => 'Una persona jurídica debe identificarse con RNC.']);
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id_type' => 'none', 'tax_id' => '', 'taxpayer_type' => 'consumidor_final']))->assertRedirect(route('catalog.clients.index'));
});

test('code is generated when blank', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc()]));
    expect(Client::latest('id')->first()->code)->toMatch('/^CLI-\d{5}$/');
});

test('contacts are saved and replaced on update with audit rows', function () {
    actingAsRole('owner');
    $contacts = [['name' => 'Ana', 'position' => 'Compras', 'email' => 'ana@ejemplo.test', 'phone' => '', 'is_primary' => '1']];
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'contacts' => $contacts]))->assertRedirect(route('catalog.clients.index'));
    $client = Client::latest('id')->firstOrFail();
    expect($client->contacts)->toHaveCount(1)->and($client->contacts->first()->is_primary)->toBeTrue();
    $firstId = $client->contacts->first()->id;

    $this->put(route('catalog.clients.update', $client), validClientPayload(['tax_id' => $client->tax_id, 'contacts' => [['name' => 'Beto', 'position' => '', 'email' => '', 'phone' => '809-1', 'is_primary' => '0']]]))->assertRedirect(route('catalog.clients.index'));
    expect($client->fresh()->contacts->pluck('name')->all())->toBe(['Beto'])
        ->and(Activity::inLog('client_contacts')->where('event', 'deleted')->where('subject_id', $firstId)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'created')->count())->toBe(2);
});

test('sales user sees and edits only own clients and new clients are assigned to them', function () {
    $user = actingAsRole('sales');
    $mine = Salesperson::factory()->create(['user_id' => $user->id]);
    $other = Salesperson::factory()->create();
    $own = Client::factory()->create(['salesperson_id' => $mine->id]);
    $foreign = Client::factory()->create(['salesperson_id' => $other->id]);

    $this->get(route('catalog.clients.index'))->assertInertia(fn ($p) => $p->where('rows.total', 1)->where('rows.data.0.id', $own->id));
    $this->get(route('catalog.clients.edit', $foreign))->assertForbidden();
    $this->put(route('catalog.clients.update', $foreign), validClientPayload(['tax_id' => $foreign->tax_id, 'business_name' => 'Hackeado SRL']))->assertForbidden();
    $this->delete(route('catalog.clients.destroy', $foreign))->assertForbidden();
    expect($foreign->fresh())->not->toBeNull()->and($foreign->fresh()->business_name)->not->toBe('Hackeado SRL');
    $this->get(route('catalog.clients.index', ['search' => $foreign->business_name]))->assertInertia(fn ($p) => $p->where('rows.total', 0));
    $this->get(route('catalog.clients.edit', $own))->assertOk();
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'salesperson_id' => $other->id]))->assertRedirect(route('catalog.clients.index'));
    expect(Client::latest('id')->first()->salesperson_id)->toBe($mine->id);
    $this->get(route('catalog.clients.export', ['format' => 'csv']));
    expect(Activity::inLog('exports')->latest('id')->first()->properties['row_count'])->toBe(2);
});

test('sales user without salesperson link cannot create clients', function () {
    actingAsRole('sales');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc()]))->assertSessionHasErrors(['salesperson_id' => 'Tu usuario no está vinculado a un vendedor.']);
});

test('owner can force an invalid rnc with a reason and it is audited', function () {
    $owner = actingAsRole('owner');
    $payload = validClientPayload(['tax_id' => '101850042', 'tax_id_override' => 'on', 'tax_id_override_reason' => '']);
    $this->post(route('catalog.clients.store'), $payload)->assertSessionHasErrors(['tax_id_override_reason' => 'Indica el motivo para forzar la identificación fiscal.']);
    $this->post(route('catalog.clients.store'), array_merge($payload, ['tax_id_override_reason' => 'RNC confirmado con la DGII; número antiguo']))->assertRedirect(route('catalog.clients.index'));

    $c = Client::where('tax_id', '101850042')->firstOrFail();
    expect($c->tax_id_validation_overridden)->toBeTrue()->and($c->tax_id_override_by)->toBe($owner->id)->and($c->tax_id_override_at)->not->toBeNull()
        ->and(Activity::inLog('clients')->where('event', 'tax_id_validation_overridden')->where('subject_id', $c->id)->where('properties->reason', 'RNC confirmado con la DGII; número antiguo')->exists())->toBeTrue();
});

test('accounting cannot force an invalid rnc', function () {
    actingAsRole('accounting');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '101850042']))->assertSessionHasErrors(['tax_id']);
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '101850042', 'tax_id_override' => 'on', 'tax_id_override_reason' => 'x']))->assertForbidden();
});

test('override still requires the right number of digits', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => '12345', 'tax_id_override' => 'on', 'tax_id_override_reason' => 'motivo']))->assertSessionHasErrors(['tax_id']);
});

test('clearing the override resets the fields', function () {
    $owner = actingAsRole('owner');
    $c = Client::factory()->create(['tax_id' => '101850042', 'tax_id_validation_overridden' => true, 'tax_id_override_reason' => 'x', 'tax_id_override_by' => $owner->id, 'tax_id_override_at' => now()]);
    $this->put(route('catalog.clients.update', $c), validClientPayload(['tax_id' => '101850043']))->assertRedirect(route('catalog.clients.index'));
    $c->refresh();
    expect($c->tax_id)->toBe('101850043')->and($c->tax_id_validation_overridden)->toBeFalse()->and($c->tax_id_override_reason)->toBeNull()->and($c->tax_id_override_by)->toBeNull()->and($c->tax_id_override_at)->toBeNull();
});

test('a client created without code gets CLI code from its id and an update with blank code keeps it', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['code' => '']))->assertRedirect();
    $client = Client::query()->latest('id')->firstOrFail();
    expect($client->code)->toBe('CLI-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT));

    $this->put(route('catalog.clients.update', $client), validClientPayload(['code' => '', 'name' => 'Nombre Nuevo', 'tax_id' => $client->tax_id]))->assertRedirect();
    expect($client->fresh()->code)->toBe('CLI-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT));
});

test('editing an overridden client without changing its tax id keeps the override', function () {
    $owner = actingAsRole('owner');
    $c = Client::factory()->create(['tax_id' => '101850042', 'tax_id_type' => 'rnc', 'tax_id_validation_overridden' => true, 'tax_id_override_reason' => 'x', 'tax_id_override_by' => $owner->id, 'tax_id_override_at' => now()]);

    $this->put(route('catalog.clients.update', $c), validClientPayload(['tax_id' => '101850042', 'business_name' => 'Nombre Editado SRL']))->assertRedirect(route('catalog.clients.index'));
    $c->refresh();
    expect($c->business_name)->toBe('Nombre Editado SRL')->and($c->tax_id_validation_overridden)->toBeTrue()->and($c->tax_id_override_reason)->toBe('x');
});
