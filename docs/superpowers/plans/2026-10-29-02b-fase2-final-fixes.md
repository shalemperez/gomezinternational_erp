# Phase 2b — Final-review fixes: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Executors are Claude Haiku agents that **decide nothing**: read ARCH §11 (`docs/superpowers/plans/2026-10-29-00-architecture.md`) first, then the Phase 1 plan's Global Constraints (`docs/superpowers/plans/2026-10-29-01-fase1-base.md`, GC-1 … GC-9) and the Phase 2 plan's header rulings and GC-10 … GC-13 (`docs/superpowers/plans/2026-10-29-02-fase2-ventas-ncf.md`) — all apply unchanged. **Executors never edit anything under `docs/`.**

**Goal:** Close the 11 Important findings of the final whole-branch review of `phase-2-sales-ncf` (HEAD `f6d77d2`, 466 tests green) plus five selected minors: fiscal date stamped at issue, credit-note discount/ISC rules, credit notes beyond the open balance becoming client credit, proforma quantities tracked at issue, dispatch button on paid invoices, after-sale documents on inactive master data, historical prior payments in statements, aging "as of" rebuilt from dated movements, NCF collisions with historical invoices, FEFO skipping expired lots with an audited override, B02/B04 without expiry, Gmail reconnect message, initial-stock numeric bounds, receipt-cancelled payload, receipt sums computed on the server.

**Architecture:** No new subsystems. Three small migrations (`2026_11_01_000024…000026`). Each task edits the current code — **read every file you touch before editing**; line numbers below were taken at HEAD `f6d77d2`.

**Tech Stack:** unchanged (Phase 2).

**Spec:** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/specs/2026-10-29-gomez-erp-design.md`. **Review:** `.superpowers/sdd/2026-10-29-02-fase2-ventas-ncf/final-review-out.log`. **Rulings:** the lines `Ruling I3/I4/I5/I9/I10/I11` at the end of `.superpowers/sdd/2026-10-29-02-fase2-ventas-ncf/progress.md` are binding and are already folded into the tasks below.

**Branch:** `phase-2-sales-ncf` (continue on it). **Gate (every task):** `vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → pint `"result":"passed"`, phpstan `"errors":0`, phpunit `"result":"passed"` with the task's N (indicative, P1 GC-1). Tasks that touch `resources/js` also run `npm run check:fix && npm run types:check` (exit 0) before the gate. **Commit rule:** `vendor/bin/pint --dirty && git add -A -- . ':(exclude)docs' && git commit -q -m "<subject>" -m "Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>"`. Test totals start at **466**.

**Dev database:** after a task that adds a migration run `php artisan migrate --no-interaction 2>&1 | tail -2` (tests use `RefreshDatabase` on `gomez_test`).

Out of scope (parked minors, do not touch): "Acreditada" status label, `ilike` escaping, `ImmutableWhenIssued` on line models, lazy balance-row race, `firstOrCreate` lot-expiry mismatch, `expiringLots` in alerts, initial stock loaded twice, salespeople seeing all dispatches, TotalsPreview permission.

---

## Global Constraints

Phase 1 GC-1 … GC-9 and Phase 2 GC-10 … GC-13 apply unchanged. Additions for this plan:

- **GC-14 Fiscal date.** `issue_date` of invoices, credit notes and debit notes is the date of issuance: the issuing action stamps `today()` and ignores any user-entered date. Draft invoices keep their editable `issue_date` only as a draft field.
- **GC-15 Credit-note amounts.** `credit_notes.total` is the fiscal amount (B04). `credit_notes.applied_amount` is the part absorbed by the referenced invoice at issue (`min(total, invoice balance)`); `credit_notes.unapplied_amount` is the remainder, a client credit usable like a receipt advance. `invoices.credited_amount` = Σ `applied_amount` of its issued notes (not Σ totals). The fiscal cap per invoice is Σ `credit_notes.total` ≤ `invoices.total + debited_amount`.
- **GC-16 Applications.** `receipt_applications` rows come from a receipt (`receipt_id`, `source` `receipt`|`advance`) or from a credit note (`credit_note_id`, `source` `credit_note`); exactly one of the two ids is set. `applied_on` is the receipt date for `receipt`, today for `advance` and `credit_note`. Client statements list only receipt-backed applications (credit-note applications are internal reallocations already credited by the note's own row).

## Review Focus

1. **A credit note whose per-unit net price exceeds the invoiced net** (lower discount %) must be refused → Task 2 test `a credit note line cannot carry less discount than the invoiced line`.
2. **Issuing a backdated draft after the sequence expired** must be refused and, once a valid sequence exists, the invoice must carry today's date → Task 1 test `issuing stamps today as the fiscal date and judges the sequence expiry on that date`.
3. **A credit note larger than the open balance** must not be refused nor leave a negative balance: the excess becomes client credit and the fiscal cap still holds → Task 3 tests `a credit note is capped by the creditable amount and the excess over the balance becomes client credit` and `client credit from a credit note is applied to another open invoice and excluded from the statement payments`.
4. **An NCF range that already contains an imported NCF** must be refused at registration and at assignment with a Spanish message, never a 500 → Task 7 tests `an ncf already used by a historical invoice is refused instead of crashing` and `a sequence range cannot include ncfs already used by invoices outside it`.
5. **A dispatch of an expired lot** must be refused unless an authorised user gives a reason, and FEFO must never suggest it → Task 8 tests `fefo skips lots that are already expired` and `expired lots are skipped by fefo and refused at confirm unless an authorised user overrides with a reason`.

---

### Task 1: Fiscal date = date of issuance (I3) and no expiry on B02/B04 (minor)

**Files:**
- Modify: `app/Actions/Sales/IssueInvoice.php`, `app/Actions/Sales/IssueCreditNote.php`, `app/Actions/Sales/IssueDebitNote.php`, `app/Services/Fiscal/FiscalNumberingService.php`, `app/Http/Requests/Sales/StoreCreditNoteRequest.php`, `app/Http/Requests/Sales/StoreDebitNoteRequest.php`, `resources/js/pages/sales/credit-notes/form.tsx`, `resources/js/pages/sales/debit-notes/form.tsx`, `resources/js/pages/sales/invoices/form.tsx`, `tests/Support/helpers.php`, `tests/Feature/Sales/InvoiceIssueTest.php`, `tests/Feature/Sales/CreditNoteTest.php`, `tests/Feature/Sales/DebitNoteTest.php`, `tests/Feature/Sales/ClientStatementTest.php`, `tests/Feature/Fiscal/FiscalNumberingTest.php`

**Interfaces:**
- Produces: `IssueInvoice` stamps `issue_date = today()` and `due_date = today() + (cash ? 0 : credit_days)`; `IssueCreditNote`/`IssueDebitNote` ignore `$data['issue_date']` and use `CarbonImmutable::today()`; `StoreCreditNoteRequest`/`StoreDebitNoteRequest` no longer validate `issue_date`; `FiscalNumber::expiresOn` is `null` whenever `! $type->expires()`.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Sales/InvoiceIssueTest.php`:
```php
test('issuing stamps today as the fiscal date and judges the sequence expiry on that date', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 10, 'expires_on' => now()->subDay()->toDateString()]);
    $draft = draftFor(['salesperson_id' => $this->sp->id, 'issue_date' => now()->subMonths(2)->toDateString()], $user);
    expect($draft->issue_date->toDateString())->toBe(now()->subMonths(2)->toDateString());

    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('error', 'La secuencia de B01 venció el '.now()->subDay()->format('d/m/Y').'; registra una nueva secuencia autorizada por la DGII.');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft);

    activeSequence('B01', ['range_from' => 100, 'range_to' => 200]);
    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('success');
    $i = $draft->fresh();
    expect($i->issue_date->toDateString())->toBe(now()->toDateString())->and($i->due_date->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(AccountingEvent::where('type', 'invoice_issued')->first()->payload['issue_date'])->toBe(now()->toDateString());
});
```
Append to `tests/Feature/Sales/CreditNoteTest.php` (add `use App\Models\DebitNote;`):
```php
test('credit and debit notes are issued with today\'s date whatever date the form sends', function () {
    actingAsRole('accounting');
    activeSequence('B03', ['range_to' => 100]);
    $i = issuedInvoice();
    $this->post(route('sales.credit-notes.store'), [...validCreditNotePayload($i), 'issue_date' => '2020-01-01'])->assertRedirect()->assertSessionHas('success');
    $this->post(route('sales.debit-notes.store'), [...validDebitNotePayload($i), 'issue_date' => '2020-01-01'])->assertRedirect()->assertSessionHas('success');
    expect(CreditNote::firstOrFail()->issue_date->toDateString())->toBe(now()->toDateString())
        ->and(DebitNote::firstOrFail()->issue_date->toDateString())->toBe(now()->toDateString());
});
```
Append to `tests/Feature/Fiscal/FiscalNumberingTest.php`:
```php
test('non-expiring types never report an expiry date even when the sequence has one', function () {
    activeSequence('B02', ['expires_on' => '2030-12-31']);
    $n = app(FiscalNumberingService::class)->assign(NcfType::B02, Carbon::today());
    expect($n->ncf)->toBe('B0200000001')->and($n->expiresOn)->toBeNull();
});
```
Edit existing tests:
- `tests/Feature/Sales/DebitNoteTest.php`, test `debit notes require an open invoice, valid lines and permission`: delete the line that posts `['issue_date' => $i->issue_date->subDay()->toDateString()]` and asserts `'La nota no puede ser anterior a la factura.'`.
- `tests/Feature/Sales/ClientStatementTest.php`, test `statement lists invoices, notes and payments with a running balance per currency`: replace the four document-creating lines (`$i = issuedInvoice([... 'issue_date' => '2026-11-02'])` through the receipt `post`) with:
```php
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    $i = issuedInvoice(['client_id' => $client->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect();      // +590.00
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertRedirect();   // −11.80
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
    $this->post(route('sales.receipts.store'), validReceiptPayload($i->fresh(), ['receipt_date' => '2026-11-05', 'amount_received' => '100', 'applications' => [['invoice_id' => $i->id, 'amount' => '100']]]))->assertRedirect();
    $this->travelBack();
```
(the assertions that follow stay unchanged).
- `tests/Support/helpers.php`: remove the `'issue_date' => now()->toDateString(),` entry from `validCreditNotePayload()` and from `validDebitNotePayload()`.

Run: `php artisan test --compact --filter='InvoiceIssueTest|CreditNoteTest|FiscalNumberingTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Actions**
  - `IssueInvoice::handle`, inside the transaction after the e-CF mode check: add `$issueDate = \Carbon\CarbonImmutable::today();` and `$dueDate = $issueDate->addDays($invoice->payment_terms === PaymentTerms::Cash ? 0 : $invoice->credit_days);`. Replace `$this->numbering->assign($type, $invoice->issue_date)` with `$this->numbering->assign($type, $issueDate)`. Add to the `forceFill([...])` array: `'issue_date' => $issueDate->toDateString(), 'due_date' => $dueDate->toDateString(),`. (The event payload already reads `$invoice->issue_date`/`due_date` after save.)
  - `IssueCreditNote::handle` line 72 and `IssueDebitNote::handle` line 49: replace `CarbonImmutable::parse((string) $data['issue_date'])` with `CarbonImmutable::today()`.
  - `FiscalNumberingService::assign`, the `FiscalNumber` construction: third argument becomes `$type->expires() && $usable->expires_on ? CarbonImmutable::parse($usable->expires_on) : null`.

- [ ] **Step 3: Requests** — `StoreCreditNoteRequest::rules()` and `StoreDebitNoteRequest::rules()`: delete the `'issue_date' => [...]` rule. In both `withValidator` closures delete the `if ($this->string('issue_date')->toString() < $invoice->issue_date->toDateString()) { ... }` block.

- [ ] **Step 4: Forms**
  - `resources/js/pages/sales/credit-notes/form.tsx`: remove `issue_date: string;` from `CreditNoteFormData` and `issue_date: today,` from `useForm`; replace the whole `<FormField label="Fecha" htmlFor="issue_date" ...>…</FormField>` block with `<FormField label="Fecha de emisión" htmlFor="issue_date" hint="Se asigna al emitir la nota."><Input id="issue_date" value={formatDate(today)} readOnly disabled /></FormField>`.
  - `resources/js/pages/sales/debit-notes/form.tsx`: same three edits (`DebitNoteFormData`, `useForm`, the `Fecha` field).
  - `resources/js/pages/sales/invoices/form.tsx`: on the `FormField` wrapping the `issue_date` input (around line 258) add the prop `hint="La fecha fiscal y el vencimiento se fijan al emitir."`.

- [ ] **Step 5: Gate** — `npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (469 tests).
- [ ] **Step 6: Commit** — `fix(sales): stamp the fiscal date at issue and drop expiry from non-expiring ncf types`

---

### Task 2: Credit-note lines — discount floor (I1) and additional taxes from the invoice snapshot, specific taxes skipped on discount notes (I2)

**Files:**
- Modify: `app/Support/Documents/LineInput.php`, `app/Services/Documents/DocumentTotalsCalculator.php`, `app/Http/Requests/Sales/StoreCreditNoteRequest.php`, `app/Actions/Sales/IssueCreditNote.php`, `tests/Feature/Sales/CreditNoteTest.php`

**Interfaces:**
- Produces: `LineInput` gains two optional constructor parameters after `$itbisRate`: `public ?array $additionalTaxCodes = null` (`list<string>`; when non-null the calculator uses `AdditionalTax::whereIn('code', …)` — active or not — instead of the product's current taxes) and `public bool $percentTaxesOnly = false` (when true, `FixedPerUnit`, `FixedPerLiter` and `FixedPerDegreeLiter` taxes are skipped). `DocumentTotalsCalculator::compute()` honours both.

- [ ] **Step 1: Failing tests** — append to `tests/Feature/Sales/CreditNoteTest.php` (add `use App\Models\AdditionalTax;` and `use App\Models\Product;`):
```php
test('a credit note line cannot carry less discount than the invoiced line', function () {
    actingAsRole('accounting');
    $i = issuedInvoice(['lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '18.00'])->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '50']]]); // net 100 + 18 = 118.00
    $line = $i->lines->first();
    $row = fn (string $discount) => ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => $discount, 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('0')]]))
        ->assertSessionHasErrors(['lines.0.discount_percent' => 'El descuento no puede ser menor que el facturado (50.0000 %).']);
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('50')]]))->assertRedirect()->assertSessionHas('success');
    expect(CreditNote::firstOrFail()->total)->toBe('59.00')->and($i->fresh()->balance)->toBe('59.00');
});

