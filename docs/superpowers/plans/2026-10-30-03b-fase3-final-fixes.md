# Phase 3b — Final-review fixes (e-CF): Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Executors are Claude Haiku agents that **decide nothing**: read ARCH §11 (`docs/superpowers/plans/2026-10-29-00-architecture.md`) first, then the Global Constraints of Phase 1 (GC-1 … GC-9), Phase 2 (GC-10 … GC-13), Phase 2b (GC-14 … GC-16) and Phase 3 (`docs/superpowers/plans/2026-10-30-03-fase3-ecf.md`, GC-17 … GC-22 and its "Standing rulings"). All of them apply unchanged. **Executors never edit anything under `docs/`.** If a step's expected output does not appear, or a pre-existing test fails for a reason this plan does not name, STOP and report. Do not improvise.

**Goal:** Close the 10 Important findings of the final whole-branch review of `phase-3-ecf` (HEAD `d50ac51`, 609 tests green) and minors 1, 2, 3, 4, 6 and 7. The changes are:
- an atomic claim of each submit/poll, and final statuses that never change once set;
- a provider lookup before any resend;
- annulment limited to tail ranges, which shrink the sequence;
- expired certificates refused, plus a 30-day expiry alert;
- a public receiver that never answers 500/302, with throttling, a size cap, signer identity checks and a DOCTYPE check after parsing;
- secret settings kept out of the activity log;
- a sweep for stuck documents, plus job timeouts;
- orphaned files moved aside instead of blocking an e-NCF;
- QR signing time in GMT-4;
- unknown ITBIS rates and total mismatches refused;
- a locked commercial decision;
- a normalised certificate RNC warning.

**Architecture:** No new subsystems. One migration (`2026_11_01_000035`). Each task edits the current code. **Read every file you touch before editing.** Line numbers were taken at HEAD `d50ac51`.

**Tech Stack:** unchanged (Phase 3).

**Spec / inputs:**
- Review: `.superpowers/sdd/2026-10-30-03-fase3-ecf/final-review-out.log`.
- Rulings: the `Ruling I1` … `Ruling I5/I6/I7/I10 and I8/I9` lines at the end of `.superpowers/sdd/2026-10-30-03-fase3-ecf/progress.md`. They are binding and already folded into the tasks below.
- Research: `docs/superpowers/research/2026-10-30-dgii-ecf.md` (RES §4 "Consulta TrackIds").

**Branch:** `phase-3-ecf` (continue on it).

**Gate (every task):** `vendor/bin/pint --dirty && composer test 2>&1 | tail -8`. It passes when:
- pint shows `"result":"passed"`;
- phpstan shows `"errors":0`;
- pest shows `"result":"passed"` with the task's N.

No task touches `resources/js`.

**Commit rule:** `vendor/bin/pint --dirty && git add -A -- . ':(exclude)docs' && git commit -q -m "<subject>" -m "Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>"`.

**Test totals:** they start at **609** and end at **641**.

**Dev database:** after Task 3 run `php artisan migrate --no-interaction 2>&1 | tail -2`.

**Out of scope (parked, do not touch):**
- Minor 5 (Alanube `FAILED` mapping) and Minor 8 (RFCE upload file name). Both are to be confirmed during certification.
- Encrypting the webhook key.
- Re-checking `firstUsedEncf` at send time. Tail ranges make it unreachable.

---

## Global Constraints

Phase 1–3 constraints apply unchanged. Additions for this plan:

- **GC-23 Claim before calling out.** Every DGII/PSFE submission or status poll first claims the `ecf_documents` row:
  - in a short `DB::transaction` it runs `lockForUpdate`, refreshes the model and re-checks the status;
  - it then sets `attempts + 1`, `last_attempt_at = now()` and `next_attempt_at = now() + config('ecf.retry_backoff_minutes')[0]` minutes;
  - it commits, and only then calls the provider.

  `applyResult()` re-reads the row under lock. It never changes a document whose status `isFinal()` (accepted, conditionally_accepted, rejected, cancelled). It still records the transmission and logs `result_ignored`. Web actions (Reintentar, Consultar, Reenviar pendientes, Alanube webhook) always `dispatch()` the unique jobs, never `dispatchSync()`.
- **GC-24 Lookup before resend.** `EcfProvider::lookup(EcfDocument): ?EcfSubmissionResult` asks the provider what it already holds for the e-NCF. `SubmitEcfDocument` calls it whenever `attempts > 0` and resends only when it returns `null`.
- **GC-25 Annulment ranges.** Only two kinds of range exist:
  - **(a)** single used e-NCFs of documents cancelled before sending (`ecf_document_id` set; existing behaviour);
  - **(b)** tail ranges: `range_from >= sequence.next_number` and `range_to = sequence.range_to`.

  Adding a tail range sets `sequence.range_to = range_from - 1` and stores `ncf_sequence_id` plus `original_range_to` on the range. Deleting a pending tail range, or getting a rejected/failed annulment, restores the sequence end. Rejected or failed tail ranges stay attached to their annulment as history: the user re-adds them to retry. Cancelled-invoice ranges still return to pending.
- **GC-26 Public receiver.** `/fe/*` routes run behind `throttle:fe` (per IP, `config('ecf.receiver.rate_per_minute')`) and `LimitFeRequestSize` (`config('ecf.receiver.max_bytes')`, HTTP 413). The reception endpoint always answers HTTP 200 with a signed ARECF: storage failures are reported, never rendered.

## Review Focus

1. **A cancel that lands while the DGII call is in flight** must be refused, and the invoice must stay issued and sent. Test: Task 1, `a cancel landing during the dgii call is refused because the attempt was claimed first`.
2. **A resend after a timeout when the DGII already accepted the first send** must adopt the acceptance and never post the XML again. Test: Task 2, `a resend after an unclear failure asks the dgii for the e-ncf and applies its accepted result instead of sending again`.
3. **e-NCFs of a pending or accepted annulment** must never be issued. Test: Task 3, `adding a tail range shrinks the sequence so its numbers are never issued`.
4. **Issuing after the certificate expired** must be refused before an e-NCF is used. Test: Task 4, `issuing in ecf mode with an expired certificate is refused before any e-ncf is used`.
5. **A supplier document with a malformed date, an oversized total or a storage failure** must still get HTTP 200 plus an ARECF, never a 500 or a 302. Tests: Task 5, `a refused document with a bad fecha emision or an oversized total is answered with an arecf and stored with its identity only` and `a storage failure while saving a receipt still answers the arecf`.

---

### Task 1: Claim the row before every provider call; final statuses are terminal; web actions queue the jobs (I1)

**Files:**
- Modify: `app/Services/Ecf/EcfService.php`, `app/Http/Controllers/Fiscal/EcfDocumentController.php`, `app/Http/Controllers/Fiscal/EcfCertificationController.php`, `app/Http/Controllers/Fe/AlanubeWebhookController.php`
- Test: `tests/Feature/Sales/InvoiceIssueEcfTest.php`, `tests/Feature/Ecf/EcfServiceFakeTest.php`, `tests/Feature/Ecf/DgiiDirectProviderTest.php`, `tests/Feature/Ecf/EcfDocumentPagesTest.php`, `tests/Feature/Ecf/CertificationPageTest.php`, `tests/Feature/Ecf/AlanubeProviderTest.php`

**Interfaces:**
- Produces:
  - `EcfService::submit(EcfDocument): EcfDocument` and `EcfService::poll(EcfDocument): EcfDocument` claim the row first, through the private `claim(EcfDocument $ecfDocument, list<EcfStatus> $allowed): void`.
  - Private `lockFresh(EcfDocument $ecfDocument): void` runs `EcfDocument::query()->whereKey($ecfDocument->getKey())->lockForUpdate()->first()` and then `$ecfDocument->refresh()`.
  - `applyResult(EcfDocument, EcfSubmissionResult, string $kind): void` (signature unchanged) runs inside one transaction under `lockFresh` and ignores results for final statuses.
  - The poll claim also increments `attempts`, as the ruling says.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Sales/InvoiceIssueEcfTest.php` (add `use App\Services\Ecf\EcfService;` and `use Illuminate\Support\Facades\Http;`):
```php
test('a stale document cancelled before the claim is never sent', function () {
    ecfMode();
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    $invoice = issuedInvoice();
    $stale = $invoice->ecfDocument; // still "signed" in memory
    app(CancelInvoice::class)->handle($invoice->fresh(), User::factory()->create(), 'duplicada');
    expect(fn () => app(EcfService::class)->submit($stale))->toThrow(EcfException::class, "El e-CF {$stale->encf} fue anulado antes del envío");
    expect($stale->fresh()->status)->toBe(EcfStatus::Cancelled)->and($stale->fresh()->attempts)->toBe(0)->and($stale->transmissions()->count())->toBe(0);
});

test('a cancel landing during the dgii call is refused because the attempt was claimed first', function () {
    ecfMode('dgii');
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    $invoice = issuedInvoice();
    $cancelError = null;
    $claimed = null;
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => function () use ($invoice, &$cancelError, &$claimed) {
        $claimed = EcfDocument::query()->where('document_id', $invoice->id)->firstOrFail();
        try {
            app(CancelInvoice::class)->handle($invoice->fresh(), User::factory()->create(), 'duplicada');
        } catch (DocumentStateException $e) {
            $cancelError = $e->getMessage();
        }

        return Http::response(['trackId' => 'TRK-C']);
    }]);
    $doc = app(EcfService::class)->submit($invoice->ecfDocument);
    expect($cancelError)->toBe('Un e-CF ya enviado a la DGII no se puede anular; emite una nota de crédito que lo anule (código de modificación 1).')
        ->and($claimed->attempts)->toBe(1)->and($claimed->next_attempt_at->isFuture())->toBeTrue()->and($claimed->last_attempt_at)->not->toBeNull()
        ->and($doc->status)->toBe(EcfStatus::Sent)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});
