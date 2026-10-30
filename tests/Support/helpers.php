<?php

use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\StockMovementType;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\FiscalId;
use App\Support\Inventory\StockLine;
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

function validProductPayload(array $over = []): array
{
    $unit = UnitOfMeasure::factory()->create();

    return array_merge([
        'sku' => 'SKU-TEST-001', 'barcode' => '', 'name' => 'Producto de prueba', 'brand_id' => '', 'category_id' => '',
        'unit_of_measure_id' => $unit->id, 'units_per_box' => 12, 'weight_kg' => '', 'volume_liters' => '', 'alcohol_degree' => '',
        'itbis_rate' => '18.00', 'tracks_lots' => '', 'tariff_code' => '', 'minimum_stock' => '0', 'description' => '', 'additional_tax_ids' => [], 'is_active' => 'on',
    ], $over);
}

function validPriceListPayload(array $over = []): array
{
    $currency = Currency::query()->firstOr(fn () => Currency::factory()->create(['code' => 'DOP', 'is_base' => true]));

    return array_merge(['name' => 'Lista de prueba', 'currency_id' => $currency->id, 'is_default' => '', 'is_active' => 'on'], $over);
}

function validClientPayload(array $over = []): array
{
    $currency = Currency::query()->where('is_base', true)->firstOr(fn () => Currency::factory()->create(['code' => 'DOP', 'is_base' => true]));

    return array_merge([
        'code' => '', 'business_name' => 'Distribuidora Ejemplo SRL', 'trade_name' => 'Ejemplo', 'tax_id_type' => 'rnc', 'tax_id' => '1-31-24679-6',
        'taxpayer_type' => 'juridica', 'address' => 'Av. Principal 1', 'city' => 'Santo Domingo', 'phone' => '809-555-0000', 'email' => 'ventas@ejemplo.test',
        'salesperson_id' => '', 'credit_days' => 30, 'credit_limit' => '100000', 'price_list_id' => '', 'currency_id' => $currency->id, 'notes' => '',
        'contacts' => [], 'tax_id_override' => '', 'tax_id_override_reason' => '', 'is_active' => 'on',
    ], $over);
}

/** A syntactically valid RNC whose first eight digits are random (starts with 1). */
function fakeRnc(): string
{
    $first = '1'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

    return $first.FiscalId::rncCheckDigit($first);
}

function validSupplierPayload(array $over = []): array
{
    $currency = Currency::query()->where('code', 'USD')->firstOr(fn () => Currency::factory()->create(['code' => 'USD']));

    return array_merge([
        'code' => '', 'name' => 'Importadora Global LLC', 'country_code' => 'US', 'tax_id' => '', 'foreign_tax_id' => 'US-12-3456789', 'currency_id' => $currency->id,
        'credit_days' => 60, 'address' => '1 Main St', 'phone' => '', 'email' => 'sales@global.test', 'notes' => '', 'contacts' => [],
        'tax_id_override' => '', 'tax_id_override_reason' => '', 'is_active' => 'on',
    ], $over);
}

function validWarehousePayload(array $over = []): array
{
    return array_merge(['code' => 'ALM-02', 'name' => 'Almacén secundario', 'address' => '', 'is_default' => '', 'is_active' => 'on'], $over);
}

function validFinancialAccountPayload(array $over = []): array
{
    $currency = Currency::query()->where('is_base', true)->firstOr(fn () => Currency::factory()->create(['code' => 'DOP', 'is_base' => true]));

    return array_merge(['type' => 'bank', 'name' => 'Cuenta operativa', 'bank_name' => 'Banco Popular', 'account_number' => '0123456789', 'currency_id' => $currency->id, 'is_active' => 'on'], $over);
}

function validNcfSequencePayload(array $over = []): array
{
    return array_merge(['ncf_type' => 'B01', 'range_from' => 1, 'range_to' => 100, 'expires_on' => now()->addYear()->endOfYear()->toDateString(), 'authorization_number' => '7001234567', 'notes' => '', 'is_active' => 'on'], $over);
}

function activeSequence(string $type = 'B01', array $over = []): NcfSequence
{
    return NcfSequence::factory()->create(array_merge(['ncf_type' => $type, 'range_from' => 1, 'range_to' => 100, 'next_number' => 1, 'expires_on' => in_array($type, ['B02', 'B04'], true) ? null : now()->addYear()->toDateString(), 'is_active' => true], $over));
}

/** A sales-role user linked to a salesperson (the shape every scoped-document test needs). */
function salesUserWithSalesperson(): array
{
    $user = actingAsRole('sales');
    $salesperson = Salesperson::factory()->create(['user_id' => $user->id]);

    return ['user' => $user, 'salesperson' => $salesperson];
}

function validProformaPayload(array $over = []): array
{
    $client = Client::factory()->create();
    $product = Product::factory()->create(['itbis_rate' => '18.00']);

    return array_merge([
        'client_id' => $client->id, 'salesperson_id' => '', 'currency_id' => Currency::base()->id, 'issue_date' => now()->toDateString(),
        'valid_until' => now()->addDays(15)->toDateString(), 'price_list_id' => '', 'notes' => '',
        'lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']],
    ], $over);
}

function validInvoicePayload(array $over = []): array
{
    $client = Client::factory()->create(['credit_days' => 30, 'credit_limit' => '0']);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $warehouse = Warehouse::default() ?? Warehouse::factory()->create(['is_default' => true]);

    return array_merge([
        'client_id' => $client->id, 'salesperson_id' => '', 'warehouse_id' => $warehouse->id, 'currency_id' => Currency::base()->id,
        'issue_date' => now()->toDateString(), 'payment_terms' => 'credit', 'credit_days' => 30, 'notes' => '',
        'lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']],
    ], $over);
}

/** Loads stock for every product of the payload, creates the draft through SaveInvoice and issues it. */
function issuedInvoice(array $over = [], ?User $actor = null): Invoice
{
    $actor ??= User::factory()->create();
    $payload = validInvoicePayload($over);
    foreach ($payload['lines'] as $line) {
        app(InventoryService::class)->receive(new StockLine($line['product_id'], $payload['warehouse_id'], '1000', '50'), StockMovementType::Initial, $actor, $actor);
    }
    if (! NcfSequence::query()->where('is_active', true)->exists()) {
        activeSequence('B01', ['range_to' => 1000]);
        activeSequence('B02', ['range_to' => 1000]);
    }
    $payload['salesperson_id'] = $payload['salesperson_id'] ?: Salesperson::factory()->create()->id;
    $invoice = app(SaveInvoice::class)->handle($payload, $actor->givePermissionTo('sales.invoices.view-all'));

    return app(IssueInvoice::class)->handle($invoice, $actor);
}

function validCreditNotePayload(Invoice $invoice, array $over = []): array
{
    $line = $invoice->lines->first();

    return array_merge([
        'invoice_id' => $invoice->id, 'issue_date' => now()->toDateString(), 'reason' => 'discount', 'warehouse_id' => $invoice->warehouse_id, 'notes' => '',
        'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => '1', 'unit_price' => '10', 'discount_percent' => '0', 'lot_id' => '']],
    ], $over);
}