test('discount notes skip fixed-per-unit taxes and every note uses the invoice line tax snapshot', function () {
    $user = actingAsRole('accounting');
    $adValorem = AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10.0000', 'amount' => null, 'included_in_itbis_base' => true]);
    $fixed = AdditionalTax::factory()->create(['code' => 'ISC-FIX', 'calculation_type' => 'fixed_per_unit', 'rate' => null, 'amount' => '5.0000', 'included_in_itbis_base' => false]);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $product->additionalTaxes()->attach([$adValorem->id, $fixed->id]);
    $i = issuedInvoice(['lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $line = $i->lines->first();
    expect($i->total)->toBe('269.60'); // 200 + 20 + 10 + 18 % × 220

    $product->additionalTaxes()->detach($fixed->id); // the snapshot, not the current setup, drives the note
    $row = fn (string $qty, string $price) => ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => $qty, 'unit_price' => $price, 'discount_percent' => '0', 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'discount', 'lines' => [$row('2', '10')]]))->assertRedirect()->assertSessionHas('success');
    $discount = CreditNote::latest('id')->firstOrFail();
    expect($discount->total)->toBe('25.96')->and($discount->additional_tax_total)->toBe('2.00')
        ->and(collect($discount->lines->first()->additional_taxes)->pluck('code')->all())->toBe(['ISC-ADV']);

    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [$row('1', '100')]]))->assertRedirect()->assertSessionHas('success');
    $return = CreditNote::latest('id')->firstOrFail();
    expect($return->total)->toBe('134.80')->and($return->additional_tax_total)->toBe('15.00') // 100 + 10 + 5 + 18 % × 110
        ->and(collect($return->lines->first()->additional_taxes)->pluck('code')->sort()->values()->all())->toBe(['ISC-ADV', 'ISC-FIX']);
});
```
Run: `php artisan test --compact --filter=CreditNoteTest 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: `LineInput`** — constructor becomes `public function __construct(public int $productId, public string $quantity, public string $unitPrice, public string $discountPercent = '0', public ?string $description = null, public ?string $itbisRate = null, public ?array $additionalTaxCodes = null, public bool $percentTaxesOnly = false)` with PHPDoc `@param list<string>|null $additionalTaxCodes`. `fromArray()` is unchanged (it never sets the two new fields).

- [ ] **Step 3: `DocumentTotalsCalculator::compute`** — before the `foreach ($lines …)` add `$snapshotTaxes = AdditionalTax::query()->whereIn('code', collect($lines)->flatMap(fn (LineInput $l) => $l->additionalTaxCodes ?? [])->unique()->values()->all())->get()->keyBy('code');` (import `App\Models\AdditionalTax`). Replace the tax loop header `foreach ($product->additionalTaxes->where('is_active', true) as $tax) {` with:
```php
$taxes = $input->additionalTaxCodes === null
    ? $product->additionalTaxes->where('is_active', true)
    : collect($input->additionalTaxCodes)->map(fn (string $code) => $snapshotTaxes->get($code))->filter();
foreach ($taxes as $tax) {
    if ($input->percentTaxesOnly && $tax->calculation_type !== AdditionalTaxCalculationType::PercentOfBase) {
        continue;
    }
```
(import `App\Enums\AdditionalTaxCalculationType`; keep the loop body).

- [ ] **Step 4: `IssueCreditNote::handle`** — in the `new LineInput(...)` call add two arguments after `$invoiceLine?->itbis_rate`: `$invoiceLine === null ? null : array_values(array_map(fn (array $tax) => (string) $tax['code'], $invoiceLine->additional_taxes ?? []))` and `$reason === CreditNoteReason::Discount`.

- [ ] **Step 5: `StoreCreditNoteRequest::validateSaleLine`** — after the `unit_price` check add:
```php
if (Decimal::cmp((string) ($row['discount_percent'] ?? '0'), $line->discount_percent) < 0) {
    $validator->errors()->add("lines.{$index}.discount_percent", "El descuento no puede ser menor que el facturado ({$line->discount_percent} %).");
}
```
and add `'discount_percent'` to the `hasAny` field list on line 82.

- [ ] **Step 6: Gate** → passed (471 tests).
- [ ] **Step 7: Commit** — `fix(sales): credit note lines keep the invoiced discount and tax snapshot; discount notes skip specific taxes`

---

### Task 3: Credit notes beyond the open balance become client credit (I4)

**Files:**
- Create: `database/migrations/2026_11_01_000024_add_credit_note_applications.php`
- Modify: `app/Models/CreditNote.php`, `app/Models/ReceiptApplication.php`, `app/Actions/Sales/IssueCreditNote.php`, `app/Actions/Sales/ApplyAdvance.php`, `app/Services/Sales/ReceiptApplications.php`, `app/Services/Sales/ClientAccount.php`, `app/Services/Sales/ClientStatementService.php`, `app/Http/Requests/Sales/ApplyAdvanceRequest.php`, `app/Http/Controllers/Sales/CreditNoteController.php`, `app/Http/Controllers/Sales/ReceiptController.php`, `app/Http/Controllers/Sales/InvoiceController.php`, `routes/sales.php`, `resources/js/components/erp/apply-advance-dialog.tsx`, `resources/js/pages/sales/receipts/show.tsx`, `resources/js/pages/sales/credit-notes/show.tsx`, `resources/js/pages/sales/invoices/show.tsx`, `resources/js/types/sales.ts`, `tests/Feature/Sales/CreditNoteTest.php`

**Interfaces:**
- Produces (GC-15/GC-16): columns `credit_notes.applied_amount`, `credit_notes.unapplied_amount` `decimal(14,2) default 0`; `receipt_applications.receipt_id` nullable, `receipt_applications.credit_note_id` nullable FK, `receipt_applications.applied_on` date not null. `ApplyAdvance::handle(Receipt|CreditNote $source, array $applications, User $actor): Receipt|CreditNote`. `ReceiptApplications::record(Receipt|CreditNote $source, array $applications, string $source, User $actor, Collection $invoices): void`. `CreditNote::applications(): HasMany<ReceiptApplication>`. Route `POST sales/credit-notes/{creditNote}/apply` → `sales.credit-notes.apply` (`CreditNoteController::apply`). `ClientAccount::advanceBalance()` includes issued credit notes' `unapplied_amount`. React `ApplyAdvanceDialog` props: `applyUrl: string` replaces `receiptId`, plus optional `title?: string`.