```
Append to `tests/Feature/Ecf/EcfServiceFakeTest.php` (add `use App\Models\EcfDocument;`, `use App\Support\Ecf\EcfSubmissionResult;`, `use Illuminate\Support\Facades\Notification;`):
```php
test('a late result never overwrites a final or cancelled status', function () {
    ecfSettings();
    activeCertificate();
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000005', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), null));
    expect($doc->status)->toBe(EcfStatus::Accepted);
    Notification::fake();
    $service->applyResult($doc, new EcfSubmissionResult(status: EcfStatus::Rejected, trackId: 'LATE-1', code: 2, messages: [['codigo' => '1', 'valor' => 'Duplicado']], raw: ['late' => true], sequenceUsed: true, httpStatus: 200), 'poll');
    expect($doc->fresh()->status)->toBe(EcfStatus::Accepted)->and($doc->fresh()->rejected_at)->toBeNull()->and($doc->transmissions()->count())->toBe(2)
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'result_ignored')->exists())->toBeTrue();
    Notification::assertNothingSent();
    $cancelled = EcfDocument::factory()->create(['status' => 'cancelled', 'encf' => 'E310000000006']);
    $service->applyResult($cancelled, new EcfSubmissionResult(status: EcfStatus::Accepted, trackId: 'LATE-2', code: 1, messages: [], raw: [], sequenceUsed: true, httpStatus: 200), 'send');
    expect($cancelled->fresh()->status)->toBe(EcfStatus::Cancelled)->and($cancelled->fresh()->accepted_at)->toBeNull();
});
```
Append to `tests/Feature/Ecf/DgiiDirectProviderTest.php` (add `use App\Jobs\SubmitEcfDocument;`):
```php
test('a retry dispatched while the submission is running is dropped by the unique lock', function () {
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000010', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => function () use ($doc) {
            SubmitEcfDocument::dispatch(EcfDocument::query()->findOrFail($doc->id));

            return Http::response(['trackId' => 'TRK-U']);
        },
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-U' => Http::response(['trackId' => 'TRK-U', 'codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => []]),
    ]);
    SubmitEcfDocument::dispatch($doc);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'))->count())->toBe(1)
        ->and($doc->fresh()->status)->toBe(EcfStatus::Accepted)->and($doc->fresh()->attempts)->toBe(2); // submit claim + chained poll claim
});
```
Edit existing tests:
- `tests/Feature/Ecf/EcfDocumentPagesTest.php`:
  - Rename `retry and poll require the manage permission and run synchronously` to `retry and poll require the manage permission and queue the job`. Replace `'Reenvío ejecutado: Aceptado'` with `'Reenvío en cola: el estado se actualizará en breve'`; the comment and the following `expect(...Accepted)` stay.
  - Rename `poll on a sent e-cf runs synchronously and flashes the new status` to `poll on a sent e-cf queues the status check`. Replace `'Consulta ejecutada: Aceptado'` with `'Consulta en cola: el estado se actualizará en breve'`.
- `tests/Feature/Ecf/CertificationPageTest.php`, test `resubmit sends the pending documents of the environment`: replace `'2 e-CF reenviados'` with `'2 e-CF en cola para reenvío'`.
- `tests/Feature/Ecf/AlanubeProviderTest.php`, test `the alanube webhook triggers a poll when the api key header matches`: replace `Bus::assertDispatchedSync(` with `Bus::assertDispatched(`.

Run: `php artisan test --compact --filter='InvoiceIssueEcfTest|EcfServiceFakeTest|DgiiDirectProviderTest|EcfDocumentPagesTest|CertificationPageTest|AlanubeProviderTest' 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `EcfService`** (add `use Illuminate\Support\Facades\DB;`)
  - `submit()` body: `$this->claim($ecfDocument, [EcfStatus::Pending, EcfStatus::Signed]);`, then the provider call and `applyResult` exactly as today. Delete the old status check and the old `forceFill(['attempts' ...])` line. Docblock: `@throws EcfException when the document was already sent or cancelled, or the provider is unavailable`.
  - `poll()` body: `$this->claim($ecfDocument, [EcfStatus::Sent, EcfStatus::InProcess]);`, then the provider call as today. Delete the old check.
  - `claim()`: inside `DB::transaction`:
    1. call `lockFresh`;
    2. if the status is not in `$allowed`, throw `EcfException` with the first match of:
       - status `Cancelled` → `"El e-CF {$ecfDocument->encf} fue anulado antes del envío"`;
       - `$allowed` contains `Pending` → `"El e-CF {$ecfDocument->encf} ya fue enviado"`;
       - otherwise → `"El e-CF {$ecfDocument->encf} no está esperando resultado de la DGII"`;
    3. otherwise `forceFill(['attempts' => attempts + 1, 'last_attempt_at' => now(), 'next_attempt_at' => now()->addMinutes((int) config('ecf.retry_backoff_minutes')[0])])->save()`.

    PHPDoc: "Locks and re-reads the row and records the attempt before any network call, so a concurrent cancel sees it (GC-23)."
  - `applyResult()`: wrap everything except the rejection notification in `$applied = DB::transaction(function () use (...): bool { ... })`:
    1. `lockFresh`;
    2. create the transmission (unchanged);
    3. if `$ecfDocument->status->isFinal()`, log `activity('ecf_documents')->performedOn($ecfDocument)->event('result_ignored')->withProperties(['current' => $ecfDocument->status->value, 'received' => $result->status->value, 'kind' => $kind, 'track_id' => $result->trackId])->log('Resultado ignorado: el e-CF ya tiene un estado final')` and `return false`;
    4. otherwise run the existing body (file, fields, save, activity) and `return true`.

    After the transaction, send the `EcfRejected` notification (existing try/catch) only when `$applied && $result->status === EcfStatus::Rejected`.

- [ ] **Step 3: Controllers**
  - `EcfDocumentController::retry`: replace `SubmitEcfDocument::dispatchSync($ecfDocument); $ecfDocument->refresh();` with `SubmitEcfDocument::dispatch($ecfDocument);`. Success flash: `'Reenvío en cola: el estado se actualizará en breve'`.
  - `EcfDocumentController::poll`: likewise with `PollEcfStatus::dispatch($ecfDocument);`. Flash: `'Consulta en cola: el estado se actualizará en breve'`.
  - `EcfCertificationController::resubmit`: `SubmitEcfDocument::dispatch($ecfDocument)` in the `each`. Flash: `"{$documents->count()} e-CF en cola para reenvío"`.
  - `AlanubeWebhookController::handle`: `PollEcfStatus::dispatch($document);`.

- [ ] **Step 4: Gate.** Expected: passed (**613** tests). If `a retry dispatched while the submission is running is dropped by the unique lock` records 2 sends, STOP and report. Do not change the job.
- [ ] **Step 5: Commit** — `fix(ecf): claim each submission and poll under a row lock, keep final statuses terminal and queue manual retries`

---

### Task 2: Provider lookup before a resend (I2)

**Files:**
- Modify: `app/Contracts/EcfProvider.php`, `app/Services/Ecf/Providers/FakeEcfProvider.php`, `app/Services/Ecf/Providers/AlanubeProvider.php`, `app/Services/Ecf/Providers/DgiiDirectProvider.php`, `app/Services/Ecf/DgiiClient.php`, `app/Services/Ecf/EcfService.php`, `app/Jobs/SubmitEcfDocument.php`, `config/ecf.php`
- Test: `tests/Feature/Ecf/DgiiDirectProviderTest.php`, `tests/Feature/Ecf/AlanubeProviderTest.php`

**Interfaces:**
- Consumes: `EcfService::applyResult` (Task 1).
- Produces:
  - **Contract.** `EcfProvider::lookup(EcfDocument $document): ?EcfSubmissionResult`. PHPDoc: "What the provider already holds for this e-NCF, asked before a resend after an unclear failure; null when it has no record (the caller sends again). @throws EcfException when the provider cannot be reached".
  - **`EcfService::lookup(EcfDocument $ecfDocument): bool`.** It is true when the provider knew the document; the result is then applied with kind `'lookup'`.
  - **`DgiiClient::hasEndpoint(string $key): bool`** and **`DgiiClient::trackIds(string $rncEmisor, string $encf): array{status: int, json: array<mixed>}`**.
  - **Config.** `ecf.environments.{test,prod}.consulta_trackids` is set. **`cert` has none:** RES §4 lists Consulta TrackIds for TesteCF/eCF only.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Ecf/DgiiDirectProviderTest.php` (add `use App\Jobs\PollEcfStatus;` and `use Illuminate\Support\Facades\Bus;`):
```php
test('a resend after an unclear failure asks the dgii for the e-ncf and applies its accepted result instead of sending again', function () {
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000011', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $doc->forceFill(['attempts' => 1, 'last_error' => 'Error HTTP 503 de la DGII en recepcion'])->save();
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/consultatrackids/api/trackids/consulta?rncemisor=131880738&encf=E310000000011' => Http::response([['trackId' => 'TRK-OLD', 'estado' => 'Rechazado', 'fechaRecepcion' => '01-11-2026 10:00:00'], ['trackId' => 'TRK-FIRST', 'estado' => 'Aceptado', 'fechaRecepcion' => '01-11-2026 09:00:00']]),
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-OLD' => Http::response(['trackId' => 'TRK-OLD', 'codigo' => 2, 'estado' => 'Rechazado', 'mensajes' => [['valor' => 'e-NCF duplicado', 'codigo' => 1]]]),
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-FIRST' => Http::response(['trackId' => 'TRK-FIRST', 'codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => []]),
    ]);
    (new SubmitEcfDocument($doc))->handle($service);
    $doc->refresh();
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->track_id)->toBe('TRK-FIRST')->and($doc->transmissions()->where('kind', 'lookup')->count())->toBe(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'));
});

