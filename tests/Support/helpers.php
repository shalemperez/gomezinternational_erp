<?php

use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\NcfType;
use App\Enums\StockMovementType;
use App\Models\Client;
use App\Models\Currency;
use App\Models\EcfCertificate;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Ecf\CertificateStore;
use App\Services\Ecf\EcfXmlWriter;
use App\Services\Ecf\XmlSigner;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use App\Support\Ecf\EcfDocumentData;
use App\Support\FiscalId;
use App\Support\Inventory\StockLine;
use Carbon\CarbonImmutable;
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
    $ncfType = NcfType::from($type);
    $isElectronic = $ncfType->isElectronic();

    return NcfSequence::factory()->create(array_merge([
        'ncf_type' => $type, 'environment' => $isElectronic ? 'test' : 'prod', 'range_from' => 1, 'range_to' => $isElectronic ? 10000000 : 100, 'next_number' => 1,
        'expires_on' => $ncfType->expires() ? now()->addYear()->toDateString() : null, 'is_active' => true,
    ], $over));
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
        'invoice_id' => $invoice->id, 'reason' => 'discount', 'warehouse_id' => $invoice->warehouse_id, 'notes' => '',
        'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => '1', 'unit_price' => '10', 'discount_percent' => '0', 'lot_id' => '']],
    ], $over);
}

function validDebitNotePayload(Invoice $invoice, array $over = []): array
{
    return array_merge([
        'invoice_id' => $invoice->id, 'reason' => 'freight', 'notes' => '',
        'lines' => [['product_id' => Product::factory()->create(['name' => 'Flete', 'itbis_rate' => '18.00'])->id, 'description' => 'Flete Santiago', 'quantity' => '1', 'unit_price' => '500', 'discount_percent' => '0']],
    ], $over);
}

function validDispatchPayload(Invoice $invoice, array $over = []): array
{
    return array_merge([
        'invoice_id' => $invoice->id, 'dispatch_date' => now()->toDateString(), 'driver_name' => 'Pedro', 'vehicle' => 'A123456', 'notes' => '',
        'lines' => $invoice->lines->map(fn ($l) => ['invoice_line_id' => $l->id, 'quantity' => $l->quantity, 'lot_id' => ''])->all(),
    ], $over);
}

function validReceiptPayload(Invoice $invoice, array $over = []): array
{
    $account = FinancialAccount::factory()->create(['currency_id' => $invoice->currency_id]);

    return array_merge([
        'client_id' => $invoice->client_id, 'currency_id' => $invoice->currency_id, 'receipt_date' => now()->toDateString(), 'payment_method' => 'transfer', 'financial_account_id' => $account->id,
        'reference' => 'TRX-1', 'amount_received' => $invoice->balance, 'retention_itbis' => '0', 'retention_isr' => '0', 'notes' => '',
        'applications' => [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]],
    ], $over);
}

/** Self-signed RSA-2048 certificate exported as PKCS#12 (binary), subject serialNumber = RNC as the DGII requires. */
function fakeP12(string $password = 'secret', string $rnc = '131880738', int $days = 365): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['countryName' => 'DO', 'organizationName' => 'Gomez International SRL', 'commonName' => 'Prueba Gomez', 'serialNumber' => $rnc], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export($cert, $p12, $key, $password);

    return $p12;
}

function activeCertificate(?User $actor = null): EcfCertificate
{
    return app(CertificateStore::class)->store(fakeP12(), 'secret', $actor ?? User::factory()->create(), 'Certificado de prueba');
}

function companyFiscalSettings(array $over = []): void
{
    $settings = app(Settings::class);
    foreach (array_merge(['company.name' => 'Gomez International', 'company.legal_name' => 'Gomez International SRL', 'company.rnc' => '131880738', 'company.address' => 'Av. Winston Churchill 1099, Santo Domingo', 'company.email' => 'facturacion@gomez.test', 'ecf.company.municipio' => '010100', 'ecf.company.provincia' => '010000'], $over) as $key => $value) {
        $settings->set($key, $value);
    }
}

/** Writes, signs (with the active or a fresh fake certificate) and returns the signed DOMDocument for assertions and XSD validation. */
function signedEcfXml(EcfDocumentData $data): DOMDocument
{
    $certificate = app(CertificateStore::class)->active() ?? activeCertificate();
    $signed = app(XmlSigner::class)->sign(app(EcfXmlWriter::class)->write($data, CarbonImmutable::now()), $certificate);
    $doc = new DOMDocument;
    $doc->loadXML($signed->xml);

    return $doc;
}

/**
 * Points the e-CF provider, environment and fake outcome at the given values and creates an active sequence per
 * traditional and electronic type when none exists yet. Keeps the fiscal mode unchanged (traditional NCF issuance).
 */
function ecfSettings(string $provider = 'fake', string $environment = 'test', string $outcome = 'accepted'): void
{
    $settings = app(Settings::class);
    $settings->set('ecf.provider', $provider);
    $settings->set('ecf.environment', $environment);
    $settings->set('ecf.fake.outcome', $outcome);
    companyFiscalSettings();

    foreach (['B01', 'B02'] as $type) {
        if (! NcfSequence::query()->where('ncf_type', $type)->where('is_active', true)->exists()) {
            activeSequence($type, ['range_to' => 1000]);
        }
    }
    foreach (['E31', 'E32', 'E33', 'E34', 'E44', 'E45'] as $type) {
        if (! NcfSequence::query()->where('ncf_type', $type)->where('environment', $environment)->where('is_active', true)->exists()) {
            activeSequence($type, ['environment' => $environment, 'range_to' => 10000000]);
        }
    }
}

/** ecfSettings() followed by the switch of the fiscal mode to 'ecf'. */
function ecfMode(string $provider = 'fake', string $environment = 'test', string $outcome = 'accepted'): void
{
    ecfSettings($provider, $environment, $outcome);
    app(Settings::class)->set('fiscal.mode', 'ecf');
}