- [ ] **Step 1: Failing tests** — in `tests/Feature/Sales/CreditNoteTest.php` add imports `use App\Models\ReceiptApplication; use App\Services\Sales\ClientAccount; use App\Services\Sales\ClientStatementService; use Carbon\CarbonImmutable;` and **replace** the test `a credit note cannot exceed the invoice balance nor the invoiced unit price` with:
```php
test('a credit note is capped by the creditable amount and the excess over the balance becomes client credit', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => '200.00'])->save();
    $i->refreshFinancials(); // balance 36.00
    $line = $i->lines->first();
    $row = fn (string $qty, string $price) => ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => $qty, 'unit_price' => $price, 'discount_percent' => '0', 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('1', '100.5')]]))
        ->assertSessionHasErrors(['lines.0.unit_price' => 'La nota no puede superar el precio facturado (100.0000).']);

    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('1', '100')]]))->assertRedirect()->assertSessionHas('success'); // 118.00 > balance 36.00
    $n = CreditNote::firstOrFail();
    expect($n->total)->toBe('118.00')->and($n->applied_amount)->toBe('36.00')->and($n->unapplied_amount)->toBe('82.00')
        ->and($i->fresh()->credited_amount)->toBe('36.00')->and($i->fresh()->balance)->toBe('0.00')->and($i->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(ClientAccount::advanceBalance($i->client, $i->currency))->toBe('82.00')
        ->and(AccountingEvent::where('type', 'credit_note_issued')->first()->payload['unapplied_amount'])->toBe('82.00');

    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('2', '100')]]))
        ->assertRedirect()->assertSessionHas('error', 'La nota de crédito (RD$ 236.00) supera el monto acreditable de la factura (RD$ 118.00).');
    expect(CreditNote::count())->toBe(1)->and(NcfSequence::where('ncf_type', 'B04')->first()->next_number)->toBe(2);
});

test('client credit from a credit note is applied to another open invoice and excluded from the statement payments', function () {
    $user = actingAsRole('accounting');
    $a = issuedInvoice();
    $a->forceFill(['paid_amount' => '200.00'])->save();
    $a->refreshFinancials();
    $line = $a->lines->first();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($a, ['lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))->assertRedirect();
    $n = CreditNote::firstOrFail(); // unapplied 82.00
    $b = issuedInvoice(['client_id' => $a->client_id]); // 236.00

    $this->get(route('sales.credit-notes.show', $n))->assertOk()->assertInertia(fn ($p) => $p->where('can.apply', true)->has('openInvoices', 1)->where('creditNote.unapplied_amount', '82.00'));
    $this->post(route('sales.credit-notes.apply', $n), ['applications' => [['invoice_id' => $b->id, 'amount' => '100']]])
        ->assertSessionHasErrors(['applications' => 'Las aplicaciones (100.00) superan el saldo a favor disponible (82.00).']);
    $this->post(route('sales.credit-notes.apply', $n), ['applications' => [['invoice_id' => $b->id, 'amount' => '82']]])
        ->assertRedirect()->assertSessionHas('success', 'Saldo a favor aplicado: RD$ 82.00');
    $application = ReceiptApplication::where('source', 'credit_note')->firstOrFail();
    expect($n->fresh()->unapplied_amount)->toBe('0.00')->and($b->fresh()->paid_amount)->toBe('82.00')->and($b->fresh()->balance)->toBe('154.00')
        ->and($application->receipt_id)->toBeNull()->and($application->credit_note_id)->toBe($n->id)->and($application->applied_on->toDateString())->toBe(now()->toDateString())
        ->and(AccountingEvent::where('type', 'credit_note_applied')->first()->payload['applied_total'])->toBe('82.00')
        ->and(Activity::inLog('credit_notes')->where('event', 'credit_applied')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'payment_applied')->where('subject_id', $b->id)->exists())->toBeTrue();
    $this->post(route('sales.credit-notes.apply', $n), ['applications' => [['invoice_id' => $b->id, 'amount' => '1']]])->assertSessionHas('error', 'La nota de crédito no tiene saldo a favor disponible');

    $rows = app(ClientStatementService::class)->statement($a->client, CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2030-12-31'))['DOP']['rows'];
    expect(collect($rows)->where('type', 'payment')->count())->toBe(0)->and(collect($rows)->where('type', 'credit_note')->count())->toBe(1)
        ->and(end($rows)['balance'])->toBe('354.00'); // 236 + 236 − 118 (the 200 paid_amount above has no receipt)
    $this->get(route('sales.invoices.show', $b))->assertOk()->assertInertia(fn ($p) => $p->where('applications.0.receipt_number', $n->number)->where('applications.0.source', 'credit_note'));
});
```
Run: `php artisan test --compact --filter=CreditNoteTest 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Migration** `database/migrations/2026_11_01_000024_add_credit_note_applications.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('applied_amount', 14, 2)->default(0)->after('total_base');
            $table->decimal('unapplied_amount', 14, 2)->default(0)->after('applied_amount');
        });
        DB::statement('UPDATE credit_notes SET applied_amount = total');
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('receipt_id')->nullable()->change();
            $table->foreignId('credit_note_id')->nullable()->after('receipt_id')->constrained('credit_notes')->restrictOnDelete();
            $table->date('applied_on')->nullable()->after('source');
        });
        DB::statement('UPDATE receipt_applications ra SET applied_on = r.receipt_date FROM receipts r WHERE r.id = ra.receipt_id');
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->date('applied_on')->nullable(false)->change();
            $table->index(['credit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->dropIndex(['credit_note_id']);
            $table->dropConstrainedForeignId('credit_note_id');
            $table->dropColumn('applied_on');
            $table->unsignedBigInteger('receipt_id')->nullable(false)->change();
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['applied_amount', 'unapplied_amount']);
        });
    }
};
```

- [ ] **Step 3: Models**
  - `CreditNote`: add `applied_amount`, `unapplied_amount` to `$fillable`, casts `'applied_amount' => 'decimal:2', 'unapplied_amount' => 'decimal:2'`, PHPDoc `@property string $applied_amount`, `@property string $unapplied_amount`; `mutableWhenIssued()` returns `['status', 'unapplied_amount']`; add `/** @return HasMany<ReceiptApplication, $this> */ public function applications(): HasMany { return $this->hasMany(ReceiptApplication::class); }`.
  - `ReceiptApplication`: `$fillable` adds `'credit_note_id', 'applied_on'`; casts add `'applied_on' => 'date:Y-m-d'`; PHPDoc `@property int|null $receipt_id`, `@property int|null $credit_note_id`, `@property Carbon $applied_on`; add `/** @return BelongsTo<CreditNote, $this> */ public function creditNote(): BelongsTo`.

- [ ] **Step 4: `IssueCreditNote::handle`** — replace the balance check (lines 65-69) with:
```php
$issuedTotals = Decimal::money((string) CreditNote::query()->where('invoice_id', $invoice->id)->where('status', 'issued')->sum('total'));
$creditable = Decimal::money(Decimal::of($invoice->total)->plus(Decimal::of($invoice->debited_amount))->minus(Decimal::of($issuedTotals)));
if (Decimal::cmp($totals['total'], $creditable) > 0) {
    $currency = $invoice->currency->code;

    throw new DocumentStateException('La nota de crédito ('.DocumentPdf::money($totals['total'], $currency).') supera el monto acreditable de la factura ('.DocumentPdf::money($creditable, $currency).').');
}
$applied = Decimal::cmp($totals['total'], $invoice->balance) > 0 ? Decimal::money($invoice->balance) : $totals['total'];
$unapplied = Decimal::money(Decimal::of($totals['total'])->minus(Decimal::of($applied)));
```
In the `CreditNote::query()->create([...])` add `'applied_amount' => $applied, 'unapplied_amount' => $unapplied,`. Change the credited update to `$invoice->forceFill(['credited_amount' => Decimal::sum([$invoice->credited_amount, $applied])])->save();`. Add to the `credit_note_issued` payload: `'applied_amount' => $applied, 'unapplied_amount' => $unapplied,`. Add `'applied_amount' => $applied, 'unapplied_amount' => $unapplied` to the `credit_note_applied` invoice activity properties too.

- [ ] **Step 5: `ReceiptApplications::record`** — signature `public function record(Receipt|CreditNote $source, array $applications, string $sourceType, User $actor, Collection $invoices): void`. Create rows with `'receipt_id' => $source instanceof Receipt ? $source->id : null, 'credit_note_id' => $source instanceof CreditNote ? $source->id : null, 'invoice_id' => $invoiceId, 'amount' => $amount, 'source' => $sourceType, 'applied_on' => $sourceType === 'receipt' && $source instanceof Receipt ? $source->receipt_date->toDateString() : today()->toDateString(), 'created_by' => $actor->id`. Invoice activity: properties `['receipt_id' => $source instanceof Receipt ? $source->id : null, 'credit_note_id' => $source instanceof CreditNote ? $source->id : null, 'number' => $source->number, 'amount' => $amount, 'source' => $sourceType]`, log `$sourceType === 'credit_note' ? "Saldo a favor de la nota {$source->number} aplicado a la factura {$invoice->number}" : "Cobro {$source->number} aplicado a la factura {$invoice->number}"`. Update the PHPDoc and imports (`App\Models\CreditNote`). `PostReceipt` calls (`'receipt'`) are unchanged.

- [ ] **Step 6: `ApplyAdvance::handle`** — signature `public function handle(Receipt|CreditNote $source, array $applications, User $actor): Receipt|CreditNote`; body:
```php
return DB::transaction(function () use ($source, $applications, $actor): Receipt|CreditNote {
    $source = $source::query()->whereKey($source->id)->lockForUpdate()->with('currency')->firstOrFail();
    $isReceipt = $source instanceof Receipt;
    if ($isReceipt && $source->status === ReceiptStatus::Cancelled) {
        throw new DocumentStateException('El recibo está anulado');
    }
    if (! $isReceipt && $source->status !== 'issued') {
        throw new DocumentStateException('La nota de crédito no está emitida');
    }
    if (Decimal::isZero($source->unapplied_amount)) {
        throw new DocumentStateException($isReceipt ? 'El recibo no tiene anticipo disponible' : 'La nota de crédito no tiene saldo a favor disponible');
    }

    $invoices = $this->applications->lockAndCheck($applications, (int) $source->client_id, (int) $source->currency_id);
    $applied = Decimal::sum(array_map(fn (array $application): string => (string) $application['amount'], $applications));
    if (Decimal::cmp($applied, $source->unapplied_amount) > 0) {
        throw new DocumentStateException('Las aplicaciones ('.Decimal::money($applied).') superan el '.($isReceipt ? 'anticipo' : 'saldo a favor').' disponible ('.Decimal::money($source->unapplied_amount).').');
    }

    $this->applications->record($source, $applications, $isReceipt ? 'advance' : 'credit_note', $actor, $invoices);
    $remaining = Decimal::money(Decimal::of($source->unapplied_amount)->minus($applied));
    $source->forceFill($isReceipt
        ? ['applied_total' => Decimal::sum([$source->applied_total, $applied]), 'unapplied_amount' => $remaining]
        : ['unapplied_amount' => $remaining])->save();

    if ($isReceipt) {
        activity('receipts')->causedBy($actor)->performedOn($source)->event('advance_applied')
            ->withProperties(['amount' => $applied, 'unapplied_amount' => $source->unapplied_amount])
            ->log("Anticipo del recibo {$source->number} aplicado a facturas");
        $this->events->record('advance_applied', $source, [
            'number' => $source->number, 'client_id' => $source->client_id, 'applications' => $this->applications->eventPayload($applications, $invoices),
            'applied_total' => $applied, 'receipt_applied_total' => $source->applied_total, 'currency' => $source->currency->code, 'exchange_rate' => $source->exchange_rate,
        ]);
    } else {
        activity('credit_notes')->causedBy($actor)->performedOn($source)->event('credit_applied')
            ->withProperties(['amount' => $applied, 'unapplied_amount' => $source->unapplied_amount])
            ->log("Saldo a favor de la nota {$source->number} aplicado a facturas");
        $this->events->record('credit_note_applied', $source, [
            'number' => $source->number, 'ncf' => $source->ncf, 'client_id' => $source->client_id, 'applications' => $this->applications->eventPayload($applications, $invoices),
            'applied_total' => $applied, 'unapplied_amount' => $source->unapplied_amount, 'currency' => $source->currency->code, 'exchange_rate' => $source->exchange_rate,
        ]);
    }

    return $source->fresh();
});
```
(import `App\Models\CreditNote`; PHPStan may need `/** @var Receipt|CreditNote $source */` after the locked query — type-only fixes allowed.)

- [ ] **Step 7: Request, services, controllers, route**
  - `ApplyAdvanceRequest::withValidator`: `$source = $this->route('receipt') ?? $this->route('creditNote');` guard `if ($validator->errors()->isNotEmpty() || (! $source instanceof Receipt && ! $source instanceof CreditNote)) { return; }`; `$hasAdvance = $source instanceof Receipt ? ($source->status !== ReceiptStatus::Cancelled && ! Decimal::isZero($source->unapplied_amount)) : ($source->status === 'issued' && ! Decimal::isZero($source->unapplied_amount));` error message `'Las aplicaciones ('.Decimal::money($applied).') superan el '.($source instanceof Receipt ? 'anticipo' : 'saldo a favor').' disponible ('.Decimal::money($source->unapplied_amount).').'`.
  - `ClientAccount::advanceBalance`: return `Decimal::sum([<existing receipts sum>, Decimal::sum(CreditNote::query()->where('client_id', $client->id)->where('currency_id', $currency->id)->where('status', 'issued')->pluck('unapplied_amount'))])` (import `App\Models\CreditNote`).
  - `ClientStatementService::movements`, the `$payments` query: add `->whereNotNull('receipt_id')` before `whereHas('receipt', …)`.
  - `InvoiceController::applicationRows`: eager load `->with(['receipt:id,number,receipt_date', 'creditNote:id,number'])`; row fields `'receipt_id' => $application->receipt_id, 'credit_note_id' => $application->credit_note_id, 'receipt_number' => $application->receipt?->number ?? $application->creditNote?->number ?? '', 'receipt_date' => ($application->receipt?->receipt_date ?? $application->applied_on)->toDateString()`.
  - `routes/sales.php`: before the credit-notes `Route::resource`, add `Route::post('credit-notes/{creditNote}/apply', [CreditNoteController::class, 'apply'])->name('credit-notes.apply');`.
  - `CreditNoteController::show`: add props `'openInvoices' => Invoice::query()->visibleTo($this->actor($request))->where('client_id', $creditNote->client_id)->where('currency_id', $creditNote->currency_id)->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])->orderBy('issue_date')->get(['id', 'number', 'ncf', 'issue_date', 'due_date', 'total', 'itbis_total', 'taxable_total', 'balance', 'currency_id'])` and `'can' => ['apply' => $creditNote->status === 'issued' && Decimal::cmp($creditNote->unapplied_amount, '0') > 0 && $this->actor($request)->can('sales.receipts.create')]`. Add:
```php
#[Middleware('permission:sales.receipts.create')]
public function apply(ApplyAdvanceRequest $request, CreditNote $creditNote, ApplyAdvance $applyAdvance): RedirectResponse
{
    Gate::authorize('view', $creditNote);
    /** @var list<array{invoice_id: int|string, amount: string}> $applications */
    $applications = $request->validated('applications');
    $applied = Decimal::sum(array_column($applications, 'amount'));
    $creditNote = $applyAdvance->handle($creditNote, $applications, $this->actor($request));

    return redirect()->route('sales.credit-notes.show', $creditNote)->with('success', 'Saldo a favor aplicado: '.DocumentPdf::money($applied, $creditNote->currency->code));
}
```
(imports `App\Actions\Sales\ApplyAdvance`, `App\Http\Requests\Sales\ApplyAdvanceRequest`, `App\Enums\InvoiceStatus`, `App\Support\Money\Decimal`).
  - `ReceiptController::apply`: unchanged except PHPStan — the action now returns `Receipt|CreditNote`; add `/** @var Receipt $receipt */` after the call if needed.
  - Run `php artisan wayfinder:generate --with-form --no-interaction >/dev/null`.

- [ ] **Step 8: Frontend**
  - `resources/js/types/sales.ts`: `ReceiptApplication.receipt_id` → `number | null`, add `credit_note_id: number | null; applied_on: string;`. `CreditNote` adds `applied_amount: string; unapplied_amount: string;`.
  - `apply-advance-dialog.tsx`: replace prop `receiptId: number` with `applyUrl: string` and add `title?: string`; remove the `apply` route import; `router.post(applyUrl, …)`; `<DialogTitle>{title ?? 'Aplicar anticipo'}</DialogTitle>`; the "Anticipo disponible" line becomes `{title ?? 'Anticipo'} disponible:` → use `Disponible: {formatMoney(unappliedAmount, currency)}`.
  - `receipts/show.tsx`: pass `applyUrl={apply.url(receipt.id)}` (import `apply` from `@/routes/sales/receipts`, already imported `cancel, index, pdf` — add `apply`).
  - `credit-notes/show.tsx`: props add `openInvoices: OpenInvoiceRow[]; can: { apply: boolean };`; `useState` `applyOpen`; meta adds `{ label: 'Aplicado a la factura', value: formatMoney(creditNote.applied_amount, currency) }` and `{ label: 'Saldo a favor', value: formatMoney(creditNote.unapplied_amount, currency) }`; actions add `{abilities.apply && <Button variant="outline" onClick={() => setApplyOpen(true)}>Aplicar saldo a favor</Button>}`; render `<ApplyAdvanceDialog open={applyOpen} onOpenChange={setApplyOpen} applyUrl={apply.url(creditNote.id)} title="Aplicar saldo a favor" currency={currency} unappliedAmount={creditNote.unapplied_amount} openInvoices={openInvoices} />` when `abilities.apply` (import `apply` from `@/routes/sales/credit-notes`, `ApplyAdvanceDialog`, `OpenInvoiceRow`).
  - `invoices/show.tsx`: the `applications` prop type → `receipt_id: number | null; credit_note_id: number | null;`; the link `href` becomes `application.receipt_id !== null ? showReceipt.url(application.receipt_id) : showCreditNote.url(application.credit_note_id ?? 0)` (import `show as showCreditNote` from `@/routes/sales/credit-notes`, alongside the existing `create as createCreditNote`); wherever that table prints `application.source` (search the file for `application.source`; if it is not printed, add nothing), show `'Saldo a favor'` for `credit_note`, `'Anticipo'` for `advance`, `'Cobro'` otherwise.

- [ ] **Step 9: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -2 && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (472 tests).
- [ ] **Step 10: Commit** — `feat(sales): credit notes beyond the open balance become client credit applicable to other invoices`

---

### Task 4: Proforma quantities tracked at issue, not at draft creation (I5)

**Files:**
- Create: `database/migrations/2026_11_01_000025_add_proforma_line_id_to_invoice_lines.php`
- Modify: `app/Models/InvoiceLine.php`, `app/Models/ProformaLine.php`, `app/Actions/Sales/CreateInvoiceFromProforma.php`, `app/Actions/Sales/SaveInvoice.php`, `app/Actions/Sales/IssueInvoice.php`, `app/Actions/Sales/CancelInvoice.php`, `app/Http/Requests/Sales/InvoiceFromProformaRequest.php`, `tests/Feature/Sales/InvoiceFromProformaTest.php`

**Interfaces:**
- Produces: `invoice_lines.proforma_line_id` nullable FK (`nullOnDelete`); `InvoiceLine::proformaLine(): BelongsTo`; `ProformaLine::pendingQuantity(?int $excludingInvoiceId = null): string` = `quantity − invoiced_quantity − Σ quantity of lines of draft invoices linked to this proforma line` (optionally excluding one invoice); `SaveInvoice` accepts `lines.*.proforma_line_id` and, when updating a draft with `proforma_id`, re-links lines by `product_id` to the existing links and refuses quantities above the pending one with `ValidationException` key `lines.{index}.quantity` message `Cantidad pendiente en la proforma: {pending}.`; `IssueInvoice` increments `invoiced_quantity` and marks the proforma `Invoiced` (activity `invoiced`); `CancelInvoice` decrements and reverts to `Approved` (activity `reopened`).

- [ ] **Step 1: Failing tests** — in `tests/Feature/Sales/InvoiceFromProformaTest.php` add imports `use App\Actions\Sales\CancelInvoice; use App\Actions\Sales\IssueInvoice; use App\Enums\StockMovementType; use App\Models\NcfSequence; use App\Models\User; use App\Services\Inventory\InventoryService; use App\Support\Inventory\StockLine;` and after `beforeEach` add:
```php
function stockAndIssue(Invoice $draft, User $actor): Invoice
{
    foreach ($draft->lines as $line) {
        app(InventoryService::class)->receive(new StockLine($line->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $actor, $actor);
    }
    if (! NcfSequence::query()->where('is_active', true)->exists()) {
        activeSequence('B01', ['range_to' => 1000]);
    }

    return app(IssueInvoice::class)->handle($draft, $actor);
}
```
Replace the test `an approved proforma converts partially then fully into draft invoices` with:
```php
test('an approved proforma converts into drafts and is marked invoiced only when they are issued', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(2)->create();
    [$l1, $l2] = $p->lines->all();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '0']])->assertRedirect(route('sales.invoices.edit', Invoice::first()))->assertSessionHas('success', 'Borrador de factura creado desde '.$p->number);
    $inv = Invoice::firstOrFail();
    expect($inv->proforma_id)->toBe($p->id)->and($inv->lines()->count())->toBe(1)->and($inv->lines->first()->proforma_line_id)->toBe($l1->id)->and($inv->total)->toBe('118.00')
        ->and($l1->fresh()->invoiced_quantity)->toBe('0.0000')->and($l1->fresh()->pendingQuantity())->toBe('0.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved)
        ->and(Activity::inLog('invoices')->where('event', 'created_from_proforma')->where('subject_id', $inv->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '1']])->assertSessionHasErrors(['quantities.'.$l1->id => 'Cantidad pendiente: 0.0000.']);
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '1']])->assertRedirect();
    expect(Invoice::count())->toBe(2)->and($p->fresh()->status)->toBe(ProformaStatus::Approved);

    Invoice::query()->orderBy('id')->get()->each(fn (Invoice $draft) => stockAndIssue($draft, $user));
    expect($l1->fresh()->invoiced_quantity)->toBe('1.0000')->and($l2->fresh()->invoiced_quantity)->toBe('1.0000')
        ->and($p->fresh()->status)->toBe(ProformaStatus::Invoiced)->and($p->fresh()->invoiced_at)->not->toBeNull()
        ->and(Activity::inLog('proformas')->where('event', 'invoiced')->where('subject_id', $p->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '0']])->assertSessionHasErrors(['quantities' => 'Indica al menos una cantidad a facturar.']);
});

