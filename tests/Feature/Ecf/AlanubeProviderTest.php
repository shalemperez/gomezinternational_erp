<?php

use App\Actions\Sales\IssueCreditNote;
use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Jobs\PollEcfStatus;
use App\Jobs\SubmitEcfDocument;
use App\Models\Client;
use App\Models\EcfAnnulment;
use App\Models\EcfDocument;
use App\Models\User;
use App\Services\Ecf\AlanubePayloadMapper;
use App\Services\Ecf\EcfDocumentBuilder;
use App\Services\Ecf\EcfService;
use App\Services\Settings;
use App\Support\FiscalId;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    Bus::fake([SubmitEcfDocument::class]);
    ecfMode('alanube');
    app(Settings::class)->set('ecf.alanube.api_key', Crypt::encryptString('live-key'));
});

test('the alanube payload maps the document data to the api field names', function () {
    $invoice = issuedInvoice();
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E31, 'E310000000001', CarbonImmutable::parse('2027-12-31'));
    $payload = app(AlanubePayloadMapper::class)->toPayload($data);
    expect($payload['idDoc'])->toMatchArray(['encf' => 'E310000000001', 'sequenceDueDate' => '31-12-2027', 'incomeType' => '01', 'paymentType' => 2, 'taxAmountIndicator' => 0])
        ->and($payload['sender'])->toMatchArray(['rnc' => '131880738', 'companyName' => 'Gomez International SRL', 'stampDate' => $invoice->issue_date->format('d-m-Y')])
        ->and($payload['buyer']['rnc'])->toBe(FiscalId::normalize($invoice->client_tax_id))
        ->and($payload['totals'])->toMatchArray(['totalTaxedAmount' => '200.00', 'i1AmountTaxed' => '200.00', 'itbisS1' => 18, 'itbisTotal' => '36.00', 'itbis1Total' => '36.00', 'totalAmount' => '236.00'])
        ->and($payload['itemDetails'][0])->toMatchArray(['lineNumber' => 1, 'billingIndicator' => 1, 'goodServiceIndicator' => 1, 'quantityItem' => '2.00', 'unitMeasure' => 43, 'unitPriceItem' => '100.0000', 'itemAmount' => '200.00'])
        ->and($payload)->not->toHaveKeys(['informationReference', 'otherCurrency'])->and($payload['totals'])->not->toHaveKey('exemptAmount');
});

test('submitting through alanube posts the json with the bearer key and stores the id and the dgii fields returned', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/fiscal-invoices' => Http::response(['id' => '01HZZALANUBE0001', 'status' => 'WAITING_RESPONSE', 'legalStatus' => 'IN_PROCESS', 'trackId' => 'TRK-9', 'documentNumber' => 'E310000000001', 'sequenceConsumed' => true, 'signatureDate' => '01-11-2026 10:00:00', 'securityCode' => 'abc123', 'documentStampUrl' => 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=131880738&encf=E310000000001', 'governmentResponse' => ['code' => 3, 'value' => []]], 201)]);
    $service = app(EcfService::class);
    $doc = issuedInvoice()->ecfDocument; // created by IssueInvoice in alanube mode (Task 8), not yet submitted
    expect($doc->status)->toBe(EcfStatus::Pending)->and($doc->xml_path)->toBeNull()->and(Storage::disk('local')->exists('ecf/test/'.now()->year.'/E310000000001/alanube-request.json'))->toBeTrue();
    $doc = $service->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toBe('01HZZALANUBE0001')->and($doc->security_code)->toBe('abc123')->and($doc->qr_url)->toContain('consultatimbre')->and($doc->signed_at)->not->toBeNull()->and($doc->provider)->toBe('alanube');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer live-key') && $r['idDoc']['encf'] === 'E310000000001' && $r['sender']['rnc'] === '131880738');
});

