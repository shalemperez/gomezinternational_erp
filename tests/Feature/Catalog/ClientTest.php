<?php

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\PriceList;
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

test('contacts are synced by id: updated, created and deleted with audit rows', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'contacts' => [
        ['name' => 'Ana', 'position' => 'Compras', 'email' => 'ana@ejemplo.test', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'Beto', 'position' => '', 'email' => '', 'phone' => '809-1', 'is_primary' => '0'],
    ]]))->assertRedirect(route('catalog.clients.index'));
    $client = Client::latest('id')->firstOrFail();
    [$ana, $beto] = $client->contacts()->orderBy('id')->get()->all();

    $this->put(route('catalog.clients.update', $client), validClientPayload(['tax_id' => $client->tax_id, 'contacts' => [
        ['id' => $ana->id, 'name' => 'Ana María', 'position' => 'Compras', 'email' => 'ana@ejemplo.test', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'Carla', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0'],
    ]]))->assertRedirect(route('catalog.clients.index'));

    expect($client->fresh()->contacts()->orderBy('id')->pluck('name')->all())->toBe(['Ana María', 'Carla'])
        ->and(ClientContact::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'updated')->where('subject_id', $ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'deleted')->where('subject_id', $beto->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'created')->count())->toBe(3)
        ->and(Activity::inLog('client_contacts')->where('event', 'deleted')->count())->toBe(1);
});

test('at most one contact can be primary and foreign contact ids are rejected', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'contacts' => [
        ['name' => 'A', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'B', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'],
    ]]))->assertSessionHasErrors(['contacts' => 'Solo un contacto puede ser el principal.']);

    $other = Client::factory()->create();
    $foreign = ClientContact::factory()->create(['client_id' => $other->id]);
    $mine = Client::factory()->create();
    $this->put(route('catalog.clients.update', $mine), validClientPayload(['tax_id' => $mine->tax_id, 'contacts' => [['id' => $foreign->id, 'name' => 'X', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0']]]))
        ->assertSessionHasErrors(['contacts.0.id']);
    expect($foreign->fresh()->client_id)->toBe($other->id);
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

test('edit form keeps the current inactive salesperson and price list as options', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create(['is_active' => false]);
    $pl = PriceList::factory()->create(['is_active' => false]);
    $client = Client::factory()->create(['salesperson_id' => $sp->id, 'price_list_id' => $pl->id]);
    $this->get(route('catalog.clients.edit', $client))->assertInertia(fn ($p) => $p->where('salespeople.0.id', $sp->id)->where('priceLists.0.id', $pl->id));
    $this->get(route('catalog.clients.create'))->assertInertia(fn ($p) => $p->where('salespeople', [])->where('priceLists', []));
});