test('deleting a proforma draft frees its quantities and cancelling the issued invoice reopens the proforma', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $draft = Invoice::firstOrFail();
    expect($line->fresh()->pendingQuantity())->toBe('0.0000');
    $this->delete(route('sales.invoices.destroy', $draft))->assertRedirect(route('sales.invoices.index'));
    expect($line->fresh()->pendingQuantity())->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved);

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $issued = stockAndIssue(Invoice::latest('id')->firstOrFail(), $user);
    expect($line->fresh()->invoiced_quantity)->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Invoiced);

    app(CancelInvoice::class)->handle($issued, $user, 'Error');
    expect($line->fresh()->invoiced_quantity)->toBe('0.0000')->and($line->fresh()->pendingQuantity())->toBe('1.0000')
        ->and($p->fresh()->status)->toBe(ProformaStatus::Approved)->and($p->fresh()->invoiced_at)->toBeNull()
        ->and(Activity::inLog('proformas')->where('event', 'reopened')->where('subject_id', $p->id)->exists())->toBeTrue();
});

test('editing a draft created from a proforma keeps the line link and respects the pending quantity', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first(); // quantity 1.0000
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $draft = Invoice::firstOrFail();
    $payload = validInvoicePayload(['client_id' => $draft->client_id, 'salesperson_id' => $draft->salesperson_id, 'warehouse_id' => $draft->warehouse_id, 'lines' => [['product_id' => $line->product_id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $this->put(route('sales.invoices.update', $draft), $payload)->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente en la proforma: 1.0000.']);
    $this->put(route('sales.invoices.update', $draft), [...$payload, 'lines' => [['product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '90', 'discount_percent' => '0']]])->assertRedirect(route('sales.invoices.show', $draft));
    expect($draft->fresh()->lines->first()->proforma_line_id)->toBe($line->id)->and($draft->fresh()->total)->toBe('106.20');
});
```
Run: `php artisan test --compact --filter=InvoiceFromProformaTest 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Migration** `database/migrations/2026_11_01_000025_add_proforma_line_id_to_invoice_lines.php`: `up()` → `Schema::table('invoice_lines', fn (Blueprint $t) => $t->foreignId('proforma_line_id')->nullable()->after('invoice_id')->constrained('proforma_lines')->nullOnDelete());`; `down()` → `dropConstrainedForeignId('proforma_line_id')`.

- [ ] **Step 3: Models**
  - `InvoiceLine`: `$fillable` adds `'proforma_line_id'`; PHPDoc `@property int|null $proforma_line_id`; add `/** @return BelongsTo<ProformaLine, $this> */ public function proformaLine(): BelongsTo { return $this->belongsTo(ProformaLine::class); }`.
  - `ProformaLine`: add
```php
/** Quantity still available to invoice: not yet invoiced and not held by another draft invoice. */
public function pendingQuantity(?int $excludingInvoiceId = null): string
{
    $inDrafts = InvoiceLine::query()->where('proforma_line_id', $this->id)
        ->whereHas('invoice', fn (Builder $query) => $query->where('status', InvoiceStatus::Draft)
            ->when($excludingInvoiceId !== null, fn (Builder $q) => $q->whereKeyNot($excludingInvoiceId)))
        ->pluck('quantity');

    return Decimal::qty(Decimal::of($this->quantity)->minus(Decimal::of($this->invoiced_quantity))->minus(Decimal::of(Decimal::sum($inDrafts, 4))));
}
```
(imports `App\Enums\InvoiceStatus`, `App\Support\Money\Decimal`, `Illuminate\Database\Eloquent\Builder`).

- [ ] **Step 4: Actions and request**
  - `InvoiceFromProformaRequest::withValidator`: replace the `$remaining = …` line with `$remaining = $line->pendingQuantity();`.
  - `CreateInvoiceFromProforma::handle`: replace `$remaining = Decimal::qty(…)` with `$remaining = $line->pendingQuantity();`; add `'proforma_line_id' => $line->id,` to each `$lines[]` entry; **delete** the `$line->forceFill(['invoiced_quantity' => …])->save();` line and the whole `$fullyInvoiced …` block (lines 61-68) — the `created_from_proforma` activity stays.
  - `SaveInvoice::handle`: after computing `$computed`, build `$extra`:
```php
$existingLinks = $invoice?->proforma_id !== null ? $invoice->lines()->whereNotNull('proforma_line_id')->pluck('proforma_line_id', 'product_id')->all() : [];
$extra = [];
$byProformaLine = [];
foreach (array_values($data['lines']) as $index => $row) {
    $proformaLineId = isset($row['proforma_line_id']) ? (int) $row['proforma_line_id'] : ($existingLinks[(int) $row['product_id']] ?? null);
    $extra[$index] = ['proforma_line_id' => $proformaLineId];
    if ($proformaLineId !== null) {
        $byProformaLine[$proformaLineId][] = $index;
    }
}
if ($invoice !== null) {
    foreach ($byProformaLine as $proformaLineId => $indexes) {
        $proformaLine = ProformaLine::query()->whereKey($proformaLineId)->lockForUpdate()->firstOrFail();
        $pending = $proformaLine->pendingQuantity($invoice->id);
        $requested = Decimal::sum(array_map(fn (int $i) => (string) array_values($data['lines'])[$i]['quantity'], $indexes), 4);
        if (Decimal::cmp($requested, $pending) > 0) {
            throw ValidationException::withMessages(array_fill_keys(array_map(fn (int $i) => "lines.{$i}.quantity", $indexes), "Cantidad pendiente en la proforma: {$pending}."));
        }
    }
}
```
and call `DocumentLines::write($invoice, $computed, $extra);` (imports `App\Models\ProformaLine`, `App\Support\Money\Decimal`, `Illuminate\Validation\ValidationException`).
  - `IssueInvoice::handle`: the issue-time rewrite must keep the links — replace `DocumentLines::write($invoice, $computed);` with `DocumentLines::write($invoice, $computed, array_map(fn (InvoiceLine $line) => ['proforma_line_id' => $line->proforma_line_id], array_values($invoice->lines->all())));` (same order as the `LineInput`s built from `$invoice->lines`). Then, after the `forceFill([...])->save()` and before the `issued` activity, add `$this->invoiceProformaLines($invoice, $actor);` with:
```php
/** Consumes the proforma quantities now that the invoice is fiscal and marks the proforma invoiced when complete. */
private function invoiceProformaLines(Invoice $invoice, User $actor): void
{
    if ($invoice->proforma_id === null) {
        return;
    }
    $proforma = Proforma::query()->whereKey($invoice->proforma_id)->lockForUpdate()->with('lines')->firstOrFail();
    foreach ($invoice->lines()->whereNotNull('proforma_line_id')->get() as $line) {
        $proformaLine = $proforma->lines->firstWhere('id', $line->proforma_line_id);
        $proformaLine?->forceFill(['invoiced_quantity' => Decimal::qty(Decimal::of($proformaLine->invoiced_quantity)->plus(Decimal::of($line->quantity)))])->save();
    }
    if ($proforma->lines->every(fn (ProformaLine $line) => Decimal::cmp($line->invoiced_quantity, $line->quantity) >= 0)) {
        $proforma->forceFill(['status' => ProformaStatus::Invoiced, 'invoiced_at' => now()])->save();
        activity('proformas')->causedBy($actor)->performedOn($proforma)->event('invoiced')
            ->withProperties(['invoice_id' => $invoice->id])
            ->log("Proforma {$proforma->number} facturada");
    }
}
```
(imports `App\Models\Proforma`, `App\Models\ProformaLine`, `App\Enums\ProformaStatus`).
  - `CancelInvoice::handle`: before the `forceFill([... Cancelled ...])` add `$this->releaseProformaLines($invoice, $actor);` with:
```php
private function releaseProformaLines(Invoice $invoice, User $actor): void
{
    if ($invoice->proforma_id === null) {
        return;
    }
    $proforma = Proforma::query()->whereKey($invoice->proforma_id)->lockForUpdate()->with('lines')->firstOrFail();
    foreach ($invoice->lines->whereNotNull('proforma_line_id') as $line) {
        $proformaLine = $proforma->lines->firstWhere('id', $line->proforma_line_id);
        $proformaLine?->forceFill(['invoiced_quantity' => Decimal::qty(Decimal::of($proformaLine->invoiced_quantity)->minus(Decimal::of($line->quantity)))])->save();
    }
    if ($proforma->status === ProformaStatus::Invoiced) {
        $proforma->forceFill(['status' => ProformaStatus::Approved, 'invoiced_at' => null])->save();
        activity('proformas')->causedBy($actor)->performedOn($proforma)->event('reopened')
            ->withProperties(['invoice_id' => $invoice->id])
            ->log("Proforma {$proforma->number} reabierta por anulación de la factura {$invoice->number}");
    }
}
```
(imports `App\Models\Proforma`, `App\Enums\ProformaStatus`; `Decimal` is already imported).

- [ ] **Step 5: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -2 && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (474 tests).
- [ ] **Step 6: Commit** — `fix(sales): proforma quantities are consumed when the invoice is issued and released on cancel`

---

### Task 5: Dispatch button on paid invoices (I6), after-sale documents on inactive master data (I11), receipt-cancelled payload (minor)

**Files:**
- Modify: `app/Http/Controllers/Sales/InvoiceController.php`, `app/Http/Requests/Sales/Concerns/ValidatesDocumentLines.php`, `app/Http/Requests/Sales/StoreCreditNoteRequest.php`, `app/Http/Requests/Sales/StoreDebitNoteRequest.php`, `app/Http/Requests/Sales/StoreReceiptRequest.php`, `app/Actions/Sales/CancelReceipt.php`, `tests/Feature/Sales/InvoiceIssueTest.php`, `tests/Feature/Sales/CreditNoteTest.php`, `tests/Feature/Sales/ReceiptTest.php`

**Interfaces:**
- Produces: `ValidatesDocumentLines::lineRules(int $max = 200, bool $activeProductsOnly = true)`; credit/debit note requests call `lineRules(activeProductsOnly: false)`; `StoreReceiptRequest` accepts inactive clients; `receipt_cancelled` payload carries `retention_itbis`, `retention_isr`, `applied_total` (the totals before cancellation).

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Sales/InvoiceIssueTest.php`:
```php
test('paid invoices keep the dispatch action until fully dispatched', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => $i->total])->save();
    $i->refreshFinancials();
    expect($i->fresh()->status)->toBe(InvoiceStatus::Paid);
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->where('can.dispatch', true)->where('can.receipt', false));
    $i->forceFill(['dispatch_status' => 'complete'])->save();
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->where('can.dispatch', false));
});
```
Append to `tests/Feature/Sales/CreditNoteTest.php` (add `use App\Models\Client;` — `Product` was imported in Task 2):
```php
test('after-sale documents accept inactive clients and products', function () {
    actingAsRole('accounting');
    activeSequence('B03', ['range_to' => 100]);
    $i = issuedInvoice();
    $line = $i->lines->first();
    Product::query()->whereKey($line->product_id)->update(['is_active' => false]);
    Client::query()->whereKey($i->client_id)->update(['is_active' => false]);
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertRedirect()->assertSessionHas('success');
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['lines' => [['product_id' => Product::factory()->create(['is_active' => false])->id, 'description' => 'Flete', 'quantity' => '1', 'unit_price' => '10', 'discount_percent' => '0']]]))->assertRedirect()->assertSessionHas('success');
    $this->post(route('sales.receipts.store'), validReceiptPayload($i->fresh()))->assertRedirect()->assertSessionHas('success');
    expect($i->fresh()->status)->toBe(InvoiceStatus::Paid);
});
```
In `tests/Feature/Sales/ReceiptTest.php`, test `an advance is later applied to another invoice and the receipt can be cancelled reversing everything`, extend the expectation after `->and(AccountingEvent::where('type', 'receipt_cancelled')->first()->payload['reversed'])->toHaveCount(2)` with:
```php
        ->and(AccountingEvent::where('type', 'receipt_cancelled')->first()->payload)->toHaveKeys(['retention_itbis', 'retention_isr', 'applied_total'])
        ->and(AccountingEvent::where('type', 'receipt_cancelled')->first()->payload['applied_total'])->toBe('436.00')