test('a resend whose e-ncf the dgii does not know is sent again and a first submission never asks', function () {
    $service = app(EcfService::class);
    $first = $service->create(issuedInvoice(), new FiscalNumber('E310000000012', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $retry = $service->create(issuedInvoice(), new FiscalNumber('E310000000013', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $retry->forceFill(['attempts' => 1])->save();
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/consultatrackids/api/trackids/consulta?rncemisor=131880738&encf=E310000000013' => Http::response([]),
        'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-N']),
    ]);
    Bus::fake([PollEcfStatus::class]);
    (new SubmitEcfDocument($first))->handle($service);
    (new SubmitEcfDocument($retry))->handle($service);
    expect($first->fresh()->status)->toBe(EcfStatus::Sent)->and($retry->fresh()->status)->toBe(EcfStatus::Sent)->and($retry->fresh()->attempts)->toBe(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'consultatrackids') && str_contains($r->url(), 'encf=E310000000013'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'encf=E310000000012'));
});
```
Append to `tests/Feature/Ecf/AlanubeProviderTest.php`:
```php
test('a resend of an alanube document with a stored id reads it back instead of posting again', function () {
    Http::fake(['https://sandbox.alanube.co/dom/v1/fiscal-invoices/ALN-7' => Http::response(['id' => 'ALN-7', 'status' => 'FINISHED', 'legalStatus' => 'ACCEPTED', 'governmentResponse' => ['code' => 1, 'value' => []]])]);
    $doc = issuedInvoice()->ecfDocument;
    $doc->forceFill(['attempts' => 1, 'track_id' => 'ALN-7'])->save();
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Accepted);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});
```
Run: `php artisan test --compact --filter='DgiiDirectProviderTest|AlanubeProviderTest' 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Config** `config/ecf.php`:
  - In the `$dgii` closure add `'consulta_trackids' => "https://ecf.dgii.gov.do/{$segment}/consultatrackids/api/trackids/consulta",`.
  - Change the `cert` entry to `'cert' => array_diff_key($dgii('certecf'), ['consulta_trackids' => true]),`.
  - Add a one-line comment above `environments`: `// Consulta TrackIds exists only in TesteCF and eCF (RES §4); in CerteCF a resend is sent without lookup.`
- [ ] **Step 3: `DgiiClient`**
  - `hasEndpoint()` returns `filled(config("ecf.environments.{$this->ecf->environment()->value}.{$key}"))`.
  - `trackIds()` copies `result()`: an authorised GET to `$this->endpoint('consulta_trackids')` with query `['rncemisor' => $rncEmisor, 'encf' => $encf]` (that key order), service name `'consulta_trackids'`, returning `$this->summary($response)`.
- [ ] **Step 4: Providers**
  - `FakeEcfProvider::lookup`: `return null;`.
  - `AlanubeProvider::lookup`: `blank($document->track_id) ? null : $this->poll($document)`.
  - `DgiiDirectProvider`:
    - Add constructor dependency `private readonly Settings $settings` (`App\Services\Settings`), placed last.
    - Extract a private `resultFor(string $trackId): EcfSubmissionResult`, which is the current `poll()` body for a given trackId. `poll()` calls `$this->resultFor((string) $document->track_id)`.
    - `lookup()`:
      1. Return `null` when `$document->isRfce()` (RFCE has no trackId; Consulta RFCE parameters are UNVERIFIED in RES §4) or when `! $this->client->hasEndpoint('consulta_trackids')`.
      2. Call `$response = $this->client->trackIds(FiscalId::normalize((string) $this->settings->get('company.rnc', '')), $document->encf)`.
      3. Status 404 → `null`.
      4. Any other status outside 200–299 → throw `EcfException("Respuesta inesperada de la DGII al consultar los trackId ({$response['status']})")`.
      5. Collect the non-empty strings `(string) ($entry['trackId'] ?? $entry['trackid'] ?? '')` from the array entries of `$response['json']`, in order. An empty list → `null`.
      6. For each trackId call `resultFor()`. Return the first result whose `status->isValidForTax()`. If none qualifies, return the last result.

      PHPDoc: the estado text of the list is not trusted; each trackId is resolved through consultaresultado.
- [ ] **Step 5: `EcfService::lookup`.** `$result = $this->provider()->lookup($ecfDocument)`. When it is `null`, return `false`. Otherwise call `$this->applyResult($ecfDocument, $result, 'lookup')` and return `true`.
- [ ] **Step 6: `SubmitEcfDocument::handle`.** Inside the existing `try`, replace `$ecf->submit($this->document);` with:
```php
            $knownByProvider = $this->document->attempts > 0 && $ecf->lookup($this->document);
            if (! $knownByProvider) {
                $ecf->submit($this->document);
            }
```
  The rest stays the same, including the `catch` and the poll chain on `Sent`/`InProcess`. A lookup failure is recorded by the same `catch` and nothing is resent.
- [ ] **Step 7: Gate.** Expected: passed (**616**). If an existing DGII-mode test now hits a stray `consultatrackids` request, STOP and report.
- [ ] **Step 8: Commit** — `fix(ecf): ask the provider for the e-ncf before resending after an unclear failure`

---

### Task 3: Annul only tail ranges and keep them away from issuing (I3)

**Files:**
- Create: `database/migrations/2026_11_01_000035_add_sequence_tail_to_ecf_annulment_ranges_table.php`
- Modify: `app/Models/EcfAnnulmentRange.php`, `app/Services/Ecf/EcfSequenceAnnulmentService.php`, `app/Http/Controllers/Fiscal/EcfAnnulmentController.php`, `app/Http/Requests/Admin/UpdateNcfSequenceRequest.php`
- Test: `tests/Feature/Ecf/EcfAnnulmentTest.php`

**Interfaces:**
- Produces:
  - **Columns.** `ecf_annulment_ranges.ncf_sequence_id` (nullable FK `ncf_sequences`, `nullOnDelete`) and `ecf_annulment_ranges.original_range_to` (nullable `unsignedBigInteger`).
  - **Model.** `EcfAnnulmentRange` adds both to `$fillable`, casts `range_from`, `range_to` and `original_range_to` to `integer`, and adds `sequence(): BelongsTo<NcfSequence, $this>` on `ncf_sequence_id`.
  - **`EcfSequenceAnnulmentService::deleteRange(EcfAnnulmentRange $range, User $actor): void`.** Throws `EcfException`.
  - **Private `restoreTail(EcfAnnulmentRange $range): void`.**
  - **`advanceSequences()` is deleted.**

- [ ] **Step 1: Failing tests** — edit `tests/Feature/Ecf/EcfAnnulmentTest.php`. Add `use App\Exceptions\FiscalNumberingException;`. Add a file-level helper after `beforeEach`:
```php
/** Caps the test-environment sequence of the type at the given end so tail ranges stay small. */
function capSequence(string $type, int $rangeTo): NcfSequence
{
    $sequence = NcfSequence::query()->where('ncf_type', $type)->where('environment', 'test')->firstOrFail();
    $sequence->update(['range_to' => $rangeTo]);

    return $sequence;
}
```
Replace the whole test `a pending range is refused when it contains used e-ncfs, lies outside a sequence or overlaps another pending range` with:
```php
test('a range is refused unless it is the unused tail of a sequence of the environment', function () {
    $actor = User::factory()->create();
    issuedInvoice(); // consumes E310000000001
    capSequence('E31', 10);
    $service = app(EcfSequenceAnnulmentService::class);
    $tailOnly = 'Solo se puede anular el tramo final sin usar de la secuencia: el rango debe empezar en E310000000002 o después y terminar en E310000000010.';
    expect(fn () => $service->addRange(NcfType::E31, 1, 10, 'sobrantes', $actor))->toThrow(EcfException::class, $tailOnly);
    expect(fn () => $service->addRange(NcfType::E31, 2, 5, 'x', $actor))->toThrow(EcfException::class, $tailOnly);
    expect(fn () => $service->addRange(NcfType::E31, 20000000, 20000005, 'x', $actor))->toThrow(EcfException::class, 'El rango no pertenece a ninguna secuencia E31 registrada para este ambiente.');
    expect(fn () => $service->addRange(NcfType::B01, 2, 5, 'x', $actor))->toThrow(EcfException::class, 'Solo se anulan secuencias electrónicas.');
    $service->addRange(NcfType::E31, 6, 10, 'sobrantes', $actor);
    expect($service->pendingRanges())->toHaveCount(1);
});

test('adding a tail range shrinks the sequence so its numbers are never issued', function () {
    $actor = User::factory()->create();
    $sequence = capSequence('E31', 10);
    $range = app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 8, 10, 'sobrantes', $actor);
    $sequence->refresh();
    expect($sequence->range_to)->toBe(7)->and($range->original_range_to)->toBe(10)->and($range->ncf_sequence_id)->toBe($sequence->id)->and($sequence->exhausted_at)->toBeNull();
    $sequence->update(['next_number' => 7]);
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, today())->ncf)->toBe('E310000000007');
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, today()))->toThrow(FiscalNumberingException::class, 'La secuencia de E31 está agotada');
    $whole = capSequence('E34', 3);
    app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E34, 1, 3, 'nc no usadas', $actor);
    expect($whole->fresh()->range_to)->toBe(0)->and($whole->fresh()->exhausted_at)->not->toBeNull();
});

test('deleting a pending tail range restores the end of the sequence', function () {
    $sequence = capSequence('E31', 10);
    $range = app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 1, 10, 'x', User::factory()->create());
    expect($sequence->fresh()->exhausted_at)->not->toBeNull();
    actingAsRole('accounting');
    $this->delete(route('fiscal.ecf-annulments.ranges.destroy', $range))->assertRedirect(route('fiscal.ecf-annulments.index'))->assertSessionHas('success', 'Rango eliminado');
    expect($sequence->fresh()->range_to)->toBe(10)->and($sequence->fresh()->exhausted_at)->toBeNull()->and(EcfAnnulmentRange::query()->whereKey($range->id)->exists())->toBeFalse()
        ->and(Activity::inLog('ecf_annulments')->where('event', 'range_deleted')->exists())->toBeTrue();
});

test('a sequence whose tail is under annulment cannot be extended', function () {
    $sequence = capSequence('E31', 10);
    app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 6, 10, 'sobrantes', User::factory()->create());
    actingAsRole('owner');
    $this->put(route('admin.ncf-sequences.update', $sequence), validNcfSequencePayload(['ncf_type' => 'E31', 'range_from' => 1, 'range_to' => 10]))
        ->assertSessionHasErrors(['range_to' => 'El final de esta secuencia está en anulación ante la DGII; no se puede ampliar.']);
    expect($sequence->fresh()->range_to)->toBe(5);
});
```
Rewrite these existing tests (bodies change as listed; everything not mentioned stays):
- `sending builds a signed anecf that validates against the xsd, posts it and advances the sequence`:
  - Rename it to `sending builds a signed anecf that validates against the xsd, posts it and keeps the sequence capped`.
  - Insert `capSequence('E31', 10); capSequence('E34', 3);` before `$service = ...`.
  - Replace the `->and(NcfSequence::...value('next_number'))->toBe(11)` link with `->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('range_to'))->toBe(0)`.
  - Replace the final line with `expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, today()))->toThrow(FiscalNumberingException::class, 'La secuencia de E31 está agotada');`.
