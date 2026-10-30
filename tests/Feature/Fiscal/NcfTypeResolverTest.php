<?php

use App\Enums\NcfType;
use App\Models\Client;
use App\Services\Fiscal\NcfTypeResolver;

test('resolves the ncf type from the client taxpayer type', function () {
    $r = app(NcfTypeResolver::class);
    expect($r->forSale(Client::factory()->create(['taxpayer_type' => 'juridica', 'tax_id_type' => 'rnc'])))->toBe(NcfType::B01)
        ->and($r->forSale(Client::factory()->create(['taxpayer_type' => 'fisica', 'tax_id_type' => 'cedula', 'tax_id' => '00113918205'])))->toBe(NcfType::B01)
        ->and($r->forSale(Client::factory()->create(['taxpayer_type' => 'consumidor_final', 'tax_id_type' => 'none', 'tax_id' => null])))->toBe(NcfType::B02)
        ->and($r->forSale(Client::factory()->create(['taxpayer_type' => 'extranjero', 'tax_id_type' => 'none', 'tax_id' => null])))->toBe(NcfType::B02)
        ->and($r->forSale(Client::factory()->create(['taxpayer_type' => 'regimen_especial'])))->toBe(NcfType::B14)
        ->and($r->forSale(Client::factory()->create(['taxpayer_type' => 'gubernamental'])))->toBe(NcfType::B15)
        ->and($r->forCreditNote())->toBe(NcfType::B04)->and($r->forDebitNote())->toBe(NcfType::B03);
});

test('a persona fisica without tax id gets a consumo invoice', function () {
    expect(app(NcfTypeResolver::class)->forSale(Client::factory()->create(['taxpayer_type' => 'fisica', 'tax_id_type' => 'none', 'tax_id' => null])))->toBe(NcfType::B02);
});