```
Run: `php artisan test --compact --filter='InvoiceIssueTest|CreditNoteTest|ReceiptTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Implement**
  - `InvoiceController::show`: `'dispatch' => $user->can('inventory.dispatches.create') && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true) && $invoice->dispatch_status !== 'complete' && $invoice->kind !== InvoiceKind::Historical`.
  - `ValidatesDocumentLines::lineRules(int $max = 200, bool $activeProductsOnly = true)`: product rule `Rule::exists('products', 'id')->when($activeProductsOnly, fn ($rule) => $rule->where('is_active', true))` — if `Rule::exists()` has no `when`, write `$activeProductsOnly ? Rule::exists('products', 'id')->where('is_active', true) : Rule::exists('products', 'id')`. `lineMessages()` message `'lines.*.product_id.exists'` stays.
  - `StoreCreditNoteRequest::rules()` and `StoreDebitNoteRequest::rules()`: `...$this->lineRules(activeProductsOnly: false),`.
  - `StoreReceiptRequest::rules()`: `'client_id' => ['required', 'integer', 'exists:clients,id']`.
  - `CancelReceipt::handle`: capture `$appliedBefore = $receipt->applied_total;` before the `forceFill`; the `receipt_cancelled` payload adds `'retention_itbis' => $receipt->retention_itbis, 'retention_isr' => $receipt->retention_isr, 'applied_total' => $appliedBefore,`.

- [ ] **Step 3: Gate** → passed (476 tests).
- [ ] **Step 4: Commit** — `fix(sales): dispatch paid invoices, allow after-sale documents on inactive master data, complete the receipt_cancelled payload`

---

### Task 6: Historical prior payments in statements (I7) and aging rebuilt as of the cut-off date (I10)

**Files:**
- Create: `database/migrations/2026_11_01_000026_add_prior_paid_amount_to_invoices.php`
- Modify: `app/Models/Invoice.php`, `app/Actions/Sales/RegisterHistoricalInvoice.php`, `app/Services/Sales/ClientStatementService.php`, `app/Http/Controllers/Sales/StatementController.php`, `tests/Feature/Sales/ClientStatementTest.php`

**Interfaces:**
- Produces: `invoices.prior_paid_amount decimal(14,2) default 0` (payments made before the system, historical invoices only); statement movement type `prior_payment` ("Pagos anteriores"); `ClientStatementService::aging()` computes each invoice's balance as of `$asOf` from dated movements; `as_of` must be ≤ today (`La fecha de corte no puede ser futura.`).