- `a dgii rejection keeps the ranges pending for a new attempt`: rename it to `a dgii rejection restores the sequence end, keeps the tail range as history and returns cancelled numbers to pending`. Body after `Http::fake(...)`:
```php
    $invoice = issuedInvoice(); // E310000000001
    app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'dup');
    $single = EcfAnnulmentRange::query()->whereNotNull('ecf_document_id')->firstOrFail();
    capSequence('E31', 10);
    $actor = User::factory()->create();
    $service = app(EcfSequenceAnnulmentService::class);
    $tail = $service->addRange(NcfType::E31, 2, 10, 'x', $actor);
    $annulment = $service->send([$single->id, $tail->id], $actor);
    $sequence = NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->firstOrFail();
    expect($annulment->status)->toBe('rejected')->and($annulment->response['mensajes'][0])->toContain('utilizadas')
        ->and($tail->fresh()->ecf_annulment_id)->toBe($annulment->id)->and($single->fresh()->ecf_annulment_id)->toBeNull()
        ->and($service->pendingRanges()->pluck('id')->all())->toBe([$single->id])
        ->and($sequence->range_to)->toBe(10)->and($sequence->next_number)->toBe(2)->and($sequence->exhausted_at)->toBeNull();
```
- `a provider failure leaves the ranges pending and the annulment marked failed`: rename it to `a provider failure marks the annulment failed and restores the sequence end`. Insert `capSequence('E31', 10);` before `$actor = ...`. Replace the final `expect(...)` with:
```php
    $failed = EcfAnnulment::query()->where('status', 'failed')->firstOrFail();
    expect($range->fresh()->ecf_annulment_id)->toBe($failed->id)->and(app(EcfSequenceAnnulmentService::class)->pendingRanges())->toHaveCount(0)
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('range_to'))->toBe(10);
```
- `the annulments page lists pending ranges including invoices cancelled before send and requires the annul permission`:
  - Insert `capSequence('E31', 10);` right after the `CancelInvoice` line.
  - In the first `ranges.store` post replace `'range_from' => 2, 'range_to' => 4` with `'range_from' => 2, 'range_to' => 10`.
- `a rejected annulment keeps its counts in the history`: insert `capSequence('E31', 10);` before `$actor = ...`.

Run: `php artisan test --compact tests/Feature/Ecf/EcfAnnulmentTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Migration** `2026_11_01_000035_add_sequence_tail_to_ecf_annulment_ranges_table.php`.
  - `up`: `Schema::table('ecf_annulment_ranges', ...)` with `$table->foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete();` and `$table->unsignedBigInteger('original_range_to')->nullable();`.
  - `down`: `$table->dropConstrainedForeignId('ncf_sequence_id'); $table->dropColumn('original_range_to');`.

  Then run `php artisan migrate --no-interaction 2>&1 | tail -2`. Expected: `DONE`.
- [ ] **Step 3: Model** — as in Interfaces.
- [ ] **Step 4: `EcfSequenceAnnulmentService`**
  - `addRange`:
    1. Replace the `$covered = ...->exists()` query with the same query ending `->orderBy('range_from')->first()` into `$sequence`. When it is `null`, throw the existing "no pertenece" message.
    2. Then, when `$from < $sequence->next_number || $to !== (int) $sequence->range_to`, throw `EcfException("Solo se puede anular el tramo final sin usar de la secuencia: el rango debe empezar en {$this->encf($type, $sequence->next_number)} o después y terminar en {$this->encf($type, (int) $sequence->range_to)}.")`.
    3. The existing `firstUsedEncf` and `overlapsAnnulment` checks stay, in that order.
    4. Create the range with the extra fields `'ncf_sequence_id' => $sequence->id, 'original_range_to' => (int) $sequence->range_to`.
    5. Then set `$sequence->range_to = $from - 1`. If `$sequence->next_number > $sequence->range_to`, set `$sequence->exhausted_at = now()`. Save.
    6. Add `'original_range_to'` to the activity properties.

    Update the method docblock to name the tail rule (GC-25).
  - `restoreTail()`:
    1. Return if `ncf_sequence_id` or `original_range_to` is null.
    2. Load the sequence `whereKey(...)->lockForUpdate()->first()`. Return if it is null, or if `(int) $sequence->range_to !== $range->range_from - 1`.
    3. Set `range_to = original_range_to`. If `next_number <= range_to`, set `exhausted_at = null`. Save.

    PHPDoc: "Gives a released tail back to its sequence; skipped when a later tail already shrank the sequence further (those numbers then stay unused, never reissued)."
  - `deleteRange()` runs in `DB::transaction`:
    1. `lockElectronicSequences($range->environment)`.
    2. `$pending = EcfAnnulmentRange::query()->whereKey($range->id)->whereNull('ecf_annulment_id')->lockForUpdate()->first()`.
    3. If it is null, throw `EcfException('Solo se eliminan rangos pendientes')`.
    4. If `$pending->ecf_document_id !== null`, throw `EcfException('Este rango proviene de una factura anulada y debe enviarse a la DGII.')`.
    5. Call `restoreTail($pending)`.
    6. Log the existing `range_deleted` activity: move it from the controller, keep the same log name, event, properties and description, and use `causedBy($actor)`.
    7. `$pending->delete()`.
  - `send()`, failure transaction: start with `$this->lockElectronicSequences($environment->value);`. For each range: when `ecf_document_id !== null`, `update(['ecf_annulment_id' => null])` (as today); otherwise call `restoreTail($range)` and keep the link.
  - `send()`, result transaction: on acceptance, delete the `advanceSequences` call (the method is removed). On rejection, apply the same per-range rule as in the failure path.
  - Delete `advanceSequences()` and its docblock.
- [ ] **Step 5: `EcfAnnulmentController::destroyRange`.** Replace the transaction with:

  ```php
  try { $annulments->deleteRange($range, $this->actor($request)); } catch (EcfException $e) { return redirect()->route('fiscal.ecf-annulments.index')->with('error', $e->getMessage()); }
  return redirect()->route('fiscal.ecf-annulments.index')->with('success', 'Rango eliminado');
  ```

  Inject `EcfSequenceAnnulmentService $annulments` as a method parameter and import `App\Exceptions\EcfException`. Remove the `DB` import if it is no longer used.
- [ ] **Step 6: `UpdateNcfSequenceRequest::withValidator`.** Add a second `$validator->after(...)` closure, independent of the existing one:
  - when `$sequence instanceof NcfSequence`, `$this->integer('range_to') > (int) $sequence->range_to`, and `EcfAnnulmentRange::query()->where('ncf_sequence_id', $sequence->id)->where(fn (Builder $q) => $q->whereNull('ecf_annulment_id')->orWhereHas('annulment', fn (Builder $a) => $a->where('status', 'accepted')))->exists()`,
  - then add the error `range_to` → `'El final de esta secuencia está en anulación ante la DGII; no se puede ampliar.'`.
- [ ] **Step 7: Gate.** Expected: passed (**619**).
- [ ] **Step 8: Commit** — `fix(ecf): annul only unused sequence tails and shrink the sequence so annulled e-ncfs are never issued`

---

### Task 4: Expired certificates are refused; 30-day expiry alert (I4)

**Files:**
- Modify: `app/Services/Ecf/CertificateStore.php`, `app/Http/Requests/Admin/UpdateEcfSettingsRequest.php`, `app/Http/Controllers/Fiscal/EcfCertificationController.php`, `app/Http/Controllers/Fe/ReceptionController.php`, `app/Jobs/CheckEcfAlerts.php`, `app/Notifications/EcfAlert.php`
- Test: `tests/Feature/Ecf/CertificateStoreTest.php`, `tests/Feature/Sales/InvoiceIssueEcfTest.php`, `tests/Feature/Ecf/EcfJobsTest.php`, `tests/Feature/Ecf/ReceiverEndpointsTest.php`

**Interfaces:**
- Produces:
  - **`CertificateStore::current(): ?EcfCertificate`.** The newest active row, expired or not. It is used for display, readiness, alerts and the receiver guard.
  - **`CertificateStore::active(): ?EcfCertificate`.** `current()`, but it throws `EcfCertificateException('El certificado digital venció el '.$certificate->valid_to->copy()->setTimezone('America/Santo_Domingo')->format('d/m/Y').'.')` when that certificate `isExpired()`.
  - **`requireActive()`.** Unchanged (`active() ?? throw ...`).
  - **`EcfAlert::__construct(array $pending, array $rejected, ?array $certificate = null)`.** `$certificate` has the shape `array{name: string, expires_on: string, days_left: int, expired: bool}`.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Ecf/CertificateStoreTest.php` (import `App\Exceptions\EcfCertificateException` and `App\Services\Ecf\CertificateStore` if they are missing):