test('polling alanube maps legal statuses and messages', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/fiscal-invoices/A1' => Http::response(['id' => 'A1', 'status' => 'FINISHED', 'legalStatus' => 'ACCEPTED_WITH_OBSERVATIONS', 'trackId' => 'TRK-1', 'securityCode' => 'zzz999', 'signatureDate' => '01-11-2026 10:00:00', 'documentStampUrl' => 'https://x', 'governmentResponse' => ['code' => 4, 'value' => [['valor' => 'Observación', 'codigo' => 7]]]]),
        'https://sandbox.alanube.co/dom/v1/fiscal-invoices/A2' => Http::response(['id' => 'A2', 'status' => 'FINISHED', 'legalStatus' => 'REJECTED', 'governmentResponse' => ['code' => 2, 'value' => [['valor' => 'RNC inválido', 'codigo' => 1]]]])]);
    $a = EcfDocument::factory()->create(['provider' => 'alanube', 'status' => 'sent', 'track_id' => 'A1', 'encf' => 'E310000000001']);
    $b = EcfDocument::factory()->create(['provider' => 'alanube', 'status' => 'sent', 'track_id' => 'A2', 'encf' => 'E310000000002']);
    $service = app(EcfService::class);
    expect($service->poll($a)->status)->toBe(EcfStatus::ConditionallyAccepted)->and($a->fresh()->dgii_messages)->toEqual([['codigo' => '7', 'valor' => 'Observación']])->and($a->fresh()->security_code)->toBe('zzz999')
        ->and($service->poll($b)->status)->toBe(EcfStatus::Rejected)->and($b->fresh()->dgii_code)->toBe(2);
});

test('consumo and notes use their own endpoints and notes carry the reference block', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/*' => Http::response(['id' => 'X', 'status' => 'WAITING_RESPONSE', 'legalStatus' => 'IN_PROCESS'], 201)]);
    $invoice = issuedInvoice();
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice, ['reason' => 'discount']), $invoice, User::factory()->create());
    $consumer = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $consumo = issuedInvoice(['client_id' => $consumer->id]);
    $service = app(EcfService::class);
    $service->submit($credit->ecfDocument);
    $service->submit($consumo->ecfDocument);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/credit-notes') && isset($r['informationReference']) && $r['idDoc']['creditNoteIndicator'] === 0);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices') && ! isset($r['idDoc']['sequenceDueDate']));
});

test('alanube errors and a missing api key surface as ecf exceptions', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/fiscal-invoices' => Http::response(['code' => 401, 'message' => 'Unauthorized'], 401)]);
    $service = app(EcfService::class);
    $doc = issuedInvoice()->ecfDocument;
    expect(fn () => $service->submit($doc))->toThrow(EcfException::class, 'Alanube rechazó la solicitud (401): Unauthorized');
    app(Settings::class)->set('ecf.alanube.api_key', null);
    expect(fn () => $service->submit($doc->fresh()))->toThrow(EcfException::class, 'Configura la API key de Alanube en Configuración → Facturación electrónica.');
    expect(fn () => app(EcfService::class)->provider()->annul(EcfAnnulment::factory()->create(), '<ANECF/>'))->toThrow(EcfException::class, 'se gestiona desde el portal del proveedor');
});

test('the alanube webhook triggers a poll when the api key header matches', function () {
    app(Settings::class)->set('ecf.alanube.webhook_key', 'hook-key');
    $doc = EcfDocument::factory()->create(['provider' => 'alanube', 'status' => 'sent', 'track_id' => 'W1']);
    Bus::fake([PollEcfStatus::class]);
    $this->postJson('/fe/alanube/webhook', ['id' => 'W1', 'status' => 'FINISHED'], ['x-api-key' => 'wrong'])->assertStatus(401);
    $this->postJson('/fe/alanube/webhook', ['id' => 'W1', 'status' => 'FINISHED'], ['x-api-key' => 'hook-key'])->assertOk()->assertJson(['ok' => true]);
    Bus::assertDispatched(PollEcfStatus::class, fn ($j) => $j->document->is($doc));
    expect(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'webhook_received')->exists())->toBeTrue();
});

test('a resend of an alanube document with a stored id reads it back instead of posting again', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/fiscal-invoices/ALN-7' => Http::response(['id' => 'ALN-7', 'status' => 'FINISHED', 'legalStatus' => 'ACCEPTED', 'governmentResponse' => ['code' => 1, 'value' => []]])]);
    $doc = issuedInvoice()->ecfDocument;
    $doc->forceFill(['attempts' => 1, 'track_id' => 'ALN-7'])->save();
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Accepted);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});