- [ ] **Step 1: Failing tests** — in `tests/Feature/Sales/ClientStatementTest.php` add imports `use App\Models\Currency; use App\Models\Salesperson;`; **replace** the helper `openInvoiceDue` and the test `aging buckets open balances by days overdue and sums in base currency` with:
```php
function openInvoiceDue(Client $client, string $dueDate): Invoice
{
    $i = issuedInvoice(['client_id' => $client->id]);
    Invoice::query()->whereKey($i->id)->update(['due_date' => $dueDate]); // query builder: bypasses the immutability guard on purpose (test fixture)

    return $i->fresh();
}

test('aging buckets open balances by days overdue and sums in base currency', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00'));
    actingAsRole('accounting');
    $client = Client::factory()->create(['business_name' => 'Aged SRL']);
    $asOf = CarbonImmutable::parse('2026-11-30');
    openInvoiceDue($client, '2026-12-05');            // current
    openInvoiceDue($client, '2026-11-15');            // 15 days
    $partly = openInvoiceDue($client, '2026-10-15');  // 46 days, balance 200 after a 36.00 receipt
    $this->post(route('sales.receipts.store'), validReceiptPayload($partly, ['amount_received' => '36', 'applications' => [['invoice_id' => $partly->id, 'amount' => '36']]]))->assertRedirect();
    openInvoiceDue($client, '2026-09-10');            // 81 days
    openInvoiceDue($client, '2026-07-01');            // 152 days
    $cancelled = openInvoiceDue($client, '2026-07-01');
    app(CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');

    $aging = app(ClientStatementService::class)->aging(auth()->user(), $asOf);
    $row = collect($aging['rows'])->firstWhere('client_id', $client->id);
    expect($row['current'])->toBe('236.00')->and($row['d1_30'])->toBe('236.00')->and($row['d31_60'])->toBe('200.00')->and($row['d61_90'])->toBe('236.00')->and($row['d90_plus'])->toBe('236.00')
        ->and($row['total'])->toBe('1144.00')->and($row['total_base'])->toBe('1144.00')->and($aging['totals']['total_base'])->toBe('1144.00');
    $this->get(route('sales.statements.index', ['as_of' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/index')->where('aging.rows.0.client_name', 'Aged SRL')->where('aging.rows.0.d90_plus', '236.00'));
    $this->travelBack();
});
```
Append:
```php
test('historical invoices show their prior payments as a credit row in the statement and the aging', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.invoices.historical.store'), ['ncf' => 'B0100000777', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => Currency::base()->id, 'taxable_total' => '10000', 'itbis_total' => '1800', 'total' => '11800', 'paid_amount' => '1800', 'notes' => ''])->assertRedirect();
    $i = Invoice::firstOrFail();
    expect($i->prior_paid_amount)->toBe('1800.00')->and($i->paid_amount)->toBe('1800.00')->and($i->balance)->toBe('10000.00');

    $s = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
    $rows = $s['DOP']['rows'];
    expect($rows)->toHaveCount(2)->and($rows[0]['type'])->toBe('invoice')->and($rows[0]['debit'])->toBe('11800.00')
        ->and($rows[1]['type'])->toBe('prior_payment')->and($rows[1]['type_label'])->toBe('Pagos anteriores')->and($rows[1]['credit'])->toBe('1800.00')->and($rows[1]['balance'])->toBe('10000.00')
        ->and($s['DOP']['closing_balance'])->toBe('10000.00');

    $aging = collect(app(ClientStatementService::class)->aging(auth()->user(), CarbonImmutable::parse('2026-08-15'))['rows'])->firstWhere('client_id', $client->id);
    expect($aging['total'])->toBe('10000.00')->and($aging['current'])->toBe('10000.00');
});

test('aging as of a past date ignores later invoices and payments and refuses a future cut-off', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00'));
    $old = issuedInvoice(['client_id' => $client->id]); // 236.00, due 2026-10-01
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
    $this->post(route('sales.receipts.store'), validReceiptPayload($old, ['receipt_date' => '2026-10-20', 'amount_received' => '100', 'applications' => [['invoice_id' => $old->id, 'amount' => '100']]]))->assertRedirect();
    issuedInvoice(['client_id' => $client->id]); // 236.00, issued 2026-10-20
    $this->travelBack();

    $svc = app(ClientStatementService::class);
    $atSep30 = collect($svc->aging(auth()->user(), CarbonImmutable::parse('2026-09-30'))['rows'])->firstWhere('client_id', $client->id);
    expect($atSep30['total'])->toBe('236.00')->and($atSep30['current'])->toBe('236.00');
    $atOct25 = collect($svc->aging(auth()->user(), CarbonImmutable::parse('2026-10-25'))['rows'])->firstWhere('client_id', $client->id);
    expect($atOct25['total'])->toBe('372.00')->and($atOct25['d1_30'])->toBe('136.00')->and($atOct25['current'])->toBe('236.00');
    $this->get(route('sales.statements.index', ['as_of' => now()->addDay()->toDateString()]))->assertSessionHasErrors(['as_of' => 'La fecha de corte no puede ser futura.']);
});
```
Run: `php artisan test --compact --filter=ClientStatementTest 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Migration** `database/migrations/2026_11_01_000026_add_prior_paid_amount_to_invoices.php`: `up()` → `Schema::table('invoices', fn (Blueprint $t) => $t->decimal('prior_paid_amount', 14, 2)->default(0)->after('paid_amount'));` then `DB::statement("UPDATE invoices SET prior_paid_amount = paid_amount WHERE kind = 'historical'")`; `down()` → `dropColumn('prior_paid_amount')`.

- [ ] **Step 3: Model and action** — `Invoice`: `$fillable` adds `'prior_paid_amount'`, cast `'prior_paid_amount' => 'decimal:2'`, PHPDoc `@property string $prior_paid_amount`. `RegisterHistoricalInvoice::handle`: in the `create([...])` add `'prior_paid_amount' => $paidAmount,` next to `'paid_amount' => $paidAmount,`; add `'prior_paid_amount' => $invoice->prior_paid_amount,` to the `historical_invoice_registered` payload.

- [ ] **Step 4: `ClientStatementService`**
  - Movement type gets a sort key: extend the `Movement` phpstan-type with `order: int`; every movement array gets `'order'`: invoice `0`, debit_note `1`, credit_note `2`, prior_payment `3`, payment `4`. In `statement()` change the sort to `->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])`.
  - In `movements()` add, after `$invoices`:
```php
$priorPayments = Invoice::query()->where('client_id', $client->id)->whereNotNull('issued_at')->whereNot('status', InvoiceStatus::Cancelled)
    ->where('prior_paid_amount', '>', 0)->with('currency:id,code')->get()
    ->map(fn (Invoice $invoice) => [
        'date' => $invoice->issue_date->toDateString(), 'id' => $invoice->id, 'order' => 3, 'currency' => $invoice->currency->code,
        'type' => 'prior_payment', 'type_label' => 'Pagos anteriores', 'number' => $invoice->number, 'ncf' => $invoice->ncf,
        'description' => 'Pagos recibidos antes del sistema', 'debit' => Decimal::money('0'), 'credit' => Decimal::money($invoice->prior_paid_amount),
    ]);
```
and concat it after `$invoices` (`$invoices->concat($priorPayments)->concat($debitNotes)…`).
  - `aging()`: replace the invoice query and the map with a balance rebuilt as of `$asOf`:
```php
$asOfDate = $asOf->toDateString();
$invoices = Invoice::query()
    ->whereNotNull('issued_at')->whereDate('issue_date', '<=', $asOfDate)
    ->where(fn (Builder $query) => $query->whereNot('status', InvoiceStatus::Cancelled)->orWhereDate('cancelled_at', '>', $asOfDate))
    ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
    ->whereHas('client', …unchanged…)
    ->with(['client.salesperson:id,name', 'currency:id,code'])
    ->get();
$ids = $invoices->pluck('id')->all();
$debits = DebitNote::query()->whereIn('invoice_id', $ids)->where('status', 'issued')->whereDate('issue_date', '<=', $asOfDate)->selectRaw('invoice_id, SUM(total) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
$credits = CreditNote::query()->whereIn('invoice_id', $ids)->where('status', 'issued')->whereDate('issue_date', '<=', $asOfDate)->selectRaw('invoice_id, SUM(applied_amount) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
$payments = ReceiptApplication::query()->whereIn('invoice_id', $ids)->whereDate('applied_on', '<=', $asOfDate)
    ->where(fn (Builder $query) => $query->whereNotNull('credit_note_id')->orWhereHas('receipt', fn (Builder $r) => $r->where('status', ReceiptStatus::Posted)))
    ->selectRaw('invoice_id, SUM(amount) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
$invoices = $invoices->map(function (Invoice $invoice) use ($asOf, $debits, $credits, $payments): array {
    $balance = Decimal::money(Decimal::of($invoice->total)
        ->plus(Decimal::of((string) ($debits[$invoice->id] ?? '0')))
        ->minus(Decimal::of((string) ($credits[$invoice->id] ?? '0')))
        ->minus(Decimal::of((string) ($payments[$invoice->id] ?? '0')))
        ->minus(Decimal::of($invoice->prior_paid_amount)));
    $days = (int) $invoice->due_date->diffInDays($asOf, false);

    return ['invoice' => $invoice, 'bucket' => <unchanged match>, 'balance' => $balance, 'base' => Decimal::mul($balance, $invoice->exchange_rate, Decimal::MONEY)];
})->filter(fn (array $item) => Decimal::cmp($item['balance'], '0') > 0)->values();
```
(the grouping/bucket code below stays; remove the now-unused `whereIn('status', …)` filter).
  - `StatementController::asOf()`: `$request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']], ['as_of.before_or_equal' => 'La fecha de corte no puede ser futura.']);`.

- [ ] **Step 5: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -2 && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (478 tests).
- [ ] **Step 6: Commit** — `fix(sales): statement shows historical prior payments and aging is rebuilt as of the cut-off date`

---

### Task 7: NCF collisions with existing invoices are refused at registration and at assignment (I8)

**Files:**
- Modify: `app/Services/Fiscal/FiscalNumberingService.php`, `app/Http/Requests/Admin/StoreNcfSequenceRequest.php`, `tests/Feature/Fiscal/FiscalNumberingTest.php`, `tests/Feature/Fiscal/NcfSequenceAdminTest.php`

**Interfaces:**
- Produces: `FiscalNumberingService::assign()` throws `FiscalNumberingException` (`El NCF {ncf} ya existe (factura histórica o importada); ajusta el inicio de la secuencia {type} para continuar después del último NCF usado.`) when the computed NCF exists in `invoices`, `credit_notes` or `debit_notes`; the transaction rolls back, so the number is not consumed. `StoreNcfSequenceRequest` adds error `range_from` → `El rango incluye el NCF {ncf}, ya emitido; inicia la secuencia después del último NCF usado.` when any document NCF of that type with a sequential inside `[range_from, range_to]` belongs to no sequence or to another sequence.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Fiscal/FiscalNumberingTest.php` (add `use App\Models\Invoice;`):
```php
test('an ncf already used by a historical invoice is refused instead of crashing', function () {
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100]);
    Invoice::factory()->create(['kind' => 'historical', 'status' => 'issued', 'fiscal_mode' => 'external', 'ncf' => 'B0100000001', 'ncf_type' => 'B01', 'issued_at' => now()]);
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::today()))
        ->toThrow(FiscalNumberingException::class, 'El NCF B0100000001 ya existe (factura histórica o importada); ajusta el inicio de la secuencia B01 para continuar después del último NCF usado.');
    expect($seq->fresh()->next_number)->toBe(1);
});
```
Append to `tests/Feature/Fiscal/NcfSequenceAdminTest.php` (add `use App\Models\Invoice;`):
```php
test('a sequence range cannot include ncfs already used by invoices outside it', function () {
    actingAsRole('owner');
    Invoice::factory()->create(['kind' => 'historical', 'status' => 'issued', 'fiscal_mode' => 'external', 'ncf' => 'B0100000123', 'ncf_type' => 'B01', 'issued_at' => now()]);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 1, 'range_to' => 200]))
        ->assertSessionHasErrors(['range_from' => 'El rango incluye el NCF B0100000123, ya emitido; inicia la secuencia después del último NCF usado.']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 124, 'range_to' => 200]))->assertRedirect(route('admin.ncf-sequences.index'));
    $seq = NcfSequence::firstOrFail();
    Invoice::factory()->create(['status' => 'issued', 'fiscal_mode' => 'ncf', 'ncf' => 'B0100000124', 'ncf_type' => 'B01', 'ncf_sequence_id' => $seq->id, 'issued_at' => now()]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_from' => 124, 'range_to' => 300]))->assertRedirect(route('admin.ncf-sequences.index')); // its own NCFs do not collide
});
```
Run: `php artisan test --compact --filter='FiscalNumberingTest|NcfSequenceAdminTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Service** — in `FiscalNumberingService::assign`, after computing `$number` and before mutating `$usable`, add:
```php
$ncf = $type->value.str_pad((string) $number, 8, '0', STR_PAD_LEFT);
if ($this->ncfExists($ncf)) {
    throw new FiscalNumberingException("El NCF {$ncf} ya existe (factura histórica o importada); ajusta el inicio de la secuencia {$type->value} para continuar después del último NCF usado.");
}
```
use `$ncf` in the returned `FiscalNumber`, and add `private function ncfExists(string $ncf): bool { return Invoice::query()->where('ncf', $ncf)->exists() || CreditNote::query()->where('ncf', $ncf)->exists() || DebitNote::query()->where('ncf', $ncf)->exists(); }` (imports `App\Models\Invoice`, `App\Models\CreditNote`, `App\Models\DebitNote`).