```php
test('an expired active certificate is refused for signing but still reported as current', function () {
    $certificate = activeCertificate();
    $certificate->forceFill(['valid_to' => now()->subDay()])->save();
    $store = app(CertificateStore::class);
    $expected = 'El certificado digital venció el '.$certificate->fresh()->valid_to->setTimezone('America/Santo_Domingo')->format('d/m/Y').'.';
    expect(fn () => $store->active())->toThrow(EcfCertificateException::class, $expected)
        ->and(fn () => $store->requireActive())->toThrow(EcfCertificateException::class, $expected)
        ->and($store->current()->is($certificate))->toBeTrue();
});
```
Append to `tests/Feature/Sales/InvoiceIssueEcfTest.php`:
```php
test('issuing in ecf mode with an expired certificate is refused before any e-ncf is used', function () {
    ecfMode();
    activeCertificate()->forceFill(['valid_to' => now()->subDay()])->save();
    $actor = User::factory()->create();
    $draft = app(SaveInvoice::class)->handle(validInvoicePayload(['salesperson_id' => Salesperson::factory()->create()->id]), $actor->givePermissionTo('sales.invoices.view-all'));
    foreach ($draft->lines as $line) {
        app(InventoryService::class)->receive(new StockLine($line->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $actor, $actor);
    }
    $next = NcfSequence::query()->where('ncf_type', 'E31')->value('next_number');
    expect(fn () => app(IssueInvoice::class)->handle($draft, $actor))->toThrow(EcfCertificateException::class, 'El certificado digital venció el');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft)->and(NcfSequence::query()->where('ncf_type', 'E31')->value('next_number'))->toBe($next)->and(EcfDocument::count())->toBe(0);
});
```
Append to `tests/Feature/Ecf/EcfJobsTest.php` (add `use App\Models\EcfCertificate;`):
```php
test('the daily alert warns when the active certificate expires within thirty days', function () {
    Notification::fake();
    $owner = actingAsRole('owner');
    EcfCertificate::query()->update(['valid_to' => now()->addDays(40)]);
    (new CheckEcfAlerts)->handle(app(EcfService::class));
    Notification::assertNothingSent();
    EcfCertificate::query()->update(['valid_to' => now()->addDays(10)]);
    (new CheckEcfAlerts)->handle(app(EcfService::class));
    Notification::assertSentTo($owner, EcfAlert::class, fn (EcfAlert $n) => $n->certificate['days_left'] === 10 && $n->certificate['expired'] === false
        && $n->certificate['expires_on'] === now()->addDays(10)->format('d/m/Y') && $n->pending === []);
    $mail = (new EcfAlert([], [], ['name' => 'Cert', 'expires_on' => '10/11/2026', 'days_left' => 10, 'expired' => false]))->toMail($owner);
    $expiredMail = (new EcfAlert([], [], ['name' => 'Cert', 'expires_on' => '01/10/2026', 'days_left' => -29, 'expired' => true]))->toMail($owner);
    expect($mail->introLines)->toContain('El certificado digital Cert vence el 10/11/2026 (en 10 días); carga uno nuevo en Configuración → Facturación electrónica.')
        ->and($expiredMail->introLines)->toContain('El certificado digital Cert venció el 01/10/2026; la emisión electrónica está detenida hasta que cargues uno vigente.');
});
```
Append to `tests/Feature/Ecf/ReceiverEndpointsTest.php`:
```php
test('the receiver answers 503 while the active certificate is expired', function () {
    EcfCertificate::query()->update(['valid_to' => now()->subDay()]);
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml())])->assertStatus(503);
    expect(ReceivedEcf::count())->toBe(0);
});
```
Run: `php artisan test --compact --filter='CertificateStoreTest|InvoiceIssueEcfTest|EcfJobsTest|ReceiverEndpointsTest' 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `CertificateStore`** — as in Interfaces. `current()` holds today's `active()` query. Docblock on `active()`: `@throws EcfCertificateException when the active certificate is expired`.
- [ ] **Step 3: Callers that only display or guard switch to `current()`.**
  - `UpdateEcfSettingsRequest` line 58.
  - `EcfCertificationController::readiness` line 96.
  - `ReceptionController::available()` becomes `$certificate = $this->certificates->current(); return $this->settings->get('ecf.provider') === 'dgii' && $certificate !== null && ! $certificate->isExpired();`.

  Signing callers keep `active()`/`requireActive()`. Grep to confirm: `grep -rn "certificates->active()\|->requireActive()\|CertificateStore::class)->active()" app` must list only providers, `DgiiClient`, `ReceivedEcfService`, `CommercialApprovalService` and `EcfSequenceAnnulmentService`.
- [ ] **Step 4: `EcfAlert`**
  - Add `public readonly ?array $certificate = null` (PHPDoc shape as in Interfaces).
  - In `toMail`, after the rejected lines, when `$this->certificate !== null`, add one line:
    - if `expired`: `"El certificado digital {$name} venció el {$expires_on}; la emisión electrónica está detenida hasta que cargues uno vigente."`;
    - otherwise: `"El certificado digital {$name} vence el {$expires_on} (en {$days_left} días); carga uno nuevo en Configuración → Facturación electrónica."`.
  - `toArray` adds `'certificate' => $this->certificate`.
- [ ] **Step 5: `CheckEcfAlerts::handle`**
  - Compute `$certificate = app(CertificateStore::class)->current()`.
  - `$certificateAlert` is `null` unless `$certificate !== null` and `$certificate->valid_to <= now()->addDays(30)`. In that case it is `['name' => $certificate->name, 'expires_on' => $validTo->format('d/m/Y'), 'days_left' => (int) CarbonImmutable::now(config('ecf.timezone'))->startOfDay()->diffInDays($validTo->startOfDay(), false), 'expired' => $certificate->isExpired()]`, with `$validTo = CarbonImmutable::instance($certificate->valid_to)->setTimezone(config('ecf.timezone'))`.
  - Add `'certificate_days_left' => $certificateAlert['days_left'] ?? null` to the `alerts_checked` properties.
  - The early return becomes `$pending === [] && $rejected === [] && $certificateAlert === null`.
  - Pass the alert as the third `EcfAlert` argument.
- [ ] **Step 6: Gate.** Expected: passed (**623**).
- [ ] **Step 7: Commit** — `fix(ecf): refuse expired certificates for signing and alert 30 days before expiry`

---

### Task 5: The reception endpoint always answers an ARECF; DOCTYPE checked after parsing (I5, minor 2)

**Files:**
- Modify: `app/Services/Ecf/ReceivedEcfService.php`
- Test: `tests/Feature/Ecf/ReceiverEndpointsTest.php`

**Interfaces:**
- Produces:
  - **`ReceivedEcfService::receive()`.** It never throws because of storage: a `Throwable` from `storeReceipt` is `report()`ed and the ARECF is still returned.
  - **Refused receipts** (`$reason !== null`) store only the identity. `razon_social_emisor`, `rnc_comprador` and `fecha_emision` are `null`; `monto_total` and `total_itbis` are `'0.00'`.
  - **`date()`** returns `null` for any unparseable value.
  - **`parse()`** returns `null` when the parsed document has a `doctype`.

- [ ] **Step 1: Failing tests** — append to `tests/Feature/Ecf/ReceiverEndpointsTest.php`:
```php
test('a refused document with a bad fecha emision or an oversized total is answered with an arecf and stored with its identity only', function () {
    $badDate = preg_replace('/<FechaEmision>[^<]*<\/FechaEmision>/', '<FechaEmision>2026-10-30</FechaEmision>', supplierEcfXml(['encf' => 'E310000000781']), 1);
    $hugeTotal = preg_replace('/<MontoTotal>[^<]*<\/MontoTotal>/', '<MontoTotal>99999999999999999.99</MontoTotal>', supplierEcfXml(['encf' => 'E310000000782']), 1);
    foreach ([$badDate, $hugeTotal] as $xml) {
        $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $xml)])->assertOk()->assertHeader('content-type', 'application/xml');
        $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
        expect($x->evaluate('string(//Estado)'))->toBe('1');
    }
    $rows = ReceivedEcf::query()->orderBy('encf')->get();
    expect($rows)->toHaveCount(2)->and($rows->first()->arecf_reason_code)->toBe(1)
        ->and($rows->pluck('fecha_emision')->filter()->all())->toBe([])->and($rows->pluck('rnc_comprador')->filter()->all())->toBe([])
        ->and($rows->pluck('monto_total')->all())->toBe(['0.00', '0.00']);
});

test('a storage failure while saving a receipt still answers the arecf', function () {
    Storage::disk('local')->put('ecf/received/'.now()->year.'/101010101E310000000780.xml', 'stale');
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => 'E310000000780']))])
        ->assertOk()->assertHeader('content-type', 'application/xml');
    expect(ReceivedEcf::count())->toBe(0);
});

test('a utf-16 document with a doctype is refused after parsing without being stored', function () {
    $utf8 = preg_replace('/^<\?xml[^>]*\?>/', '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE ECF>', supplierEcfXml(['encf' => 'E310000000783']), 1);
    $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', mb_convert_encoding($utf8, 'UTF-16', 'UTF-8'))])->assertOk();
    $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
    expect($x->evaluate('string(//Estado)'))->toBe('1')->and($x->evaluate('string(//CodigoMotivoNoRecibido)'))->toBe('1')->and(ReceivedEcf::count())->toBe(0);
});
```
Run: `php artisan test --compact tests/Feature/Ecf/ReceiverEndpointsTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Implement**
  - In `receive()`, wrap the `storeReceipt(...)` call: `try { $received = $this->storeReceipt(...); } catch (Throwable $exception) { report($exception); }`. Import `Throwable`.
  - In `storeReceipt()`, add `$isRefused = $reason !== null;` and use ternaries so the five extracted fields take the refused values listed in Interfaces when `$isRefused`.
  - `date()`: `try { $parsed = CarbonImmutable::createFromFormat('d-m-Y', $raw, self::TIMEZONE); } catch (Throwable) { return null; } return $parsed instanceof CarbonImmutable ? $parsed->toDateString() : null;`.
  - `parse()`: keep the raw regex. Change the final line to `return $loaded && $document->doctype === null ? $document : null;`, and update the docblock to "Null for DOCTYPE declarations (checked on the raw bytes and again after parsing, for UTF-16/32 input) and for malformed XML."
- [ ] **Step 3: Gate.** Expected: passed (**626**).
- [ ] **Step 4: Commit** — `fix(ecf): always answer the arecf, store only the identity of refused documents and check the doctype after parsing`

---

### Task 6: Rate limit, size cap and refused-storage cap on the public `/fe` routes (I6)

