<?php

use App\Actions\Sales\IssueCreditNote;
use App\Actions\Sales\IssueDebitNote;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Models\AdditionalTax;
use App\Models\Client;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\User;
use App\Services\Ecf\EcfDocumentBuilder;
use App\Services\Ecf\EcfXmlWriter;
use App\Services\Ecf\XsdValidator;
use App\Support\FiscalId;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    (new DocumentSequenceSeeder)->run();
});

test('an e31 invoice maps header, buyer, items and totals and validates against the official xsd', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice(); // B01 client with RNC, 2 × 100.00 at 18 %
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000001', CarbonImmutable::parse('2027-12-31'));
    $unsigned = app(EcfXmlWriter::class)->write($data, CarbonImmutable::parse('2026-11-01 14:00:00', 'UTC'));
    $doc = signedEcfXml($data);
    app(XsdValidator::class)->validate($doc, 'ecf-31');
    $x = new DOMXPath($doc);
    $val = fn (string $p) => $x->evaluate("string($p)");

    expect($val('/ECF/Encabezado/Version'))->toBe('1.0')->and($val('//IdDoc/TipoeCF'))->toBe('31')->and($val('//IdDoc/eNCF'))->toBe('E310000000001')
        ->and($val('//IdDoc/FechaVencimientoSecuencia'))->toBe('31-12-2027')->and($val('//IdDoc/IndicadorMontoGravado'))->toBe('0')
        ->and($val('//IdDoc/TipoIngresos'))->toBe('01')->and($val('//IdDoc/TipoPago'))->toBe('2')->and($val('//IdDoc/FechaLimitePago'))->toBe($invoice->due_date->format('d-m-Y'))
        ->and($val('//Emisor/RNCEmisor'))->toBe('131880738')->and($val('//Emisor/RazonSocialEmisor'))->toBe('Gomez International SRL')->and($val('//Emisor/NombreComercial'))->toBe('Gomez International')
        ->and($val('//Emisor/FechaEmision'))->toBe($invoice->issue_date->format('d-m-Y'))->and($val('//Emisor/NumeroFacturaInterna'))->toBe($invoice->number)
        ->and($val('//Comprador/RNCComprador'))->toBe(FiscalId::normalize($invoice->client_tax_id))->and($val('//Comprador/RazonSocialComprador'))->toBe($invoice->client_name)
        ->and($x->query('//DetallesItems/Item')->length)->toBe(1)->and($val('//Item/NumeroLinea'))->toBe('1')->and($val('//Item/IndicadorFacturacion'))->toBe('1')
        ->and($val('//Item/IndicadorBienoServicio'))->toBe('1')->and($val('//Item/CantidadItem'))->toBe('2.00')->and($val('//Item/UnidadMedida'))->toBe('43')
        ->and($val('//Item/PrecioUnitarioItem'))->toBe('100.0000')->and($val('//Item/MontoItem'))->toBe('200.00')
        ->and($val('//Totales/MontoGravadoTotal'))->toBe('200.00')->and($val('//Totales/MontoGravadoI1'))->toBe('200.00')->and($val('//Totales/ITBIS1'))->toBe('18')
        ->and($val('//Totales/TotalITBIS'))->toBe('36.00')->and($val('//Totales/TotalITBIS1'))->toBe('36.00')->and($val('//Totales/MontoTotal'))->toBe('236.00')
        ->and($x->query('//Totales/MontoExento')->length)->toBe(0)->and($x->query('//Totales/ITBIS2')->length)->toBe(0)
        ->and($unsigned->saveXML())->toContain('<FechaHoraFirma>01-11-2026 10:00:00</FechaHoraFirma>')
        ->and((new DOMXPath($unsigned))->query('//*[not(node())]')->length)->toBe(0);
});

test('consumo invoices omit the sequence expiry and the buyer rnc when the client has no tax id', function () {
    companyFiscalSettings();
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $invoice = issuedInvoice(['client_id' => $client->id, 'payment_terms' => 'cash', 'credit_days' => 0]);
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E32, 'E320000000001', null);
    $doc = signedEcfXml($data);
    app(XsdValidator::class)->validate($doc, 'ecf-32');
    $x = new DOMXPath($doc);

    expect($x->query('//IdDoc/FechaVencimientoSecuencia')->length)->toBe(0)->and($x->query('//Comprador/RNCComprador')->length)->toBe(0)
        ->and($x->evaluate('string(//Comprador/RazonSocialComprador)'))->toBe($invoice->client_name)->and($x->evaluate('string(//IdDoc/TipoPago)'))->toBe('1')
        ->and($x->query('//IdDoc/FechaLimitePago')->length)->toBe(0)->and($data->requiresRfce('250000.00'))->toBeTrue()->and($data->isConsumo())->toBeTrue();
});