- [ ] **Step 3: Request** — in `StoreNcfSequenceRequest::withValidator`, after the overlap check add:
```php
$used = $this->firstUsedNcfInRange($type, $this->integer('range_from'), $this->integer('range_to'), $currentId);
if ($used !== null) {
    $v->errors()->add('range_from', "El rango incluye el NCF {$used}, ya emitido; inicia la secuencia después del último NCF usado.");
}
```
and the helper:
```php
/** Lowest NCF of this type inside the range that was not issued by this sequence (historical, imported or another sequence). */
private function firstUsedNcfInRange(NcfType $type, int $from, int $to, ?int $excludeSequenceId): ?string
{
    $found = [];
    foreach ([Invoice::class, CreditNote::class, DebitNote::class] as $model) {
        $ncf = $model::query()->where('ncf_type', $type->value)
            ->whereRaw('CAST(SUBSTR(ncf, 4) AS INTEGER) BETWEEN ? AND ?', [$from, $to])
            ->when($excludeSequenceId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('ncf_sequence_id')->orWhere('ncf_sequence_id', '!=', $excludeSequenceId)))
            ->orderBy('ncf')->value('ncf');
        if ($ncf !== null) {
            $found[] = (string) $ncf;
        }
    }
    sort($found);

    return $found[0] ?? null;
}
```
(imports `App\Models\Invoice`, `App\Models\CreditNote`, `App\Models\DebitNote`).

- [ ] **Step 4: Gate** → passed (480 tests).
- [ ] **Step 5: Commit** — `fix(fiscal): refuse ncf ranges and assignments that collide with existing invoice ncfs`

---

### Task 8: FEFO skips expired lots; confirming expired lots needs an audited override (I9)

**Files:**
- Create: `app/Http/Requests/Inventory/ConfirmDispatchRequest.php`
- Modify: `app/Support/PermissionCatalog.php`, `app/Services/Inventory/InventoryService.php`, `app/Actions/Inventory/ConfirmDispatch.php`, `app/Http/Controllers/Inventory/DispatchController.php`, `resources/js/types/inventory.ts`, `resources/js/pages/inventory/dispatches/form.tsx`, `resources/js/pages/inventory/dispatches/show.tsx`, `tests/Feature/Admin/PermissionCatalogTest.php`, `tests/Feature/Inventory/InventoryServiceTest.php`, `tests/Feature/Inventory/DispatchTest.php`

**Interfaces:**
- Produces: permission `inventory.dispatches.expired-override` ("Autorizar despacho de lotes vencidos", roles `owner`, `management`) → catalog **120**; `InventoryService::suggestLots(int $productId, int $warehouseId, string $quantity, ?CarbonInterface $onDate = null): array` excludes lots with `expires_on < onDate` (default today); `ConfirmDispatch::handle(Dispatch $dispatch, User $actor, bool $expiredOverride = false, ?string $overrideReason = null): Dispatch`; `ConfirmDispatchRequest` (`expired_override` boolean, `expired_override_reason` required when accepted); `DispatchController::stockedLots` rows gain `expired: bool`; dispatch detail gains `has_expired_lots: bool`; show page `can.expiredOverride`.

- [ ] **Step 1: Failing tests**

`tests/Feature/Admin/PermissionCatalogTest.php`: change `->and(Permission::count())->toBe(119)` to `->toBe(120)` and append to the same chain `->and(Role::findByName('management')->hasPermissionTo('inventory.dispatches.expired-override'))->toBeTrue()->and(Role::findByName('warehouse')->hasPermissionTo('inventory.dispatches.expired-override'))->toBeFalse()`.

Append to `tests/Feature/Inventory/InventoryServiceTest.php`:
```php
test('fefo skips lots that are already expired', function () {
    $p = Product::factory()->create(['tracks_lots' => true]);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10', null, 'EXP', now()->subDay()->toDateString()), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '3', '10', null, 'OK', now()->addMonth()->toDateString()), StockMovementType::Initial, $this->actor, $this->actor);
    $s = $this->svc->suggestLots($p->id, $this->wh->id, '4');
    expect($s)->toHaveCount(1)->and($s[0]['code'])->toBe('OK')->and($s[0]['quantity'])->toBe('3.0000');
    expect($this->svc->suggestLots($p->id, $this->wh->id, '4', now()->subDays(2)))->toHaveCount(1)->and($this->svc->suggestLots($p->id, $this->wh->id, '4', now()->subDays(2))[0]['code'])->toBe('EXP');
});
```
Append to `tests/Feature/Inventory/DispatchTest.php`:
```php
test('expired lots are skipped by fefo and refused at confirm unless an authorised user overrides with a reason', function () {
    $user = actingAsRole('warehouse');
    activeSequence('B01', ['range_to' => 100]);
    $product = Product::factory()->create(['tracks_lots' => true, 'itbis_rate' => '18.00']);
    $wh = Warehouse::factory()->create(['is_default' => true]);
    $svc = app(InventoryService::class);
    $svc->receive(new StockLine($product->id, $wh->id, '5', '10', null, 'OLD', now()->subDay()->toDateString()), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($product->id, $wh->id, '5', '10', null, 'OK', now()->addMonth()->toDateString()), StockMovementType::Initial, $user, $user);
    $draft = app(SaveInvoice::class)->handle(validInvoicePayload(['warehouse_id' => $wh->id, 'salesperson_id' => Salesperson::factory()->create()->id, 'lines' => [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0', 'description' => '']]]), $user->givePermissionTo('sales.invoices.view-all'));
    $i = app(IssueInvoice::class)->handle($draft, $user);
    $line = $i->lines->first();
    $old = Lot::where('code', 'OLD')->firstOrFail();

    $this->get(route('inventory.dispatches.create', ['invoice' => $i->id]))->assertInertia(fn ($p) => $p->has('invoice.lines.0.suggested_lots', 1)->where('invoice.lines.0.suggested_lots.0.code', 'OK')->where('lots.0.code', 'OLD')->where('lots.0.expired', true));
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '2', 'lot_id' => $old->id]]]))->assertRedirect();
    $d = Dispatch::firstOrFail();
    $this->get(route('inventory.dispatches.show', $d))->assertInertia(fn ($p) => $p->where('dispatch.has_expired_lots', true)->where('can.expiredOverride', false));
    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect()->assertSessionHas('error', 'El lote OLD de '.$product->sku.' venció el '.now()->subDay()->format('d/m/Y').'; un usuario autorizado debe aprobar el despacho de lotes vencidos.');
    $this->post(route('inventory.dispatches.confirm', $d), ['expired_override' => '1', 'expired_override_reason' => 'Cliente acepta'])->assertForbidden();
    expect($d->fresh()->status)->toBe(DispatchStatus::Draft)->and(StockMovement::where('type', 'dispatch')->count())->toBe(0);

    $owner = actingAsRole('owner');
    $this->post(route('inventory.dispatches.confirm', $d), ['expired_override' => '1'])->assertSessionHasErrors(['expired_override_reason' => 'Indica el motivo de la autorización.']);
    $this->post(route('inventory.dispatches.confirm', $d), ['expired_override' => '1', 'expired_override_reason' => 'Cliente acepta'])->assertRedirect()->assertSessionHas('success');
    expect($d->fresh()->status)->toBe(DispatchStatus::Confirmed)
        ->and(Activity::inLog('dispatches')->where('event', 'expired_lot_override')->where('subject_id', $d->id)->where('causer_id', $owner->id)->first()->properties['reason'])->toBe('Cliente acepta');
});
```
Run: `php artisan test --compact --filter='PermissionCatalogTest|InventoryServiceTest|DispatchTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Permission** — `PermissionCatalog::permissions()`, after `inventory.dispatches.confirm`: `$add('inventory.dispatches.expired-override', 'Autorizar despacho de lotes vencidos', ['owner', 'management']);`. Seed the dev DB: `php artisan db:seed --class=PermissionSeeder --no-interaction`.

- [ ] **Step 3: Service** — `suggestLots(int $productId, int $warehouseId, string $quantity, ?CarbonInterface $onDate = null): array` (import `Carbon\CarbonInterface`): `$cutoff = ($onDate ?? today())->toDateString();` and filter the rows before sorting with `->filter(fn (StockBalance $row) => $row->lot->expires_on === null || $row->lot->expires_on->toDateString() >= $cutoff)`.

- [ ] **Step 4: Request** `app/Http/Requests/Inventory/ConfirmDispatchRequest.php` — same shape as `IssueInvoiceRequest`: `authorize()` returns `true`; rules `'expired_override' => ['nullable', 'boolean'], 'expired_override_reason' => ['nullable', 'string', 'max:255', 'required_if_accepted:expired_override']`; message `'expired_override_reason.required_if_accepted' => 'Indica el motivo de la autorización.'`.

- [ ] **Step 5: Action** — `ConfirmDispatch::handle(Dispatch $dispatch, User $actor, bool $expiredOverride = false, ?string $overrideReason = null): Dispatch`; load `->with(['lines.product', 'lines.lot'])`; after the invoice status check add:
```php
$expired = $dispatch->lines->filter(fn ($line) => $line->lot?->expires_on !== null && $line->lot->expires_on->toDateString() < $dispatch->dispatch_date->toDateString());
if ($expired->isNotEmpty() && ! $expiredOverride) {
    $line = $expired->first();

    throw new DocumentStateException("El lote {$line->lot->code} de {$line->product->sku} venció el {$line->lot->expires_on->format('d/m/Y')}; un usuario autorizado debe aprobar el despacho de lotes vencidos.");
}
```
and, right after the `confirmed` activity:
```php
if ($expired->isNotEmpty()) {
    activity('dispatches')->causedBy($actor)->performedOn($dispatch)->event('expired_lot_override')
        ->withProperties(['number' => $number, 'lots' => $expired->map(fn ($line) => $line->lot->code)->values()->all(), 'reason' => $overrideReason])
        ->log("Despacho de lotes vencidos autorizado en el conduce {$number}");
}
```

- [ ] **Step 6: Controller**
  - `confirm(ConfirmDispatchRequest $request, Dispatch $dispatch, ConfirmDispatch $confirmDispatch)`: `$user = $this->actor($request); $override = $request->boolean('expired_override'); if ($override && ! $user->can('inventory.dispatches.expired-override')) { abort(403); } $confirmed = $confirmDispatch->handle($dispatch, $user, $override, $request->string('expired_override_reason')->trim()->toString() ?: null);`.
  - `show`: `can` adds `'expiredOverride' => $user->can('inventory.dispatches.expired-override')`; `dispatchDetail()` adds `'has_expired_lots' => $dispatch->lines->contains(fn ($line) => $line->lot?->expires_on !== null && $line->lot->expires_on->toDateString() < $dispatch->dispatch_date->toDateString())` (the `lines.lot` relation is already loaded in `show`).
  - `stockedLots()`: each row adds `'expired' => $balance->lot->expires_on !== null && $balance->lot->expires_on->toDateString() < today()->toDateString()` (update the PHPDoc shape).

- [ ] **Step 7: Frontend**
  - `types/inventory.ts`: `DispatchLotOption` adds `expired: boolean;`; `DispatchDetail` adds `has_expired_lots: boolean;`.
  - `dispatches/form.tsx`: inside the lot `SelectItem`, after the `vence` fragment, add `{lot.expired ? ' · VENCIDO' : ''}`.
  - `dispatches/show.tsx`: `can` type adds `expiredOverride: boolean`; `const [overrideReason, setOverrideReason] = useState('')` (import `useState`, `Textarea`); the confirm `AlertDialogDescription` becomes `{dispatch.has_expired_lots ? 'Este conduce incluye lotes vencidos. ' : ''}Se descontará el inventario. Esta acción no se puede deshacer.`; when `dispatch.has_expired_lots && abilities.expiredOverride` render `<Textarea placeholder="Motivo de la autorización" maxLength={255} value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} />` under the description; the `AlertDialogAction` posts `dispatch.has_expired_lots && abilities.expiredOverride ? { expired_override: true, expired_override_reason: overrideReason } : {}` and is `disabled` when `dispatch.has_expired_lots && abilities.expiredOverride && overrideReason.trim() === ''`.

- [ ] **Step 8: Gate** — `npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (482 tests).
- [ ] **Step 9: Commit** — `fix(inventory): fefo skips expired lots and confirming them requires an audited override`