**Files:**
- Create: `app/Http/Middleware/LimitFeRequestSize.php`
- Modify: `config/ecf.php`, `app/Providers/AppServiceProvider.php`, `routes/fe.php`, `app/Services/Ecf/ReceivedEcfService.php`
- Test: `tests/Feature/Ecf/ReceiverEndpointsTest.php`

**Interfaces:**
- Produces:
  - **Config.** `config('ecf.receiver')` is `['rate_per_minute' => 60, 'max_bytes' => 1048576, 'refused_per_ip_per_day' => 100]`. Task 7 adds a key here.
  - **Limiter.** The named rate limiter `fe` is `Limit::perMinute((int) config('ecf.receiver.rate_per_minute'))->by((string) $request->ip())`.
  - **Middleware.** `LimitFeRequestSize::handle(Request $request, Closure $next): Response` answers `response('Solicitud demasiado grande', 413)` when the largest of these values exceeds `config('ecf.receiver.max_bytes')`:
    - `(int) $request->server('CONTENT_LENGTH', 0)`;
    - `strlen((string) $request->getContent())`;
    - the `xml` upload's `getSize()`;
    - `strlen` of a string `xml` input.

- [ ] **Step 1: Failing tests** — append to `tests/Feature/Ecf/ReceiverEndpointsTest.php`:
```php
test('the public receiver endpoints are rate limited per ip', function () {
    config(['ecf.receiver.rate_per_minute' => 2]);
    $this->post('/fe/recepcion/api/ecf', ['xml' => 'not xml'])->assertOk();
    $this->post('/fe/recepcion/api/ecf', ['xml' => 'not xml'])->assertOk();
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => 'not xml'])->assertStatus(429);
});

test('requests larger than the receiver limit are refused with 413', function () {
    config(['ecf.receiver.max_bytes' => 1000]);
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', str_repeat('a', 1001))])->assertStatus(413);
    $this->post('/fe/recepcion/api/ecf', ['xml' => str_repeat('a', 1001)])->assertStatus(413);
    expect(ReceivedEcf::count())->toBe(0);
});

test('refused documents stop being stored after the daily limit per ip', function () {
    config(['ecf.receiver.refused_per_ip_per_day' => 1]);
    foreach (['E310000000790', 'E310000000791'] as $encf) {
        $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => $encf, 'comprador_rnc' => '999999999']))])->assertOk();
    }
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => 'E310000000792']))])->assertOk();
    expect(ReceivedEcf::query()->where('arecf_status', 1)->count())->toBe(1)->and(ReceivedEcf::query()->where('arecf_status', 0)->count())->toBe(1);
});
```
Run: `php artisan test --compact tests/Feature/Ecf/ReceiverEndpointsTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Config.** Add the `receiver` array to `config/ecf.php` after `pending_alert_hours`, with the comment `// Public /fe endpoints (GC-26).`
- [ ] **Step 3: Limiter.** In `AppServiceProvider`, add `private function configureRateLimiting(): void` registering `RateLimiter::for('fe', ...)` as in Interfaces, and call it from `boot()` after `self::configureTrustedProxies();`. Imports: `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter`.
- [ ] **Step 4: Middleware and routes.** Create the middleware (PHPDoc: "Caps anonymous DGII/PSFE requests before any signature or XSD work (GC-26)."). In `routes/fe.php`, wrap the three existing `Route::post(...)` lines, unchanged, in `Route::middleware(['throttle:fe', LimitFeRequestSize::class])->group(function () { ... });`.
- [ ] **Step 5: Refused-storage cap.**
  - In `ReceivedEcfService::receive()`, the storing condition becomes `$document !== null && $identity !== null && ! $duplicate && ($reason === null || ! $this->refusedLimitReached($senderIp))`.
  - Add `private function refusedLimitReached(?string $senderIp): bool`. It returns `$senderIp !== null && ReceivedEcf::query()->where('sender_ip', $senderIp)->where('arecf_status', 1)->where('received_at', '>=', now()->subDay())->count() >= (int) config('ecf.receiver.refused_per_ip_per_day')`.
- [ ] **Step 6: Verify routes.** `php artisan route:list --name=fe. -v 2>&1 | grep -c "throttle:fe"`. Expected: `3`. If `-v` does not show middleware on this version, run `php artisan route:list --name=fe. --json | grep -o "throttle:fe" | wc -l` instead. Expected: `3`.
- [ ] **Step 7: Gate.** Expected: passed (**629**).
- [ ] **Step 8: Commit** — `fix(ecf): rate limit and size cap the public receiver endpoints and cap stored refusals per ip`

---

### Task 7: Signer identity of received e-CF and incoming ACECF (I7, minimal)

**Files:**
- Modify: `app/Services/Ecf/XmlSigner.php`, `app/Services/Ecf/ReceivedEcfService.php`, `config/ecf.php`, `tests/Support/helpers.php`
- Test: `tests/Feature/Ecf/ReceiverEndpointsTest.php`

**Interfaces:**
- Produces:
  - **`XmlSigner::signerTaxId(DOMDocument $document): ?string`.** It reads the digits of the subject `serialNumber` of the first `X509Certificate` (namespace `XMLSecurityDSig::XMLDSIGNS`). Build the PEM as `"-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $base64), 64, "\n")."-----END CERTIFICATE-----\n"` and use `openssl_x509_parse`. A repeated attribute is a list: take its first value. It returns `null` when the certificate is missing or unreadable or the serial is empty, and never throws.
  - **Config.** `config('ecf.receiver.verify_signer_tax_id')`, default `(bool) env('ECF_VERIFY_SIGNER_TAX_ID', true)`.
  - **Helpers.** `supplierEcfXml(array $over)` accepts `signer_rnc` (default `'101010101'`).

- [ ] **Step 1: Helpers and failing tests**
  - In `tests/Support/helpers.php`, in `supplierEcfXml`, change `fakeP12('x', '101010101')` to `fakeP12('x', $over['signer_rnc'] ?? '101010101')` and extend the PHPDoc shape with `signer_rnc?: string`.
  - In `tests/Feature/Ecf/ReceiverEndpointsTest.php`, add `use App\Support\FiscalId;` and replace `signedAcecfFor` with:
```php
/** Writes an ACECF about one of our e-CF, naming $buyerRnc (default: the document's client) and signed by a fake certificate of $signerRnc (default: the buyer). */
function signedAcecfFor(EcfDocument $document, int $estado, ?string $motivo, ?string $buyerRnc = null, ?string $signerRnc = null): string
{
    $buyerRnc ??= FiscalId::normalize((string) $document->document->client_tax_id);
    openssl_pkcs12_read(fakeP12('x', $signerRnc ?? $buyerRnc), $bundle, 'x');
    $acecf = app(EcfXmlWriter::class)->writeAcecf('131880738', $document->encf, CarbonImmutable::now(), '1180.00', $buyerRnc, $estado, $motivo, CarbonImmutable::now());

    return app(XmlSigner::class)->signWithPem($acecf, $bundle['cert'], $bundle['pkey'])->xml;
}
```
  - Append:
```php
test('a supplier e-cf signed with the certificate of another taxpayer is refused with motivo 2', function () {
    $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => 'E310000000784', 'signer_rnc' => '202020202']))])->assertOk();
    $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
    expect($x->evaluate('string(//Estado)'))->toBe('1')->and($x->evaluate('string(//CodigoMotivoNoRecibido)'))->toBe('2')
        ->and(ReceivedEcf::query()->where('arecf_status', 0)->count())->toBe(0);
});

test('an incoming commercial approval must be signed by and name the buyer of our e-cf', function () {
    Bus::fake([SubmitEcfDocument::class]);
    $doc = issuedInvoice()->ecfDocument;
    $buyer = FiscalId::normalize((string) $doc->document->client_tax_id);
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', signedAcecfFor($doc, 1, null, $buyer, '202020202'))])->assertStatus(400);
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', signedAcecfFor($doc, 1, null, '202020202', '202020202'))])->assertStatus(400);
    expect($doc->fresh()->commercial_status)->not->toBe('approved');
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', signedAcecfFor($doc, 1, null))])->assertOk();
    expect($doc->fresh()->commercial_status)->toBe('approved');
});
```
Run: `php artisan test --compact tests/Feature/Ecf/ReceiverEndpointsTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Config.** Add `'verify_signer_tax_id' => (bool) env('ECF_VERIFY_SIGNER_TAX_ID', true),` to `ecf.receiver`, with the comment `// RES §3: the certificate SN may be the delegate's cédula; set false during certification if real supplier certificates do not carry the RNC.`
- [ ] **Step 3: `XmlSigner::signerTaxId`** — as in Interfaces.
- [ ] **Step 4: `ReceivedEcfService`**
  - Add `private function requiresSignerMatch(): bool { return (bool) config('ecf.receiver.verify_signer_tax_id'); }`.
  - `refusalReason`: right after the `verify()` check returns, `if ($this->requiresSignerMatch() && $this->signer->signerTaxId($document) !== $identity['rnc']) { return 2; }`.
  - `applyCommercialApproval`:
    1. After reading `$estado`, read `$rncComprador = $this->text($xpath, '/ACECF/DetalleAprobacionComercial/RNCComprador');`.
    2. Extend the existing refusal `if` with `|| ($this->requiresSignerMatch() && $this->signer->signerTaxId($document) !== $rncComprador)`.
    3. After `$ecfDocument === null` returns, add `if (FiscalId::normalize((string) $this->buyerTaxId($ecfDocument)) !== $rncComprador) { return false; }`.
  - Add `private function buyerTaxId(EcfDocument $ecfDocument): ?string`:
    - `Invoice` → `client_tax_id`;
    - `CreditNote`/`DebitNote` → `$document->invoice?->client_tax_id`;
    - anything else → `null`.

    Import `App\Models\Invoice`, `CreditNote` and `DebitNote`.
- [ ] **Step 5: Gate.** Expected: passed (**631**). `CommercialApprovalTest` must stay green: its supplier is signed by `101010101` = `RNCEmisor`.
- [ ] **Step 6: Commit** — `fix(ecf): require the signing certificate to belong to the issuer of a received e-cf and to the buyer of an incoming acecf`

