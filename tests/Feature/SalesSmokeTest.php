<?php

use App\Support\PermissionCatalog;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;

test('every phase 2 index page renders for the owner', function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
    actingAsRole('owner');
    foreach (['sales.proformas.index', 'sales.invoices.index', 'sales.credit-notes.index', 'sales.debit-notes.index', 'sales.receipts.index', 'sales.statements.index', 'inventory.stock.index', 'inventory.movements.index', 'inventory.lots.index', 'inventory.dispatches.index', 'inventory.initial-stock.create', 'admin.ncf-sequences.index', 'admin.email-templates.index', 'sales.proformas.create', 'sales.invoices.create', 'sales.receipts.create', 'sales.invoices.historical.create'] as $name) {
        $this->get(route($name))->assertOk();
    }
});

test('every permission referenced by the navigation exists in the catalog', function () {
    preg_match_all("/permission: '([a-z0-9.-]+)'/", (string) file_get_contents(resource_path('js/nav.ts')), $m);
    expect($m[1])->not->toBeEmpty()->and(array_diff(array_unique($m[1]), PermissionCatalog::names()))->toBe([]);
});