---

### Task 9: Minors — Gmail reconnect message, initial-stock bounds, receipt sums from the server (GC-12)

**Files:**
- Create: `app/Http/Requests/Sales/ReceiptPreviewRequest.php`, `resources/js/hooks/use-receipt-preview.ts`
- Modify: `app/Exceptions/GmailNotConnectedException.php`, `app/Services/Mail/GoogleGmailSender.php`, `app/Imports/InitialStockImporter.php`, `app/Http/Controllers/Sales/ReceiptController.php`, `routes/sales.php`, `resources/js/components/erp/apply-advance-dialog.tsx`, `resources/js/pages/sales/receipts/form.tsx`, `resources/js/pages/sales/receipts/show.tsx`, `resources/js/types/sales.ts`, `tests/Feature/Mail/GmailSenderTest.php`, `tests/Feature/DataImport/InitialStockImporterTest.php`, `tests/Feature/Sales/ReceiptTest.php`

**Interfaces:**
- Produces: `GmailNotConnectedException::__construct(?string $message = null)`; `InitialStockImporter` rules `quantity` and `unit_cost` bounded to `decimal(14,4)`; route `POST sales/receipts/preview` → `sales.receipts.preview` (`ReceiptController::preview`) returning `{credit_total, applied_total, unapplied_amount}` as money strings; receipt show prop `receipt.retention_total`; receipt create prop `advanceTotals: array<string, string>` keyed by currency id; React hook `useReceiptPreview(input: ReceiptPreviewInput): ReceiptPreview | null`.

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Mail/GmailSenderTest.php` (add `use App\Support\Mail\SentEmailResult;`):
```php
test('a rejected gmail token is reported as a reconnect request instead of a server error', function () {
    expect((new GmailNotConnectedException)->getMessage())->toBe('Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    $this->app->instance(GmailSender::class, new class implements GmailSender
    {
        public function send(User $sender, OutgoingEmail $email): SentEmailResult
        {
            throw new GmailNotConnectedException('Google rechazó la conexión con Gmail (invalid_grant). Reconecta tu cuenta de Gmail.');
        }
    });
    $client = Client::factory()->create(['email' => 'cliente@x.test']);
    $this->post(route('sales.statements.send', $client), ['to' => ['cliente@x.test']])->assertRedirect()->assertSessionHas('error', 'Google rechazó la conexión con Gmail (invalid_grant). Reconecta tu cuenta de Gmail.');
    expect(SentEmail::firstOrFail()->status)->toBe('failed');
});
```
Append to `tests/Feature/DataImport/InitialStockImporterTest.php`:
```php
test('initial stock quantities and costs are bounded to the column precision', function () {
    $wh = Warehouse::factory()->create();
    Product::factory()->create(['sku' => 'B-1']);
    Product::factory()->create(['sku' => 'B-2']);
    Product::factory()->create(['sku' => 'B-3']);
    actingAsRole('warehouse');
    $file = makeSpreadsheet([['SKU', 'Cantidad', 'Costo unitario', 'Lote', 'Vencimiento'], ['B-1', '99999999999', '1', '', ''], ['B-2', '1', '1.12345', '', ''], ['B-3', '1.5', '350.5', '', '']]);
    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file, 'warehouse_id' => $wh->id])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(1)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 2)->first()->errors)->toHaveKey('quantity')
        ->and($batch->rows()->where('row_number', 3)->first()->errors)->toHaveKey('unit_cost');
});
```
Append to `tests/Feature/Sales/ReceiptTest.php`:
```php
test('the receipt preview sums applications and retentions on the server', function () {
    actingAsRole('accounting');
    $this->postJson(route('sales.receipts.preview'), ['amount_received' => '100.10', 'retention_itbis' => '0.20', 'retention_isr' => '', 'applications' => [['invoice_id' => 1, 'amount' => '50.05'], ['invoice_id' => 2, 'amount' => '0.05']]])
        ->assertOk()->assertJson(['credit_total' => '100.30', 'applied_total' => '50.10', 'unapplied_amount' => '50.20']);
    actingAsRole('sales');
    $this->postJson(route('sales.receipts.preview'), ['applications' => []])->assertForbidden();
});
```
and in the test `permissions and scoping: sales can view own receipts but not create or cancel` extend the `show` assertion chain with `->where('receipt.retention_total', '0.00')`.

Run: `php artisan test --compact --filter='GmailSenderTest|InitialStockImporterTest|ReceiptTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Gmail** — `GmailNotConnectedException::__construct(?string $message = null) { parent::__construct($message ?? 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.'); }`. `GoogleGmailSender::send`: replace `throw new \RuntimeException('Google rechazó el token de Gmail: '.$token['error']);` with `throw new GmailNotConnectedException('Google rechazó la conexión con Gmail ('.$token['error'].'). Reconecta tu cuenta de Gmail.');`.

- [ ] **Step 3: Importer bounds** — `InitialStockImporter::rules()`: `'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/']`, `'unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/']`.

- [ ] **Step 4: Receipt preview backend**
  - `ReceiptPreviewRequest`: `authorize()` → `$this->user()?->can('sales.receipts.create') ?? false`; rules `'amount_received' => ['nullable', 'numeric'], 'retention_itbis' => ['nullable', 'numeric'], 'retention_isr' => ['nullable', 'numeric'], 'applications' => ['present', 'array', 'max:100'], 'applications.*.amount' => ['nullable', 'numeric']`.
  - `ReceiptController::preview(ReceiptPreviewRequest $request): JsonResponse`: `$money = fn (mixed $v): string => is_numeric($v) ? Decimal::money((string) $v) : '0.00';` `$credit = Decimal::sum([$money($request->input('amount_received')), $money($request->input('retention_itbis')), $money($request->input('retention_isr'))]);` `$applied = Decimal::sum(array_map(fn (array $a) => $money($a['amount'] ?? null), $request->validated('applications')));` return `response()->json(['credit_total' => $credit, 'applied_total' => $applied, 'unapplied_amount' => Decimal::money(Decimal::of($credit)->minus(Decimal::of($applied)))])` (import `Illuminate\Http\JsonResponse`). Middleware attribute `permission:sales.receipts.create`.
  - `routes/sales.php`: before the receipts `Route::resource`, add `Route::post('receipts/preview', [ReceiptController::class, 'preview'])->name('receipts.preview');`.
  - `ReceiptController::create`: add `'advanceTotals' => Currency::query()->where('is_active', true)->get()->mapWithKeys(fn (Currency $currency) => [(string) $currency->id => $client === null ? '0.00' : ClientAccount::advanceBalance($client, $currency)])->all()` (import `App\Services\Sales\ClientAccount`).
  - `ReceiptController::show`: receipt prop adds `'retention_total' => Decimal::sum([$receipt->retention_itbis, $receipt->retention_isr])`.
  - Run `php artisan wayfinder:generate --with-form --no-interaction >/dev/null`.

- [ ] **Step 5: Frontend**
  - `resources/js/hooks/use-receipt-preview.ts` — same fetch/debounce pattern as `use-totals-preview.ts`, posting to `preview.url()` from `@/routes/sales/receipts`:
```ts
export type ReceiptPreviewInput = { amount_received: string; retention_itbis: string; retention_isr: string; applications: { invoice_id: number; amount: string }[] };
export type ReceiptPreview = { credit_total: string; applied_total: string; unapplied_amount: string };
export function useReceiptPreview(input: ReceiptPreviewInput): ReceiptPreview | null
```
(400 ms debounce; the effect depends on `JSON.stringify(input)`; network failures keep the previous value).
  - `receipts/form.tsx`: remove `advanceTotal`, `appliedTotal`, `creditTotal` (`Number` math); props add `advanceTotals: Record<string, string>`; `const advanceTotal = advanceTotals[String(data.currency_id ?? '')] ?? '0.00';` and the alert renders when `advanceTotal !== '0.00'` with text `Este cliente tiene saldo a favor por {formatMoney(advanceTotal, currency)} — aplícalo desde el recibo o la nota de crédito correspondiente.`; `const summary = useReceiptPreview({ amount_received: data.amount_received, retention_itbis: data.retention_itbis, retention_isr: data.retention_isr, applications: selectedApplications.map(({ invoice_id, amount }) => ({ invoice_id, amount })) });` and the `<dl>` shows `summary ? formatMoney(summary.applied_total, currency) : '—'` and `summary ? formatMoney(summary.unapplied_amount, currency) : '—'`.
  - `receipts/show.tsx`: `Retenciones` value → `formatMoney(receipt.retention_total ?? '0.00', currency)`; `types/sales.ts` `Receipt` adds `retention_total?: string;`.
  - `apply-advance-dialog.tsx`: replace the `applied` reducer with `const summary = useReceiptPreview({ amount_received: '0', retention_itbis: '0', retention_isr: '0', applications });` and render `Total a aplicar: {summary ? formatMoney(summary.applied_total, currency) : '—'}`; the `applications` filter keeps rows whose `amount` is a non-empty string (`(amounts[invoice.id] ?? '').trim() !== ''`) instead of `Number(...) > 0`.

- [ ] **Step 6: Gate** — `npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -8` → passed (485 tests).
- [ ] **Step 7: Commit** — `fix(sales): gmail reconnect flash, initial stock bounds, receipt sums computed on the server`

---

## After the last task

Report to the coordinator: `git log --oneline f6d77d2..HEAD` (9 commits), the final phpunit line (expected 485 tests, indicative), `php artisan migrate:status | tail -4` (three new migrations ran) and `docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT count(*) FROM permissions"` → `120`. The coordinator runs the scoped re-review and then merges `phase-2-sales-ncf` into `main` with `git merge --no-ff`.

## Self-review notes (planner)

- Decisions taken per spec/rulings where the review left a choice: I1 is enforced as a validation rule (not silently forced); the credit-note tax snapshot applies to every reason (not only discount) so notes never depend on the product's current tax setup; `invoices.credited_amount` now means "credit applied to this invoice" (GC-15) and the fiscal cap reads Σ `credit_notes.total`; client credit is applied with the receipts permission `sales.receipts.create`; proforma pending quantities exclude quantities held by other drafts (`ProformaLine::pendingQuantity`) so two drafts cannot double-book a line; editing a proforma-linked draft re-links lines by product; statement movements get a stable per-date `order` key (needed for the new "Pagos anteriores" row); `applied_on` dates applications so aging can be rebuilt; cancelled receipts never count in aging even for cut-offs before the cancellation.
- Types consistent across tasks: `applied_amount`/`unapplied_amount` (Task 3) are consumed by Task 6's aging; `applied_on` (Task 3) by Task 6; `pendingQuantity()` (Task 4) only inside Task 4; `ApplyAdvanceDialog.applyUrl` (Task 3) by Task 9.