---

### Task 8: Secret settings never reach the activity log; normalised certificate RNC warning (I8, minor 7)

**Files:**
- Modify: `app/Models/Setting.php`, `app/Http/Controllers/Admin/EcfSettingsController.php`
- Test: `tests/Feature/Admin/EcfSettingsTest.php`

**Interfaces:**
- Produces:
  - **`Setting::SECRET_KEYS`** = `['ecf.alanube.api_key', 'ecf.alanube.webhook_key']`.
  - **`Setting::getActivitylogOptions(): LogOptions`.** The trait method is aliased as `defaultActivitylogOptions` (`use LogsModelActivity { getActivitylogOptions as defaultActivitylogOptions; }`). When `in_array($this->getAttribute('key'), self::SECRET_KEYS, true)`, it returns `$options->logExcept(['password', 'remember_token', 'value'])`; otherwise it returns `$options` unchanged. Import `Spatie\Activitylog\Support\LogOptions`.

- [ ] **Step 1: Failing tests** — append to `tests/Feature/Admin/EcfSettingsTest.php`:
```php
test('secret settings never reach the activity log', function () {
    app(Settings::class)->set('ecf.alanube.webhook_key', 'hook-secret-123');
    app(Settings::class)->set('ecf.alanube.webhook_key', 'hook-secret-456');
    app(Settings::class)->set('ecf.alanube.api_key', Crypt::encryptString('api-secret-789'));
    app(Settings::class)->set('ecf.provider', 'alanube');
    $logged = Activity::inLog('settings')->get()->map(fn ($activity) => json_encode($activity->properties))->implode(' ');
    expect($logged)->not->toContain('hook-secret')->not->toContain('eyJ')->toContain('alanube');
});

test('the certificate rnc warning ignores dashes in the company rnc', function () {
    actingAsRole('owner');
    activeCertificate(); // subject serialNumber 131880738
    app(Settings::class)->set('company.rnc', '1-31-88073-8');
    $this->get(route('admin.ecf-settings.index'))->assertOk()->assertInertia(fn ($p) => $p->where('warnings', []));
    app(Settings::class)->set('company.rnc', '1-01-01010-1');
    $this->get(route('admin.ecf-settings.index'))->assertInertia(fn ($p) => $p->where('warnings.0', 'El certificado pertenece al RNC 131880738, distinto al RNC de la empresa 1-01-01010-1.'));
});
```
Run: `php artisan test --compact tests/Feature/Admin/EcfSettingsTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `Setting`** — as in Interfaces. PHPDoc on the constant: "Settings whose value never reaches the activity log (GC-18, GC-21)."
- [ ] **Step 3: `EcfSettingsController::warnings`.** Compare `$certificate->rnc !== FiscalId::normalize($companyRnc)` (import `App\Support\FiscalId`). The message keeps `{$companyRnc}` as stored.
- [ ] **Step 4: Gate.** Expected: passed (**633**).
- [ ] **Step 5: Commit** — `fix(ecf): keep secret settings out of the activity log and normalise the certificate rnc warning`

---

### Task 9: Stuck documents are swept again; job and queue timeouts (I9)

**Files:**
- Modify: `app/Jobs/RetryPendingEcfDocuments.php`, `app/Jobs/SubmitEcfDocument.php`, `app/Jobs/PollEcfStatus.php`, `config/queue.php`, `README.md`
- Test: `tests/Feature/Ecf/EcfJobsTest.php`

**Interfaces:**
- Consumes: the claim of Task 1 already sets `next_attempt_at` before the provider call.
- Produces:
  - **Sweep.** `RetryPendingEcfDocuments` also submits:
    - `pending` documents with `attempts = 0` (Alanube documents never submitted);
    - `pending|signed` rows with `next_attempt_at IS NULL`, `0 < attempts < config('ecf.max_attempts')` and `last_attempt_at <= now() - 10 minutes`.
  - **Timeouts.** `SubmitEcfDocument::$timeout` and `PollEcfStatus::$timeout` are `150`, declared as `public int $timeout = 150;`.
  - **Queue.** The default `queue.connections.database.retry_after` is `180`.

- [ ] **Step 1: Failing tests** — append to `tests/Feature/Ecf/EcfJobsTest.php`:
```php
test('the retry sweep also picks up documents left without a next attempt and alanube documents never submitted', function () {
    ecfSettings();
    $stuck = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 2, 'next_attempt_at' => null, 'last_attempt_at' => now()->subMinutes(15)]);
    $running = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 1, 'next_attempt_at' => null, 'last_attempt_at' => now()->subMinutes(5)]);
    $exhausted = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 48, 'next_attempt_at' => null, 'last_attempt_at' => now()->subHour()]);
    $alanube = EcfDocument::factory()->create(['provider' => 'alanube', 'status' => 'pending', 'attempts' => 0, 'created_at' => now()->subMinutes(5)]);
    Bus::fake([SubmitEcfDocument::class, PollEcfStatus::class]);
    (new RetryPendingEcfDocuments)->handle();
    Bus::assertDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($stuck));
    Bus::assertDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($alanube));
    Bus::assertNotDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($running));
    Bus::assertNotDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($exhausted));
    Bus::assertDispatchedTimes(SubmitEcfDocument::class, 2);
});

test('submit and poll jobs may run for 150 seconds and the database queue retries only after 180', function () {
    $doc = ecfDocFor('in_process');
    expect((new SubmitEcfDocument($doc))->timeout)->toBe(150)->and((new PollEcfStatus($doc))->timeout)->toBe(150)
        ->and(config('queue.connections.database.retry_after'))->toBe(180);
});
```
Run: `grep -n "DB_QUEUE_RETRY_AFTER" .env .env.example phpunit.xml`. Expected: no output. Otherwise STOP and report. Then run `php artisan test --compact tests/Feature/Ecf/EcfJobsTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `RetryPendingEcfDocuments::handle`**
  - `$firstSubmissions` uses `->whereIn('status', [EcfStatus::Pending->value, EcfStatus::Signed->value])` instead of `->where('status', Signed)`.
  - Add `$stuckSubmissions` as described in Interfaces.
  - `$submissions = $dueSubmissions->merge($firstSubmissions)->merge($stuckSubmissions);` (Eloquent merge de-duplicates by key). Dispatch for each.
  - `$submitted = $submissions->count();`. The poll part is unchanged.
- [ ] **Step 3: Timeouts.** Add `public int $timeout = 150;` under `$uniqueFor` in both jobs, with a one-line PHPDoc: "A DGII call chain (seed, validate, post, 401 refresh) can take up to 4 × 30 s."
- [ ] **Step 4: Queue and README.**
  - `config/queue.php`: in the `database` connection, change `env('DB_QUEUE_RETRY_AFTER', 90)` to `env('DB_QUEUE_RETRY_AFTER', 180)`.
  - `README.md` line 21: replace `--tries=3 --timeout=120` with `--tries=3 --timeout=150`. Append to the same bullet: ` Los envíos a la DGII pueden tardar hasta 150 s: mantén \`DB_QUEUE_RETRY_AFTER\` (180 por defecto) por encima de ese valor.`
- [ ] **Step 5: Gate.** Expected: passed (**635**).
- [ ] **Step 6: Commit** — `fix(ecf): sweep documents stuck after a lost attempt and give the dgii jobs a 150 s timeout`

---

### Task 10: Files left by a rolled-back issue are moved aside; RFCE validated before storing (I10)

**Files:**
- Modify: `app/Services/Ecf/EcfStorage.php`, `app/Services/Ecf/LocalXmlPreparer.php`, `app/Services/Ecf/Providers/AlanubeProvider.php`
- Test: `tests/Feature/Ecf/EcfServiceFakeTest.php`, `tests/Feature/Ecf/AlanubeProviderTest.php`

**Interfaces:**
- Produces: `EcfStorage::putForDocument(EcfDocument $document, string $fileName, string $contents): string`.
  - It writes `documentDir($document).'/'.$fileName`.
  - When a file already exists there, it first moves that file (`Storage::disk(config('ecf.storage_disk'))->move(...)`) to `documentDir($document).'/orphaned/'.Str::uuid()->toString().'-'.$fileName`, logs `Log::warning('Archivo e-CF huérfano movido', ['path' => $path])`, and then calls `putNew`.
  - PHPDoc: "Only for files written while the document row is being created. `ecf_documents` is unique on (encf, environment), so an existing file can only be a leftover of an issue whose transaction rolled back and released the e-NCF."

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Ecf/EcfServiceFakeTest.php`:
```php
test('a file left by a rolled back issue is moved aside instead of blocking the e-ncf', function () {
    ecfSettings();
    activeCertificate();
    $directory = 'ecf/test/'.now()->year.'/E310000000020';
    Storage::disk('local')->put("{$directory}/131880738E310000000020.xml", 'stale');
    $doc = app(EcfService::class)->create(issuedInvoice(), new FiscalNumber('E310000000020', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), null);
    $orphans = Storage::disk('local')->files("{$directory}/orphaned");
    expect($doc->xml_path)->toBe("{$directory}/131880738E310000000020.xml")->and(Storage::disk('local')->get($doc->xml_path))->toContain('<eNCF>E310000000020</eNCF>')
        ->and($orphans)->toHaveCount(1)->and(Storage::disk('local')->get($orphans[0]))->toBe('stale');
});
```
Append to `tests/Feature/Ecf/AlanubeProviderTest.php`:
```php
test('an alanube request left by a rolled back issue is moved aside', function () {
    $directory = 'ecf/test/'.now()->year.'/E310000000001';
    Storage::disk('local')->put("{$directory}/alanube-request.json", '{"stale":true}');
    $doc = issuedInvoice()->ecfDocument;
    expect($doc->encf)->toBe('E310000000001')->and(Storage::disk('local')->get("{$directory}/alanube-request.json"))->toContain('E310000000001')
        ->and(Storage::disk('local')->files("{$directory}/orphaned"))->toHaveCount(1);
});
```
Run: `php artisan test --compact --filter='EcfServiceFakeTest|AlanubeProviderTest' 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `EcfStorage::putForDocument`** — as in Interfaces. Imports: `Illuminate\Support\Str`, `Illuminate\Support\Facades\Log`.
- [ ] **Step 3: `LocalXmlPreparer::prepare`** — reorder so nothing is stored before both XMLs are valid:
  1. sign and validate the main XML;
  2. when `$document->flow === 'rfce'`, sign the RFCE (`writeRfce($data, $signed->securityCode)`) and validate it with `'rfce'` into `$rfce`;
  3. store `$document->xml_path = $this->storage->putForDocument($document, $data->fileName(), $signed->xml)`;
  4. if `$rfce`, store `$document->rfce_xml_path = $this->storage->putForDocument($document, "RFCE-{$data->fileName()}", $rfce->xml)` and set the RFCE QR; otherwise set the e-CF QR;
  5. `security_code`, `signed_at` and `status` stay as today.

  The `$directory` variable is removed.