test('exempt lines use indicator 4 and totals split gravado and exento', function () {
    companyFiscalSettings();
    $exempt = Product::factory()->create(['itbis_rate' => '0.00']);
    $taxed = Product::factory()->create(['itbis_rate' => '18.00']);
    $invoice = issuedInvoice(['lines' => [
        ['product_id' => $taxed->id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0'],
        ['product_id' => $exempt->id, 'description' => '', 'quantity' => '3', 'unit_price' => '50', 'discount_percent' => '10'],
    ]]);
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000002', CarbonImmutable::parse('2027-12-31'));
    $doc = signedEcfXml($data);
    app(XsdValidator::class)->validate($doc, 'ecf-31');
    $x = new DOMXPath($doc);

    expect($x->evaluate('string(//Item[NumeroLinea=2]/IndicadorFacturacion)'))->toBe('4')->and($x->evaluate('string(//Item[NumeroLinea=2]/DescuentoMonto)'))->toBe('15.00')
        ->and($x->evaluate('string(//Item[NumeroLinea=2]/MontoItem)'))->toBe('135.00')->and($x->evaluate('string(//Totales/MontoExento)'))->toBe('135.00')
        ->and($x->evaluate('string(//Totales/MontoGravadoI1)'))->toBe('100.00')->and($x->evaluate('string(//Totales/TotalITBIS)'))->toBe('18.00')
        ->and($x->evaluate('string(//Totales/MontoTotal)'))->toBe('253.00');
});

test('quantities with more than two decimals are refused with the line number', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice(['lines' => [['product_id' => Product::factory()->create()->id, 'description' => '', 'quantity' => '1.125', 'unit_price' => '100', 'discount_percent' => '0']]]);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000003', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'La línea 1 tiene una cantidad con más de 2 decimales (1.1250); el e-CF solo admite 2 decimales.');
});

test('a usd invoice is converted to dop and carries the otra moneda block', function () {
    companyFiscalSettings();
    $usd = Currency::query()->where('code', 'USD')->firstOr(fn () => Currency::factory()->create(['code' => 'USD', 'is_base' => false]));
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => today()->toDateString(), 'rate' => '58.500000']);
    $invoice = issuedInvoice(['currency_id' => $usd->id]);
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000004', CarbonImmutable::parse('2027-12-31'));
    $doc = signedEcfXml($data);
    app(XsdValidator::class)->validate($doc, 'ecf-31');
    $x = new DOMXPath($doc);

    expect($x->evaluate('string(//Item/PrecioUnitarioItem)'))->toBe('5850.0000')->and($x->evaluate('string(//Item/MontoItem)'))->toBe('11700.00')
        ->and($x->evaluate('string(//Totales/TotalITBIS)'))->toBe('2106.00')->and($x->evaluate('string(//Totales/MontoTotal)'))->toBe('13806.00')
        ->and($x->evaluate('string(//OtraMoneda/TipoMoneda)'))->toBe('USD')->and($x->evaluate('string(//OtraMoneda/TipoCambio)'))->toBe('58.5000')
        ->and($x->evaluate('string(//OtraMoneda/MontoTotalOtraMoneda)'))->toBe('236.00')->and($x->evaluate('string(//OtraMoneda/TotalITBISOtraMoneda)'))->toBe('36.00');
});

