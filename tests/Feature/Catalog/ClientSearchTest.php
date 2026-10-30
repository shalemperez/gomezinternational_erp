<?php

use App\Models\Client;
use App\Models\Salesperson;

test('client search returns active visible clients by code, name or tax id', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    $own = Client::factory()->create(['salesperson_id' => $mine->id, 'business_name' => 'Distribuidora Norte', 'tax_id' => '101850043']);
    Client::factory()->create(['salesperson_id' => $mine->id, 'business_name' => 'Inactiva Norte', 'is_active' => false]);
    Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id, 'business_name' => 'Ajena Norte']);

    $this->getJson(route('catalog.clients.search', ['q' => 'norte']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.formatted_tax_id', '1-01-85004-3')->assertJsonPath('data.0.currency_code', 'DOP');
    $this->getJson(route('catalog.clients.search', ['q' => '1-01-85004']))->assertJsonCount(1, 'data');
    actingAsRole('management');
    $this->getJson(route('catalog.clients.search', ['q' => 'norte']))->assertJsonCount(2, 'data');
});