- [ ] **Step 4: `AlanubeProvider::prepare`.** `$this->storage->putForDocument($document, self::REQUEST_FILE, $json);`.
- [ ] **Step 5: Gate.** Expected: passed (**637**). The test `immutable storage refuses to overwrite an existing file` must stay green, because `putNew` is unchanged.
- [ ] **Step 6: Commit** — `fix(ecf): move files left by a rolled back issue aside and validate the rfce before storing`

---

### Task 11: QR signing time in GMT-4; unknown ITBIS rates and total mismatches refused (minors 1, 3)

**Files:**
- Modify: `app/Support/Ecf/EcfQrUrl.php`, `app/Services/Ecf/EcfDocumentBuilder.php`
- Test: `tests/Feature/Ecf/QrCodeTest.php`, `tests/Feature/Ecf/EcfDocumentBuilderTest.php`

**Interfaces:**
- Produces:
  - **`EcfQrUrl::forEcf`** formats `fechafirma` from `CarbonImmutable::instance($fechaFirma)->setTimezone(config('ecf.timezone'))`.
  - **`EcfDocumentBuilder`:**
    - The private `indicadorFacturacion(string $itbisRate, int $lineNumber): int` maps 18 → 1, 16 → 2 and zero (`Decimal::isZero`) → 4. Any other rate throws `EcfException("La línea {$lineNumber} tiene una tasa de ITBIS de {$itbisRate} % que el e-CF no admite (solo 18 %, 16 % o exento).")`.
    - The private `assemble()` gains a last parameter, `string $documentTotal`. `fromInvoice` passes `(string) $invoice->total`; `fromCreditNote` and `fromDebitNote` pass `(string) $note->total`.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Ecf/QrCodeTest.php`:
```php
test('the qr signing time is printed in santo domingo time whatever timezone the instant carries', function () {
    $url = EcfQrUrl::forEcf(EcfEnvironment::Test, '131880738', null, 'E310000000001', CarbonImmutable::parse('2026-11-01'), '100.00', CarbonImmutable::parse('2026-11-01 02:30:00', 'UTC'), 'abcdef');
    expect($url)->toContain('fechafirma=31-10-2026%2022%3A30%3A00');
});
```
Append to `tests/Feature/Ecf/EcfDocumentBuilderTest.php`:
```php
test('an itbis rate other than 18, 16 or 0 is refused with the line number', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice();
    DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update(['itbis_rate' => '10.00']);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice->fresh(), NcfType::E31, 'E310000000001', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'La línea 1 tiene una tasa de ITBIS de 10.00 % que el e-CF no admite (solo 18 %, 16 % o exento).');
});

test('an e-cf whose total differs from the document total is refused', function () {
    companyFiscalSettings();
    $invoice = issuedInvoice(); // 2 × 100.00 at 18 % = 236.00
    DB::table('invoices')->where('id', $invoice->id)->update(['total' => '999.99']);
    expect(fn () => app(EcfDocumentBuilder::class)->fromInvoice($invoice->fresh(), NcfType::E31, 'E310000000001', CarbonImmutable::parse('2027-12-31')))
        ->toThrow(EcfException::class, 'El total del e-CF (236.00) no coincide con el total del documento (999.99).');
});
```
Run: `php artisan test --compact --filter='QrCodeTest|EcfDocumentBuilderTest' 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: `EcfQrUrl::forEcf`** — as in Interfaces. Import `Carbon\CarbonImmutable`.
- [ ] **Step 3: `EcfDocumentBuilder`**
  - In `rows()`, call `$this->indicadorFacturacion((string) $line->itbis_rate, (int) $line->line_number)`.
  - In `assemble()`, store `$totals = $this->totals($rows)` and pass it to `EcfDocumentData`. Then compute `$documentCurrencyTotal = $foreign !== null ? $foreign->totals->montoTotal : $totals->montoTotal;`. If `Decimal::cmp($documentCurrencyTotal, Decimal::money($documentTotal)) !== 0`, throw `EcfException("El total del e-CF ({$documentCurrencyTotal}) no coincide con el total del documento (".Decimal::money($documentTotal).').')`.
  - Update the class docblock list of refusals to include "unknown ITBIS rate, totals that differ from the document".
- [ ] **Step 4: Gate.** Expected: passed (**640**). If any pre-existing test now fails with "no coincide con el total del documento", STOP and report the document and both totals. Do not loosen the check.
- [ ] **Step 5: Commit** — `fix(ecf): print the qr signing time in gmt-4 and refuse unknown itbis rates and e-cf totals that differ from the document`

---

### Task 12: One commercial decision at a time (minor 4)

**Files:**
- Modify: `app/Services/Ecf/CommercialApprovalService.php`, `app/Models/ReceivedEcf.php`
- Test: `tests/Feature/Ecf/CommercialApprovalTest.php`

**Interfaces:**
- Produces:
  - **States.** `received_ecfs.commercial_status` gains the transient value `'sending'` (it fits `varchar(10)`). `ReceivedEcf::commercialStatusLabel()` maps `'sending'` to `'Enviando'`.
  - **Order in `CommercialApprovalService::decide()`:**
    1. reason check (unchanged);
    2. a claim transaction (`lockForUpdate`, `assertPending`, set `'sending'`, save);
    3. signing, DGII call and issuer delivery inside `try`. Any `Throwable` sets the status back to `'pending'`, in a transaction and only `where('commercial_status', 'sending')`, then rethrows;
    4. the final transaction: `lockForUpdate`, require `'sending'`, save the decision as today.
  - **`assertPending()`** throws `'Ya se está enviando una decisión comercial para este e-CF.'` for `'sending'` and the existing `'Este e-CF ya tiene una decisión comercial.'` for any other non-pending status.

- [ ] **Step 1: Failing test** — append to `tests/Feature/Ecf/CommercialApprovalTest.php`:
```php
test('a decision being sent blocks a second decision and a dgii failure releases it', function () {
    $received = ReceivedEcf::query()->firstOrFail();
    $actor = User::factory()->create();
    $received->forceFill(['commercial_status' => 'sending'])->save();
    expect(fn () => app(CommercialApprovalService::class)->decide($received, $actor, true, null))->toThrow(EcfException::class, 'Ya se está enviando una decisión comercial para este e-CF.');
    $received->forceFill(['commercial_status' => 'pending'])->save();
    $statusDuringCall = null;
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => function () use ($received, &$statusDuringCall) {
        $statusDuringCall = $received->fresh()->commercial_status;

        return Http::response(['mensaje' => 'Error'], 400);
    }]);
    expect(fn () => app(CommercialApprovalService::class)->decide($received->fresh(), $actor, true, null))->toThrow(EcfException::class, 'La DGII rechazó la aprobación comercial: Error');
    expect($statusDuringCall)->toBe('sending')->and($received->fresh()->commercial_status)->toBe('pending')->and($received->fresh()->commercialStatusLabel())->toBe('Pendiente');
});
```
Run: `php artisan test --compact tests/Feature/Ecf/CommercialApprovalTest.php 2>&1 | tail -3`. Expected: `"result":"failed"`.

- [ ] **Step 2: Implement** — as in Interfaces. The class docblock gains "The row is marked `sending` under lock before the DGII call, so two clicks cannot send two ACECF."
- [ ] **Step 3: Gate.** Expected: passed (**641**).
- [ ] **Step 4: Commit** — `fix(ecf): lock a received e-cf while its commercial decision is being sent`

---

## Decisions taken by the planner (rulings not applicable literally)

- **I1, poll claim.** The ruling's claim (attempts + 1) is applied to polls as well, so `attempts` counts every DGII/PSFE call.
- **I1, scope of the claim.** The claim alone does not stop two callers from sending while the status is still `signed`. That protection comes from the `ShouldBeUnique` lock, which holds now that every web action uses `dispatch()` (GC-23). Task 1 pins it.
- **I2, placement.** `lookup` sits in `SubmitEcfDocument` (as ruled) through `EcfService::lookup`, not inside `EcfService::submit()`. A direct `submit()` call, as in tests, still sends.
- **I2, DGII coverage.** The DGII lookup uses Consulta TrackIds (RES §4). It returns `null`, and therefore resends:
  - in CerteCF, which has no such service;
  - for RFCE documents, whose consulta parameters are UNVERIFIED.

  Each trackId is resolved through consultaresultado rather than trusting the list's estado text.
- **I3, tail ranges on rejection or failure.** Restoring the sequence end on a rejected/failed annulment means the tail range cannot stay pending. It stays linked to the rejected/failed annulment and the user re-adds it.
- **I3, additions.** `advanceSequences()` is removed because it is a no-op under the two allowed kinds. Two additions were needed: an `ncf_sequence_id` column, so the right sequence can be restored, and a sequence-edit guard, so the shrink cannot be undone from the sequences form.
- **I4.** "Refuse" for `active()` means it throws. A new `current()` serves display, readiness, alerts and the receiver guard.
- **I7.** RES §3 says the certificate SN may be the authorised delegate's cédula, not the issuer RNC. The minimal rule is applied, behind `ECF_VERIFY_SIGNER_TAX_ID` (default on), so it can be switched off during certification.
- **I9.** The operations guide lives in `README.md` (outside `docs/`), so executors update it there.