test('credit and debit notes carry the reference block and validate against xsd 34 and 33', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice();
    $actor = User::factory()->create();
    activeSequence('B04');
    activeSequence('B03');
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice, ['reason' => 'discount']), $invoice, $actor);
    $debit = app(IssueDebitNote::class)->handle(validDebitNotePayload($invoice), $invoice->fresh(), $actor);
    $this->travel(31)->days();

    $cdoc = signedEcfXml(app(EcfDocumentBuilder::class)->fromCreditNote($credit->fresh(), NcfType::E34, 'E340000000001', null));
    app(XsdValidator::class)->validate($cdoc, 'ecf-34');
    $cx = new DOMXPath($cdoc);
    expect($cx->evaluate('string(//IdDoc/IndicadorNotaCredito)'))->toBe('0')->and($cx->query('//IdDoc/FechaVencimientoSecuencia')->length)->toBe(0)
        ->and($cx->query('//IdDoc/TerminoPago')->length)->toBe(0)
        ->and($cx->evaluate('string(//InformacionReferencia/NCFModificado)'))->toBe($invoice->ncf)->and(strlen($invoice->ncf))->toBe(11)
        ->and($cx->evaluate('string(//InformacionReferencia/FechaNCFModificado)'))->toBe($invoice->issue_date->format('d-m-Y'))
        ->and($cx->evaluate('string(//InformacionReferencia/CodigoModificacion)'))->toBe('3')->and($cx->evaluate('string(//InformacionReferencia/RazonModificacion)'))->toBe('Descuento posterior');

    $ddoc = signedEcfXml(app(EcfDocumentBuilder::class)->fromDebitNote($debit->fresh(), NcfType::E33, 'E330000000001', CarbonImmutable::parse('2027-12-31')));
    app(XsdValidator::class)->validate($ddoc, 'ecf-33');
    $dx = new DOMXPath($ddoc);
    expect($dx->evaluate('string(//IdDoc/FechaVencimientoSecuencia)'))->toBe('31-12-2027')->and($dx->query('//IdDoc/IndicadorNotaCredito')->length)->toBe(0)
        ->and($dx->evaluate('string(//InformacionReferencia/CodigoModificacion)'))->toBe('3');
});

test('additional taxes map to tabla i codes and a tax without dgii code is refused', function () {
    companyFiscalSettings();
    $isc = AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10', 'dgii_code' => '023', 'included_in_itbis_base' => true]);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $product->additionalTaxes()->attach($isc->id);
    $invoice = issuedInvoice(['lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $doc = signedEcfXml(app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000005', CarbonImmutable::parse('2027-12-31')));
    app(XsdValidator::class)->validate($doc, 'ecf-31');
    $x = new DOMXPath($doc);
    expect($x->evaluate('string(//Item/TablaImpuestoAdicional/ImpuestoAdicional/TipoImpuesto)'))->toBe('023')
        ->and($x->evaluate('string(//Totales/ImpuestosAdicionales/ImpuestoAdicional/TipoImpuesto)'))->toBe('023')
        ->and($x->evaluate('string(//Totales/ImpuestosAdicionales/ImpuestoAdicional/TasaImpuestoAdicional)'))->toBe('10.00')
        ->and($x->evaluate('string(//Totales/ImpuestosAdicionales/ImpuestoAdicional/MontoImpuestoSelectivoConsumoAdvalorem)'))->toBe('10.00')
        ->and($x->evaluate('string(//Totales/MontoImpuestoAdicional)'))->toBe('10.00')->and($x->evaluate('string(//Totales/MontoTotal)'))->toBe($invoice->total);

    $isc->update(['dgii_code' => null]);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice->fresh(), NcfType::E31, 'E310000000006', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'El impuesto adicional ISC-ADV no tiene código DGII configurado (Tabla I del Formato e-CF).');
});

test('missing company rnc or address is refused before any xml is produced', function () {
    companyFiscalSettings(['company.rnc' => null]);
    $invoice = issuedInvoice();
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000007', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'Configura el RNC de la empresa en Configuración antes de emitir e-CF.');
    companyFiscalSettings(['company.address' => '']);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000007', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'Configura la dirección de la empresa en Configuración antes de emitir e-CF.');
});

test('a credito fiscal invoice without the client tax id is refused', function () {
    companyFiscalSettings();
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $invoice = issuedInvoice(['client_id' => $client->id]);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000008', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'La factura de crédito fiscal requiere el RNC o cédula del cliente.');
});

test('an expiring e-cf type without sequence expiry is refused', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice();
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000009', null))
        ->toThrow(EcfException::class, 'requiere fecha de vencimiento para el e-CF');
});

test('a credit note on an e-cf older than 30 days carries IndicadorNotaCredito 1', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice();
    $actor = User::factory()->create();
    activeSequence('B04');
    DB::table('invoices')->where('id', $invoice->id)->update(['issue_date' => today()->subDays(31)->toDateString()]);
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice->fresh(), ['reason' => 'discount']), $invoice->fresh(), $actor);

    $doc = signedEcfXml(app(EcfDocumentBuilder::class)->fromCreditNote($credit->fresh(), NcfType::E34, 'E340000000002', null));
    app(XsdValidator::class)->validate($doc, 'ecf-34');

    expect((new DOMXPath($doc))->evaluate('string(//IdDoc/IndicadorNotaCredito)'))->toBe('1');
});
