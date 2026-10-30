# Phase 2 — Sales with traditional NCF + basic inventory: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Executors are Claude Haiku agents that **decide nothing**: read `docs/superpowers/plans/2026-10-29-00-architecture.md` §11 first, then the Phase 1 plan's Global Constraints (they still apply), then this plan's Global Constraints.

**Goal:** First production release for invoicing: proformas → invoices with traditional NCF (B01/B02/B14/B15) → credit/debit notes (B04/B03) → dispatches (conduces) → receipts (cobros) with retentions and advances, client statements and aging, PDFs, e-mail sending through the user's own Gmail, warehouse stock with lots (FEFO), reservations and returns, initial stock and historical open invoices imports, and an `accounting_events` outbox so Phase 5 can generate journal entries retroactively.

**Architecture:** Documents are Eloquent models with line models; all money math goes through `DocumentTotalsCalculator` (ARCH §4) and `Decimal`. Fiscal numbering is centralised in `FiscalNumberingService` (traditional NCF now; Phase 3 plugs the `EcfProvider` behind the same service). Issued fiscal documents are immutable (`ImmutableWhenIssued`). Inventory changes only through `InventoryService`. Every state transition writes an activity row and, when it has accounting meaning, an `accounting_events` row.

**Tech Stack:** Phase 1 stack + `barryvdh/laravel-dompdf ^3.1` (PDF) + `google/apiclient ^2.20` (Gmail send-as-user).

**Spec:** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/specs/2026-10-29-gomez-erp-design.md` — this plan implements §3.3, §3.4.1, §3.6 (stock, movements, kardex, reserved/available, minimum-stock alerts, lots/FEFO; counts/adjustments/transfers are Phase 4), §3.10 (sending only), §3.12 (initial stock and historical invoices), §3.14 numbering, §9 item 2.

**Architecture doc (inherited):** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/plans/2026-10-29-00-architecture.md` (*ARCH §n*). **Phase 1 plan (interfaces consumed):** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/plans/2026-10-29-01-fase1-base.md` (*P1 GC-n*, *P1 Task n*).

**Branch:** `phase-2-sales-ncf` (created from `main` at merge commit `cf3cfca`, Phase 1 complete). Commit rule: P1 GC-1 (`vendor/bin/pint --dirty && git add -A -- . ':(exclude)docs' && git commit -q -m "<subject>" -m "Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>"`). Test totals continue from Phase 1's final count (**334**, verified 2026-10-30 with `php artisan test --compact`). PHPStan level 7 must stay clean (`composer test` runs it).

**Standing rulings inherited from Phase 1 execution** (`.superpowers/sdd/2026-10-29-01-fase1-base/rulings.md`), binding for every task here:
- brick/math is 1.x: the rounding enum is `RoundingMode::HalfUp` (never `HALF_UP`).
- PHPStan level-7 errors in plan code may be fixed by the executor with TYPE-ONLY changes (casts, PHPDoc, `instanceof` guards, `$request->string()/integer()/boolean()`, `whereKey()->first()` instead of `find()`, `getAttribute('x')` on generic models); never `ignoreErrors`, baselines, signature or logic changes. List each under "PHPStan type-only fixes" in the report.
- `destroy()` wraps the delete in a savepoint: `try { DB::transaction(fn () => $model->delete()); } catch (QueryException $e) { if ($e->getCode() === '23503') { return back()->with('error', 'No se puede eliminar: el registro está en uso'); } throw $e; }`.
- Generated codes are reserved with `nextval(pg_get_serial_sequence('<table>', 'id'))` before a single INSERT when a code depends on the id; document numbers here come from `DocumentNumbering` instead.
- Every numeric input gets `max` bounds matching the column precision (money `max:999999999999.99`, quantities `regex:/^\d{1,10}(\.\d{1,4})?$/`).
- `Http::preventStrayRequests()` is global in tests (`tests/Pest.php`); tests seed `PermissionSeeder` once (`$seed = true` in `tests/TestCase.php`); `actingAsRole()` only reseeds when roles are missing.
- When a plan TEST contradicts the behaviour its own name states, the executor may make the minimal TEST-ONLY change and list it under "Test corrections"; production code is never bent to a wrong test.
- `exists:` rules on master-data ids are not enough: every document form in this phase uses `Rule::exists(...)->where('is_active', true)` so inactive clients, salespeople, products, warehouses and price lists are rejected (messages given per task).

---

## Global Constraints

Phase 1 GC-1 … GC-9 apply unchanged (environment, JSON test summaries, gate `vendor/bin/pint --dirty && composer test 2>&1 | tail -6`, commit rule, Spanish copy, database conventions, authorization, flash, shared components, resource pattern, audit, Boost). PostgreSQL host port is **5435**. The Boost Pest skill is named `testing-best-practices`. The additions below are Phase 2 specific.

### GC-10 Regulatory facts baked into this plan (sources verified 2026-10-29)

| Fact | Source |
|---|---|
| NCF structure: letter `B` (serie) + 2 digits (tipo) + 8 digits (secuencial) = 11 characters, in force since 2018-05-01. | DGII Aviso 13-18 "Estructura de Número de Comprobantes Fiscales" (https://www.dgii.gov.do/publicacionesOficiales/avisosInformativos/Documents/2018/13-18.pdf); Guía Informativa sobre Comprobantes Fiscales (DGII, marzo 2026) §2 (https://dgii.gov.do/publicacionesOficiales/bibliotecaVirtual/contribuyentes/facturacion/Documents/Comprobantes%20Fiscales/2-Guia-Informativa-NCF.pdf) |
| Tipos: 01 Factura de Crédito Fiscal, 02 Factura de Consumo, 03 Nota de Débito, 04 Nota de Crédito, 11 Comprobante de Compras, 12 Registro Único de Ingresos, 13 Gastos Menores, 14 Regímenes Especiales, 15 Gubernamental, 16 Exportaciones, 17 Pagos al Exterior. | Guía Informativa NCF §3 |
| Sequences are valid up to two calendar years (until 31 December of the year after authorisation); this does **not** apply to Facturas de Consumo (02), Notas de Crédito (04) and Registro Único de Ingresos (12). | Norma General 06-2018 art. 6; Guía Informativa NCF §6 |
| Printed format (art. 7): top-right: denominación del tipo de comprobante, NCF, **fecha de vencimiento de la secuencia (only for comprobantes con valor fiscal)**; notas de crédito/débito must also show the NCF modified; top-left: RNC emisor, nombre/razón social, nombre comercial, punto de emisión (sucursal), fecha de emisión; below header left: RNC y nombre/razón social del cliente; centre: bienes/servicios, valores, impuestos, datos del documento (Decreto 254-06 art. 8); footer: datos de la imprenta (if different from the emitter) and "original/copia". | Norma General 06-2018 art. 7 (https://www.dgii.gov.do/legislacion/normasGenerales/Documents/NG%20sobre%20Comprobantes%20Fiscales/Norma06-18.pdf); Guía Informativa NCF §7 |
| Facturas de consumo with a value **equal or above RD$250,000.00** must record the identity document of the buyer and are reported individually in Formato 607; the DGII may adjust the amount for inflation. | Norma General 10-2018 art. 1 (modifies NG 07-2018 art. 4) (https://www.dgii.gov.do/legislacion/normasGenerales/Documents/NG%20sobre%20Comprobantes%20Fiscales/Norma10-18.pdf) |
| e-CF types 31–47 exist (Aviso 24-2019) — Phase 3. | Guía Informativa NCF §3 |

Nothing else regulatory is assumed. Formats 606/607/608 are Phase 5.

### GC-11 Document conventions
- Internal numbers: `App\Services\Documents\DocumentNumbering::next(DocumentKind $kind): string` → `COT-000001` (proforma), `FAC-000001` (invoice), `NC-000001` (credit note), `ND-000001` (debit note), `REC-000001` (receipt), `CND-000001` (dispatch). Six digits, zero padded, never reused, assigned inside the issuing transaction with `lockForUpdate()`.
- Money columns on documents (ARCH §3): `decimal(14,2)`; quantities/unit prices `decimal(14,4)`; `exchange_rate decimal(12,6)`; percents `decimal(7,4)`; `itbis_rate decimal(7,2)` (snapshot of the product's rate).
- Every document stores the **currency** (`currency_id`), the **exchange rate** used (`exchange_rate`, `1.000000` for DOP) and `total_base` = total in DOP (`Decimal::mul(total, exchange_rate, 2)`).
- Line computation and rounding: ARCH §4, implemented once in `App\Services\Documents\DocumentTotalsCalculator` (Task 2) and reused by proformas, invoices, credit notes and debit notes. Line snapshots store `itbis_rate`, `itbis_amount`, `additional_taxes` (jsonb list `[{code, name, amount}]`) and `additional_tax_total`.
- Status enums are string-backed PHP enums with `label()`; transitions happen only in Actions, which write `activity('<table>')->event('<transition>')` plus, when the transition has accounting meaning, `AccountingEventRecorder::record(...)`.
- **Immutability** (ARCH §6.5): invoices, credit notes, debit notes and receipts use `App\Models\Concerns\ImmutableWhenIssued`; after `issued_at`/`posted_at` is set, only the columns listed in the model's `$mutableWhenIssued` may change; anything else throws `App\Exceptions\ImmutableDocumentException` ("El documento ya fue emitido y no se puede modificar"). No route edits an issued document; corrections are credit/debit notes; cancellation is a transition.
- Sales scoping (spec §5): users without `sales.<resource>.view-all` see only documents whose `salesperson_id` is their own salesperson (`scopeVisibleTo(User)` on every document model, same shape as `Client::visibleTo`).
- Costs never reach Inertia props unless the user has `inventory.stock.view-costs` (`average_cost`, `unit_cost`, `total_cost` are stripped in controllers).

### GC-12 Document pages (frontend)
- Shared document components (Task 8): `StatusBadge`, `ClientPicker`, `DocumentHeader`; Task 2: `ProductPicker`, `DocumentLinesEditor`, `useTotalsPreview`; Task 7: `SendEmailDialog`.
- Document editors (proforma, invoice draft, credit/debit note draft, receipt, dispatch) use Inertia `useForm` (controlled), lines in a `useState` array, and **never compute money in the browser**: on every line change they debounce (400 ms) `POST /sales/totals/preview` (Task 2) and render the returned line amounts and totals. Product search uses `GET /catalog/products/search?q=&client_id=` (Task 2) through `@/components/erp/product-picker` (Dialog + search input + result list; selecting a row fills `product_id`, `description`, `unit_price` from the client's price list or the default list, `itbis_rate`).
- Every document has `index.tsx` (DataTable with status badges and filters), `form.tsx` (create/edit while draft) and `show.tsx` (read-only detail with action buttons, PDF link, "Enviar por correo" dialog, Historial tab via `RecordHistory`).
- Status badges: Spanish labels from the enum; colours: draft `secondary`, sent/pending `outline`, approved/issued/confirmed/posted `default`, paid `default`, rejected/expired/cancelled `destructive`.
- PDF links open `GET …/{id}/pdf` in a new tab (`target="_blank"`).

### GC-13 Audit and accounting events for documents
Every Action that changes a document status writes `activity('<table>')->causedBy($actor)->performedOn($doc)->event('<transition>')->withProperties([...])->log('<Spanish>')` and the task's tests assert it. Accounting-relevant transitions also write an `accounting_events` row (Task 1) — invoice issued/cancelled, credit note issued, debit note issued, receipt posted/cancelled, dispatch confirmed (cost of goods), return received, initial stock loaded — with the amounts Phase 5 needs (payload keys fixed per task). Tests assert the event rows and payload keys.

---

## Review Focus

1. **An invoice whose NCF sequence is exhausted or expired at issue time** must be refused with a clear message and must not consume a number → Task 3 tests `issuing with an exhausted sequence is refused`, `issuing with an expired sequence is refused`; Task 9 test `issue is refused and nothing is written when the ncf sequence is exhausted or stock is insufficient`.
2. **Two concurrent issues must never produce the same NCF or internal number** → Task 1 test `concurrent next numbers are unique` and Task 3 test `fiscal numbers are assigned inside a locking transaction and never repeat` (sequential calls inside `DB::transaction` + `lockForUpdate`, plus unique indexes on `invoices.ncf` and `invoices.number`).
3. **A dispatch for more than the invoiced quantity, or from a lot without enough stock**, must be refused → Task 13 tests `a dispatch cannot exceed the invoiced quantity` and `lot-tracked products get a fefo suggestion and confirm is refused when the lot has no stock`.
4. **A receipt applied for more than an invoice's balance, or a credit note for more than the remaining quantity/amount**, must be refused → Task 14 test `applications cannot exceed the invoice balance or the receipt amount and must match client and currency` and Task 11 tests `a return cannot exceed the dispatched quantity and re-enters stock at the dispatch cost` and `a credit note cannot exceed the invoice balance nor the invoiced unit price`.
5. **A consumo invoice ≥ RD$250,000 for a client without tax id** must be refused with the Norma 10-2018 message → Task 9 test `consumo clients get B02 and the threshold requires the client tax id`.

---

### Task 1: Phase 2 foundations — permissions, internal numbering, immutability trait, accounting events outbox, settings keys

**Files:**
- Create: `app/Enums/DocumentKind.php`, `app/Services/Documents/DocumentNumbering.php`, `app/Models/DocumentSequence.php`, `app/Models/Concerns/ImmutableWhenIssued.php`, `app/Exceptions/ImmutableDocumentException.php`, `app/Models/AccountingEvent.php`, `app/Services/Accounting/AccountingEventRecorder.php`, `database/migrations/2026_11_01_000001_create_document_sequences_table.php`, `database/migrations/2026_11_01_000002_create_accounting_events_table.php`, `database/seeders/DocumentSequenceSeeder.php`, `tests/Feature/Documents/DocumentNumberingTest.php`, `tests/Feature/Documents/ImmutableWhenIssuedTest.php`, `tests/Feature/Accounting/AccountingEventRecorderTest.php`
- Modify: `app/Support/PermissionCatalog.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/Admin/PermissionCatalogTest.php`, `resources/js/nav.ts`

**Interfaces:**
- Produces: enum `DocumentKind: string { Proforma='proforma'; Invoice='invoice'; CreditNote='credit_note'; DebitNote='debit_note'; Receipt='receipt'; Dispatch='dispatch' }` with `prefix(): string` (`COT`, `FAC`, `NC`, `ND`, `REC`, `CND`) and `label()` ("Proforma", "Factura", "Nota de crédito", "Nota de débito", "Recibo", "Conduce"); `DocumentNumbering::next(DocumentKind $kind): string`; trait `ImmutableWhenIssued` (models override `protected function mutableWhenIssued(): array` and optionally `protected function issuedAtColumn(): string`, default `'issued_at'`); `AccountingEvent` model (`type`, `document_type`, `document_id`, `occurred_on`, `currency_id`, `exchange_rate`, `payload`, `processed_at`); `AccountingEventRecorder::record(string $type, Model $document, array $payload, ?CarbonInterface $occurredOn = null): AccountingEvent`; new permissions (list below); nav groups "Ventas" and "Inventario" entries.

- [ ] **Step 1: Permission catalog** — in `PermissionCatalog::permissions()` append after the inventory block (same `$add`/`$crud` helpers; `$all = ['owner','management','accounting']`):
```php
// sales (phase 2)
$docs = function (string $prefix, string $plural, array $viewRoles, array $createRoles, array $extra) use ($add, $all): void {
    $add("$prefix.view", "Ver $plural", $viewRoles);
    $add("$prefix.view-all", "Ver $plural de todos los vendedores", $all);
    $add("$prefix.create", "Crear $plural", $createRoles);
    $add("$prefix.update", "Editar $plural en borrador", $createRoles);
    $add("$prefix.export", "Exportar $plural", array_values(array_intersect($viewRoles, self::EXPORTERS)));
    foreach ($extra as $action => [$label, $roles]) {
        $add("$prefix.$action", $label, $roles);
    }
};
$docs('sales.proformas', 'proformas', [...$all, 'sales'], [...$all, 'sales'], ['approve' => ['Aprobar proformas', [...$all, 'sales']], 'delete' => ['Eliminar proformas en borrador', [...$all, 'sales']], 'send' => ['Enviar proformas por correo', [...$all, 'sales']]]);
$docs('sales.invoices', 'facturas', [...$all, 'sales'], [...$all, 'sales'], ['issue' => ['Emitir facturas', [...$all, 'sales']], 'cancel' => ['Anular facturas', $all], 'send' => ['Enviar facturas por correo', [...$all, 'sales']], 'credit-override' => ['Autorizar exceso de límite de crédito', ['owner', 'management']], 'delete' => ['Eliminar facturas en borrador', [...$all, 'sales']]]);
$add('sales.credit-notes.view', 'Ver notas de crédito', [...$all, 'sales']);
$add('sales.credit-notes.create', 'Crear y emitir notas de crédito', $all);
$add('sales.credit-notes.export', 'Exportar notas de crédito', $all);
$add('sales.debit-notes.view', 'Ver notas de débito', [...$all, 'sales']);
$add('sales.debit-notes.create', 'Crear y emitir notas de débito', $all);
$add('sales.debit-notes.export', 'Exportar notas de débito', $all);
$add('sales.receipts.view', 'Ver recibos de ingreso', [...$all, 'sales']);
$add('sales.receipts.create', 'Registrar recibos de ingreso', $all);
$add('sales.receipts.cancel', 'Anular recibos de ingreso', ['owner', 'management', 'accounting']);
$add('sales.receipts.export', 'Exportar recibos', $all);
$add('sales.statements.view', 'Ver estados de cuenta y antigüedad', [...$all, 'sales']);
$add('sales.statements.send', 'Enviar estados de cuenta por correo', [...$all, 'sales']);
$add('sales.historical-invoices.import', 'Importar facturas históricas', ['owner', 'accounting']);

// fiscal (phase 2 subset)
$add('fiscal.sequences.view', 'Ver secuencias NCF', $all);
$add('fiscal.sequences.manage', 'Administrar secuencias NCF', ['owner', 'accounting']);
$add('fiscal.mode.manage', 'Cambiar modo fiscal (NCF/e-CF)', ['owner']);

// inventory (phase 2 subset)
$add('inventory.stock.view', 'Ver existencias', [...$all, 'sales', 'warehouse']);
$add('inventory.stock.view-costs', 'Ver costos de inventario', $all);
$add('inventory.movements.view', 'Ver movimientos de inventario (kardex)', [...$all, 'warehouse']);
$add('inventory.lots.view', 'Ver lotes y vencimientos', [...$all, 'sales', 'warehouse']);
$add('inventory.dispatches.view', 'Ver conduces', [...$all, 'sales', 'warehouse']);
$add('inventory.dispatches.create', 'Crear conduces', [...$all, 'warehouse']);
$add('inventory.dispatches.confirm', 'Confirmar conduces (salida de almacén)', [...$all, 'warehouse']);
$add('inventory.initial-stock.import', 'Cargar existencias iniciales', ['owner', 'accounting', 'warehouse']);
$add('inventory.alerts.receive', 'Recibir alertas de stock mínimo y vencimientos', ['owner', 'management', 'warehouse']);
$add('inventory.dispatches.export', 'Exportar conduces', array_values(array_intersect([...$all, 'sales', 'warehouse'], self::EXPORTERS)));
$add('inventory.stock.export', 'Exportar existencias', array_values(array_intersect([...$all, 'sales', 'warehouse'], self::EXPORTERS)));
$add('inventory.movements.export', 'Exportar movimientos de inventario', $all);
```
The catalog goes from 73 to **119** permissions (31 sales + 3 fiscal + 12 inventory). Add to `modules()`: `'sales' => 'Ventas'`, `'fiscal' => 'Fiscal'` (inventory exists). In `tests/Feature/Admin/PermissionCatalogTest.php` extend `seeder creates all roles and permissions idempotently` with:
```php
->and(Permission::count())->toBe(119)
->and(Role::findByName('warehouse')->hasPermissionTo('inventory.stock.export'))->toBeFalse()
->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.issue'))->toBeTrue()
->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.cancel'))->toBeFalse()
->and(Role::findByName('sales')->hasPermissionTo('sales.proformas.view-all'))->toBeFalse()
->and(Role::findByName('accounting')->hasPermissionTo('sales.receipts.cancel'))->toBeTrue()
->and(Role::findByName('warehouse')->hasPermissionTo('inventory.dispatches.confirm'))->toBeTrue()
->and(Role::findByName('warehouse')->hasPermissionTo('inventory.stock.view-costs'))->toBeFalse()
->and(Role::findByName('owner')->hasPermissionTo('fiscal.mode.manage'))->toBeTrue()
->and(Role::findByName('management')->hasPermissionTo('fiscal.mode.manage'))->toBeFalse()
```

- [ ] **Step 2: Nav** — in `resources/js/nav.ts` insert after the "Catálogo" group:
```ts
{ label: 'Ventas', items: [
    { title: 'Proformas', href: '/sales/proformas', icon: FileText, permission: 'sales.proformas.view' },
    { title: 'Facturas', href: '/sales/invoices', icon: Receipt, permission: 'sales.invoices.view' },
    { title: 'Notas de crédito', href: '/sales/credit-notes', icon: FileMinus, permission: 'sales.credit-notes.view' },
    { title: 'Notas de débito', href: '/sales/debit-notes', icon: FilePlus, permission: 'sales.debit-notes.view' },
    { title: 'Recibos de ingreso', href: '/sales/receipts', icon: HandCoins, permission: 'sales.receipts.view' },
    { title: 'Estados de cuenta', href: '/sales/statements', icon: Scale, permission: 'sales.statements.view' },
] },
```
and extend the "Inventario" group with `{ title: 'Existencias', href: '/inventory/stock', icon: Layers, permission: 'inventory.stock.view' }`, `{ title: 'Movimientos', href: '/inventory/movements', icon: ArrowLeftRight, permission: 'inventory.movements.view' }`, `{ title: 'Lotes', href: '/inventory/lots', icon: CalendarClock, permission: 'inventory.lots.view' }`, `{ title: 'Conduces', href: '/inventory/dispatches', icon: PackageCheck, permission: 'inventory.dispatches.view' }`; and the "Administración" group with `{ title: 'Secuencias NCF', href: '/admin/ncf-sequences', icon: Hash, permission: 'fiscal.sequences.view' }`, `{ title: 'Plantillas de correo', href: '/admin/email-templates', icon: Mail, permission: 'admin.settings.manage' }`. Add `ArrowLeftRight, CalendarClock, FileMinus, FilePlus, FileText, HandCoins, Hash, Layers, Mail, PackageCheck, Receipt, Scale` to the existing `lucide-react` import (`Boxes`, `Truck`, `Users` are already imported; keep the list alphabetical as the file does). The routes behind the new links are created by Tasks 3, 8, 9–16; until then a click lands on a 404, which is acceptable inside this phase branch.

- [ ] **Step 3: Failing tests**

`tests/Feature/Documents/DocumentNumberingTest.php`:
```php
<?php

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use App\Services\Documents\DocumentNumbering;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(fn () => (new DocumentSequenceSeeder)->run());

test('seeder creates one sequence per kind idempotently', function () {
    (new DocumentSequenceSeeder)->run();
    expect(DocumentSequence::count())->toBe(6)->and(DocumentSequence::where('kind', 'invoice')->first()->prefix)->toBe('FAC');
});

test('next returns zero padded consecutive numbers per kind', function () {
    $n = app(DocumentNumbering::class);
    expect($n->next(DocumentKind::Proforma))->toBe('COT-000001')
        ->and($n->next(DocumentKind::Proforma))->toBe('COT-000002')
        ->and($n->next(DocumentKind::Invoice))->toBe('FAC-000001')
        ->and($n->next(DocumentKind::Dispatch))->toBe('CND-000001');
});

test('concurrent next numbers are unique', function () {
    $n = app(DocumentNumbering::class);
    $numbers = [];
    foreach (range(1, 25) as $i) {
        $numbers[] = $n->next(DocumentKind::Receipt);
    }
    expect(array_unique($numbers))->toHaveCount(25)->and(end($numbers))->toBe('REC-000025');
});

test('kind labels and prefixes', function () {
    expect(DocumentKind::CreditNote->prefix())->toBe('NC')->and(DocumentKind::CreditNote->label())->toBe('Nota de crédito');
});
```
`tests/Feature/Documents/ImmutableWhenIssuedTest.php` (uses a throw-away model bound to a temporary table):
```php
<?php

use App\Exceptions\ImmutableDocumentException;
use App\Models\Concerns\ImmutableWhenIssued;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('immutable_probe', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->string('status')->default('draft');
        $t->timestamp('issued_at')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('immutable_probe'));

class ImmutableProbe extends Model
{
    use ImmutableWhenIssued;

    protected $table = 'immutable_probe';

    protected $guarded = [];

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return ['status'];
    }
}

test('draft documents can be edited and deleted', function () {
    $doc = ImmutableProbe::create(['title' => 'a']);
    $doc->update(['title' => 'b']);
    expect($doc->fresh()->title)->toBe('b');
    $doc->delete();
    expect(ImmutableProbe::count())->toBe(0);
});

test('issued documents reject changes outside the whitelist and deletion', function () {
    $doc = ImmutableProbe::create(['title' => 'a', 'issued_at' => now()]);
    expect(fn () => $doc->update(['title' => 'b']))->toThrow(ImmutableDocumentException::class, 'El documento ya fue emitido y no se puede modificar');
    expect(fn () => $doc->delete())->toThrow(ImmutableDocumentException::class);
    $doc->update(['status' => 'paid']);
    expect($doc->fresh()->status)->toBe('paid')->and($doc->fresh()->title)->toBe('a');
});

test('the transition that sets issued_at is allowed', function () {
    $doc = ImmutableProbe::create(['title' => 'a']);
    $doc->update(['issued_at' => now(), 'status' => 'issued']);
    expect($doc->fresh()->issued_at)->not->toBeNull();
});
```
`tests/Feature/Accounting/AccountingEventRecorderTest.php`:
```php
<?php

use App\Models\AccountingEvent;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;

beforeEach(fn () => (new CurrencySeeder)->run());

test('records an event with document reference, currency, rate and payload', function () {
    $user = User::factory()->create();
    $event = app(AccountingEventRecorder::class)->record('probe_event', $user, ['amount' => '100.00', 'currency' => 'DOP', 'exchange_rate' => '1.000000'], Carbon::parse('2026-11-02'));

    expect($event->type)->toBe('probe_event')
        ->and($event->document_type)->toBe(User::class)
        ->and($event->document_id)->toBe($user->id)
        ->and($event->occurred_on->format('Y-m-d'))->toBe('2026-11-02')
        ->and($event->currency_id)->toBe(Currency::base()->id)
        ->and($event->exchange_rate)->toBe('1.000000')
        ->and($event->payload['amount'])->toBe('100.00')
        ->and($event->processed_at)->toBeNull()
        ->and(AccountingEvent::count())->toBe(1);
});

test('payload must carry currency and exchange_rate', function () {
    expect(fn () => app(AccountingEventRecorder::class)->record('probe_event', User::factory()->create(), ['amount' => '1']))
        ->toThrow(InvalidArgumentException::class, 'El evento contable requiere currency y exchange_rate');
});
```

- [ ] **Step 4: Migrations**

`document_sequences`: `id()`, `string('kind', 20)->unique()`, `string('prefix', 5)`, `unsignedInteger('next_number')->default(1)`, `timestamps()`.
`accounting_events`: `id()`, `string('type', 40)->index()`, `string('document_type', 120)`, `unsignedBigInteger('document_id')`, `index(['document_type', 'document_id'])`, `date('occurred_on')->index()`, `foreignId('currency_id')->constrained('currencies')->restrictOnDelete()`, `decimal('exchange_rate', 12, 6)`, `jsonb('payload')`, `timestamp('processed_at')->nullable()->index()`, `timestamps()`.

- [ ] **Step 5: Enum, models, services, seeder**

`app/Enums/DocumentKind.php`:
```php
<?php

namespace App\Enums;

enum DocumentKind: string
{
    case Proforma = 'proforma';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Receipt = 'receipt';
    case Dispatch = 'dispatch';

    public function prefix(): string
    {
        return match ($this) {
            self::Proforma => 'COT', self::Invoice => 'FAC', self::CreditNote => 'NC',
            self::DebitNote => 'ND', self::Receipt => 'REC', self::Dispatch => 'CND',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Proforma => 'Proforma', self::Invoice => 'Factura', self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito', self::Receipt => 'Recibo', self::Dispatch => 'Conduce',
        };
    }
}
```
`app/Models/DocumentSequence.php`: `use LogsModelActivity;` `$fillable = ['kind', 'prefix', 'next_number']`; cast `next_number` integer.
`app/Services/Documents/DocumentNumbering.php`:
```php
<?php

namespace App\Services\Documents;

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumbering
{
    public function next(DocumentKind $kind): string
    {
        return DB::transaction(function () use ($kind): string {
            $sequence = DocumentSequence::query()->where('kind', $kind->value)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return sprintf('%s-%06d', $sequence->prefix, $number);
        });
    }
}
```
`database/seeders/DocumentSequenceSeeder.php` `run()`: `foreach (DocumentKind::cases() as $kind) { DocumentSequence::query()->firstOrCreate(['kind' => $kind->value], ['prefix' => $kind->prefix(), 'next_number' => 1]); }` — add to `DatabaseSeeder` after `WarehouseSeeder`.
`app/Exceptions/ImmutableDocumentException.php`: `class ImmutableDocumentException extends \RuntimeException { public function __construct() { parent::__construct('El documento ya fue emitido y no se puede modificar'); } }`.
`app/Models/Concerns/ImmutableWhenIssued.php`:
```php
<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableDocumentException;

/**
 * Models override `mutableWhenIssued()` (columns that may still change after issue) and, when the
 * issue timestamp is not `issued_at`, `issuedAtColumn()`. Methods (not properties) because PHP forbids
 * redeclaring a trait property with a different default value.
 */
trait ImmutableWhenIssued
{
    public static function bootImmutableWhenIssued(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal($model->issuedAtColumn()) === null) {
                return; // the transition that sets issued_at is allowed
            }
            $dirty = array_keys($model->getDirty());
            $allowed = [...$model->mutableWhenIssued(), 'updated_at'];
            if (array_diff($dirty, $allowed) !== []) {
                throw new ImmutableDocumentException;
            }
        });

        static::deleting(function (self $model): void {
            if ($model->getOriginal($model->issuedAtColumn()) !== null) {
                throw new ImmutableDocumentException;
            }
        });
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return [];
    }

    protected function issuedAtColumn(): string
    {
        return 'issued_at';
    }
}
```
`app/Models/AccountingEvent.php`: `use LogsModelActivity;` fillable all columns; casts `occurred_on` → `date:Y-m-d`, `exchange_rate` → `decimal:6`, `payload` → array, `processed_at` → datetime; `document(): MorphTo` (`morphTo('document', 'document_type', 'document_id')`); `currency(): BelongsTo`.
`app/Services/Accounting/AccountingEventRecorder.php`:
```php
<?php

namespace App\Services\Accounting;

use App\Models\AccountingEvent;
use App\Models\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AccountingEventRecorder
{
    /** @param array<string, mixed> $payload must include 'currency' (code) and 'exchange_rate' (6-dp string) */
    public function record(string $type, Model $document, array $payload, ?CarbonInterface $occurredOn = null): AccountingEvent
    {
        if (! isset($payload['currency'], $payload['exchange_rate'])) {
            throw new InvalidArgumentException('El evento contable requiere currency y exchange_rate');
        }
        $currency = Currency::query()->where('code', $payload['currency'])->firstOrFail();

        return AccountingEvent::create([
            'type' => $type,
            'document_type' => $document::class,
            'document_id' => $document->getKey(),
            'occurred_on' => ($occurredOn ?? now())->toDateString(),
            'currency_id' => $currency->id,
            'exchange_rate' => $payload['exchange_rate'],
            'payload' => $payload,
        ]);
    }
}
```

- [ ] **Step 6: Migrate, seed, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan db:seed --no-interaction 2>&1 | tail -1 && docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT (SELECT count(*) FROM permissions)||' permissions, '||(SELECT count(*) FROM document_sequences)||' sequences'"` → `119 permissions, 6 sequences`. Then `npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (343 tests).

- [ ] **Step 7: Commit** — `feat(documents): add phase 2 permissions, internal numbering, immutability trait and accounting events`

---

### Task 2: `DocumentTotalsCalculator`, totals preview endpoint, product search endpoint, product picker

**Files:**
- Create: `app/Services/Documents/DocumentTotalsCalculator.php`, `app/Support/Documents/LineInput.php`, `app/Support/Documents/ComputedLine.php`, `app/Support/Documents/ComputedTotals.php`, `app/Http/Controllers/Sales/TotalsPreviewController.php`, `app/Http/Controllers/Catalog/ProductSearchController.php`, `app/Http/Requests/Sales/TotalsPreviewRequest.php`, `routes/sales.php`, `resources/js/components/erp/product-picker.tsx`, `resources/js/components/erp/document-lines-editor.tsx`, `resources/js/types/sales.ts`, `resources/js/hooks/use-totals-preview.ts`, `tests/Feature/Documents/DocumentTotalsCalculatorTest.php`, `tests/Feature/Sales/TotalsPreviewTest.php`, `tests/Feature/Catalog/ProductSearchTest.php`
- Modify: `routes/web.php`, `routes/catalog.php`

**Interfaces:**
- Consumes: `Decimal`, `AdditionalTaxCalculator`, `Product`, `PriceList`, `PriceListItem`, `Client`.
- Produces: `LineInput` (readonly: `int $productId, string $quantity, string $unitPrice, string $discountPercent = '0', ?string $description = null, ?string $itbisRate = null` — when `$itbisRate` is given it overrides the product's current rate; credit/debit notes pass the invoice line's snapshot); `ComputedLine` (readonly: `int $productId, string $description, string $quantity, string $unitPrice, string $discountPercent, string $lineSubtotal, string $discountAmount, string $taxableBase, string $itbisRate, string $itbisAmount, array $additionalTaxes /* list<array{code,name,amount}> */, string $additionalTaxTotal, string $lineTotal`); `ComputedTotals` (readonly: `array $lines /* list<ComputedLine> */, string $subtotal, string $discountTotal, string $taxableTotal, string $itbisTotal, string $additionalTaxTotal, string $total`); `DocumentTotalsCalculator::compute(array $lines /* list<LineInput> */): ComputedTotals`; `DocumentTotalsCalculator::priceFor(Product $product, ?Client $client): string` (client's list → default list → `'0.0000'`); routes `sales.totals.preview` (POST `/sales/totals/preview`, auth) and `catalog.products.search` (GET `/catalog/products/search`, permission `catalog.products.view`); React `ProductPicker` (`onPick(product: PickedProduct)`), `DocumentLinesEditor` (`lines`, `onChange`, `computed`, `readOnly?`), hook `useTotalsPreview(lines, currencyCode) → { computed, loading }`; TS types `DocumentLineInput`, `ComputedLine`, `ComputedTotals`, `PickedProduct`.

- [ ] **Step 1: Failing tests**

`tests/Feature/Documents/DocumentTotalsCalculatorTest.php`:
```php
<?php

use App\Models\AdditionalTax;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Support\Documents\LineInput;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('line math follows ARCH §4 order with HALF_UP rounding', function () {
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $totals = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '3', '100.3333', '10', null)]);
    $line = $totals->lines[0];

    expect($line->lineSubtotal)->toBe('301.00')      // 3 × 100.3333 = 300.9999 → 301.00
        ->and($line->discountAmount)->toBe('30.10')  // 301.00 × 10 % = 30.10
        ->and($line->taxableBase)->toBe('270.90')
        ->and($line->itbisAmount)->toBe('48.76')     // 270.90 × 18 % = 48.762 → 48.76
        ->and($line->lineTotal)->toBe('319.66')
        ->and($totals->subtotal)->toBe('301.00')->and($totals->discountTotal)->toBe('30.10')
        ->and($totals->taxableTotal)->toBe('270.90')->and($totals->itbisTotal)->toBe('48.76')->and($totals->total)->toBe('319.66');
});

test('additional taxes feed the itbis base only when flagged', function () {
    $isc = AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10', 'included_in_itbis_base' => true]);
    $other = AdditionalTax::factory()->create(['code' => 'OTRO', 'calculation_type' => 'fixed_per_unit', 'rate' => null, 'amount' => '5', 'included_in_itbis_base' => false]);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $product->additionalTaxes()->sync([$isc->id, $other->id]);

    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '2', '100', '0', null)])->lines[0];

    expect($line->taxableBase)->toBe('200.00')
        ->and($line->additionalTaxes)->toBe([['code' => 'ISC-ADV', 'name' => $isc->name, 'amount' => '20.00'], ['code' => 'OTRO', 'name' => $other->name, 'amount' => '10.00']])
        ->and($line->additionalTaxTotal)->toBe('30.00')
        ->and($line->itbisAmount)->toBe('39.60')     // (200 + 20) × 18 %
        ->and($line->lineTotal)->toBe('269.60');
});

test('exempt products carry no itbis and document totals are sums of rounded lines', function () {
    $a = Product::factory()->create(['itbis_rate' => '0.00']);
    $b = Product::factory()->create(['itbis_rate' => '16.00']);
    $totals = app(DocumentTotalsCalculator::class)->compute([new LineInput($a->id, '1', '0.005', '0', null), new LineInput($b->id, '1', '0.005', '0', null)]);
    expect($totals->lines[0]->itbisAmount)->toBe('0.00')->and($totals->lines[0]->lineSubtotal)->toBe('0.01')
        ->and($totals->subtotal)->toBe('0.02')->and($totals->itbisTotal)->toBe('0.00')->and($totals->total)->toBe('0.02');
});

test('priceFor uses the client list then the default list then zero', function () {
    $product = Product::factory()->create();
    $default = PriceList::factory()->create(['is_default' => true]);
    $special = PriceList::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $default->id, 'product_id' => $product->id, 'price' => '50.0000']);
    PriceListItem::factory()->create(['price_list_id' => $special->id, 'product_id' => $product->id, 'price' => '45.0000']);
    $client = Client::factory()->create(['price_list_id' => $special->id]);
    $calc = app(DocumentTotalsCalculator::class);

    expect($calc->priceFor($product, $client))->toBe('45.0000')
        ->and($calc->priceFor($product, Client::factory()->create(['price_list_id' => null])))->toBe('50.0000')
        ->and($calc->priceFor(Product::factory()->create(), null))->toBe('0.0000');
});

test('description defaults to the product name and quantities keep four decimals', function () {
    $product = Product::factory()->create(['name' => 'Ron 750']);
    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '1.5', '10', '0', null)])->lines[0];
    expect($line->description)->toBe('Ron 750')->and($line->quantity)->toBe('1.5000')->and($line->unitPrice)->toBe('10.0000');
});

test('an explicit itbis rate overrides the product rate', function () {
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '1', '100', '0', null, '16.00')])->lines[0];
    expect($line->itbisRate)->toBe('16.00')->and($line->itbisAmount)->toBe('16.00')->and($line->lineTotal)->toBe('116.00');
});
```
`tests/Feature/Sales/TotalsPreviewTest.php`:
```php
<?php

use App\Models\Product;

test('preview endpoint returns computed lines and totals and validates input', function () {
    actingAsRole('sales');
    $product = Product::factory()->create(['itbis_rate' => '18.00']);

    $this->postJson(route('sales.totals.preview'), ['lines' => [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]])
        ->assertOk()->assertJsonPath('totals.total', '236.00')->assertJsonPath('lines.0.itbis_amount', '36.00');

    $this->postJson(route('sales.totals.preview'), ['lines' => [['product_id' => 999999, 'quantity' => '-1', 'unit_price' => 'x']]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_id', 'lines.0.quantity', 'lines.0.unit_price']);
});
```
`tests/Feature/Catalog/ProductSearchTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;

test('search returns active products by sku or name with the client price', function () {
    actingAsRole('sales');
    $list = PriceList::factory()->create(['is_default' => true]);
    $p = Product::factory()->create(['sku' => 'RON-750', 'name' => 'Ron añejo']);
    Product::factory()->create(['sku' => 'OFF-1', 'name' => 'Ron viejo', 'is_active' => false]);
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p->id, 'price' => '150.0000']);
    $client = Client::factory()->create();

    $this->getJson(route('catalog.products.search', ['q' => 'ron', 'client_id' => $client->id]))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'RON-750')->assertJsonPath('data.0.unit_price', '150.0000')->assertJsonPath('data.0.itbis_rate', '18.00');

    $this->getJson(route('catalog.products.search', ['q' => 'zzz']))->assertOk()->assertJsonCount(0, 'data');
});

test('search requires the products view permission', function () {
    $this->actingAs(\App\Models\User::factory()->create());
    $this->getJson(route('catalog.products.search', ['q' => 'a']))->assertForbidden();
});
```

- [ ] **Step 2: Value objects and calculator**

`app/Support/Documents/LineInput.php`:
```php
<?php

namespace App\Support\Documents;

final readonly class LineInput
{
    public function __construct(
        public int $productId,
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent = '0',
        public ?string $description = null,
        public ?string $itbisRate = null,
    ) {}

    /** @param array{product_id: int|string, quantity: string, unit_price: string, discount_percent?: string|null, description?: string|null, itbis_rate?: string|null} $row */
    public static function fromArray(array $row): self
    {
        $discount = $row['discount_percent'] ?? '0';

        return new self((int) $row['product_id'], (string) $row['quantity'], (string) $row['unit_price'], $discount === null || $discount === '' ? '0' : (string) $discount, $row['description'] ?? null, isset($row['itbis_rate']) && $row['itbis_rate'] !== '' ? (string) $row['itbis_rate'] : null);
    }
}
```
`app/Support/Documents/ComputedLine.php`: `final readonly class ComputedLine` with the public constructor properties listed in Interfaces (in that order) and `public function toArray(): array` returning snake_case keys (`product_id, description, quantity, unit_price, discount_percent, line_subtotal, discount_amount, taxable_base, itbis_rate, itbis_amount, additional_taxes, additional_tax_total, line_total`).
`app/Support/Documents/ComputedTotals.php`: `final readonly class ComputedTotals` with `array $lines, string $subtotal, string $discountTotal, string $taxableTotal, string $itbisTotal, string $additionalTaxTotal, string $total` and `toArray(): array` → `['lines' => [...each->toArray()], 'totals' => ['subtotal' => …, 'discount_total' => …, 'taxable_total' => …, 'itbis_total' => …, 'additional_tax_total' => …, 'total' => …]]`.
`app/Services/Documents/DocumentTotalsCalculator.php`:
```php
<?php

namespace App\Services\Documents;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\Catalog\AdditionalTaxCalculator;
use App\Support\Documents\ComputedLine;
use App\Support\Documents\ComputedTotals;
use App\Support\Documents\LineInput;
use App\Support\Money\Decimal;

class DocumentTotalsCalculator
{
    public function __construct(private readonly AdditionalTaxCalculator $taxCalculator) {}

    /** @param list<LineInput> $lines */
    public function compute(array $lines): ComputedTotals
    {
        $products = Product::query()->with('additionalTaxes')->whereIn('id', array_map(fn (LineInput $l) => $l->productId, $lines))->get()->keyBy('id');
        $computed = [];
        foreach ($lines as $input) {
            /** @var Product $product */
            $product = $products[$input->productId];
            $quantity = Decimal::qty($input->quantity);
            $unitPrice = Decimal::qty($input->unitPrice);
            $discountPercent = Decimal::scale($input->discountPercent === '' ? '0' : $input->discountPercent, 4);
            $lineSubtotal = Decimal::mul($quantity, $unitPrice, Decimal::MONEY);
            $discountAmount = Decimal::percent($lineSubtotal, $discountPercent);
            $taxableBase = Decimal::money(Decimal::of($lineSubtotal)->minus(Decimal::of($discountAmount)));

            $additional = [];
            $itbisBase = Decimal::of($taxableBase);
            $additionalTotal = Decimal::of('0');
            foreach ($product->additionalTaxes->where('is_active', true) as $tax) {
                $amount = $this->taxCalculator->forLine($tax, $quantity, $taxableBase, $product->volume_liters, $product->alcohol_degree);
                $additional[] = ['code' => $tax->code, 'name' => $tax->name, 'amount' => $amount];
                $additionalTotal = $additionalTotal->plus(Decimal::of($amount));
                if ($tax->included_in_itbis_base) {
                    $itbisBase = $itbisBase->plus(Decimal::of($amount));
                }
            }
            $itbisRate = Decimal::scale($input->itbisRate ?? (string) $product->itbis_rate, 2);
            $itbisAmount = Decimal::percent(Decimal::money($itbisBase), $itbisRate);
            $lineTotal = Decimal::money(Decimal::of($taxableBase)->plus($additionalTotal)->plus(Decimal::of($itbisAmount)));

            $computed[] = new ComputedLine(
                $product->id, $input->description !== null && $input->description !== '' ? $input->description : $product->name,
                $quantity, $unitPrice, $discountPercent, $lineSubtotal, $discountAmount, $taxableBase, $itbisRate, $itbisAmount,
                $additional, Decimal::money($additionalTotal), $lineTotal,
            );
        }

        return new ComputedTotals(
            $computed,
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->lineSubtotal, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->discountAmount, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->taxableBase, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->itbisAmount, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->additionalTaxTotal, $computed)),
            Decimal::sum(array_map(fn (ComputedLine $l) => $l->lineTotal, $computed)),
        );
    }

    public function priceFor(Product $product, ?Client $client): string
    {
        $listIds = array_values(array_filter([$client?->price_list_id, PriceList::default()?->id]));
        foreach ($listIds as $listId) {
            $price = PriceListItem::query()->where('price_list_id', $listId)->where('product_id', $product->id)->value('price');
            if ($price !== null) {
                return Decimal::qty((string) $price);
            }
        }

        return '0.0000';
    }
}
```

- [ ] **Step 3: Endpoints** — `routes/sales.php` (require from `routes/web.php`):
```php
<?php

use App\Http\Controllers\Sales\TotalsPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('sales')->name('sales.')->group(function () {
    Route::post('totals/preview', TotalsPreviewController::class)->name('totals.preview');
});
```
`TotalsPreviewRequest` rules: `'lines' => ['present', 'array', 'max:500'], 'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'], 'lines.*.description' => ['nullable', 'string', 'max:200'], 'lines.*.itbis_rate' => ['nullable', Rule::in(['0.00', '16.00', '18.00'])]`. `TotalsPreviewController::__invoke(TotalsPreviewRequest $request, DocumentTotalsCalculator $calc): JsonResponse` → `response()->json($calc->compute(array_map(LineInput::fromArray(...), $request->validated('lines')))->toArray())`.
`ProductSearchController::__invoke(Request $request, DocumentTotalsCalculator $calc): JsonResponse` (`#[Middleware('permission:catalog.products.view')]`; route `Route::get('products/search', ProductSearchController::class)->name('products.search')` placed **before** `Route::resource('products', …)` in `routes/catalog.php`): `$q = trim((string) $request->query('q', ''))`; `$clientId = $request->integer('client_id'); $client = $clientId > 0 ? Client::query()->whereKey($clientId)->first() : null`; query `Product::query()->active()->where(fn ($w) => $w->where('sku', 'ILIKE', "%{$q}%")->orWhere('name', 'ILIKE', "%{$q}%")->orWhere('barcode', 'ILIKE', "%{$q}%"))->orderBy('name')->limit(20)->get()` mapped to `['id', 'sku', 'name', 'itbis_rate' => (string) …, 'tracks_lots', 'unit_price' => $calc->priceFor($p, $client)]`, returned as `['data' => [...]]`.

- [ ] **Step 4: Frontend building blocks**

`resources/js/types/sales.ts`:
```ts
export type PickedProduct = { id: number; sku: string; name: string; itbis_rate: string; tracks_lots: boolean; unit_price: string };
export type DocumentLineInput = { key: string; product_id: number | null; sku?: string; description: string; quantity: string; unit_price: string; discount_percent: string };
export type ComputedLine = { product_id: number; description: string; quantity: string; unit_price: string; discount_percent: string; line_subtotal: string; discount_amount: string; taxable_base: string; itbis_rate: string; itbis_amount: string; additional_taxes: { code: string; name: string; amount: string }[]; additional_tax_total: string; line_total: string };
export type ComputedTotals = { lines: ComputedLine[]; totals: { subtotal: string; discount_total: string; taxable_total: string; itbis_total: string; additional_tax_total: string; total: string } };
```
`resources/js/hooks/use-totals-preview.ts`: `export function useTotalsPreview(lines: DocumentLineInput[]): { computed: ComputedTotals | null; loading: boolean }` — `useEffect` debounced 400 ms; skips when no line has a `product_id`; POSTs `{ lines: lines.filter(l => l.product_id).map(...) }` to `preview.url()` (`@/routes/sales/totals`) with `fetch` (`headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') }`, `credentials: 'same-origin'`); stores the JSON in state; on 422 keeps the previous value.
`resources/js/components/erp/product-picker.tsx`: `export function ProductPicker({ clientId, onPick, trigger }: { clientId: number | null; onPick: (p: PickedProduct) => void; trigger?: ReactNode })` — shadcn `Dialog` titled "Buscar producto", `Input` placeholder "SKU, nombre o código de barras" (debounced 300 ms `fetch` to `search.url({ query: { q, client_id: clientId ?? undefined } })` from `@/routes/catalog/products`), results `Table` (SKU, Nombre, ITBIS, Precio via `formatNumber(unit_price, 4)`), clicking a row calls `onPick` and closes; empty text "Sin resultados".
`resources/js/components/erp/document-lines-editor.tsx`: `export function DocumentLinesEditor({ lines, onChange, computed, clientId, readOnly = false, currency }: { lines: DocumentLineInput[]; onChange: (lines: DocumentLineInput[]) => void; computed: ComputedTotals | null; clientId: number | null; readOnly?: boolean; currency: 'DOP' | 'USD' })` — `Table` with columns Producto (sku + description editable `Input`), Cantidad (`Input inputMode="decimal"`), Precio, Desc. %, ITBIS (from `computed.lines[i].itbis_amount`), Imp. adic. (`additional_tax_total`), Total (`line_total`), Quitar button; footer button "Añadir producto" opening `ProductPicker` (adds `{ key: crypto.randomUUID(), product_id, sku, description: name, quantity: '1', unit_price, discount_percent: '0' }`); totals block (Subtotal, Descuento, Base imponible, ITBIS, Impuestos adicionales, **Total**) formatted with `formatMoney(v, currency)`; when `readOnly` all inputs are plain text.

- [ ] **Step 5: Gate** — `php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (352 tests).

- [ ] **Step 6: Commit** — `feat(documents): add totals calculator, preview endpoint, product search and lines editor`

---

### Task 3: NCF sequences, `FiscalNumberingService`, NCF type resolution, sequence alerts and admin screen

**Files:**
- Create: `app/Enums/NcfType.php`, `app/Models/NcfSequence.php`, `database/factories/NcfSequenceFactory.php`, `database/migrations/2026_11_01_000003_create_ncf_sequences_table.php`, `app/Support/Fiscal/FiscalNumber.php`, `app/Exceptions/FiscalNumberingException.php`, `app/Services/Fiscal/FiscalNumberingService.php`, `app/Services/Fiscal/NcfTypeResolver.php`, `app/Services/Fiscal/SequenceAlerts.php`, `app/Notifications/NcfSequenceAlert.php`, `app/Jobs/CheckNcfSequences.php`, `app/Http/Controllers/Admin/NcfSequenceController.php`, `app/Http/Requests/Admin/StoreNcfSequenceRequest.php`, `app/Http/Requests/Admin/UpdateNcfSequenceRequest.php`, `resources/js/pages/admin/ncf-sequences/{index,form}.tsx`, `resources/js/types/fiscal.ts`, `tests/Feature/Fiscal/FiscalNumberingTest.php`, `tests/Feature/Fiscal/NcfTypeResolverTest.php`, `tests/Feature/Fiscal/NcfSequenceAdminTest.php`
- Modify: `routes/admin.php`, `routes/console.php`, `tests/Support/helpers.php`

**Interfaces:**
- Consumes: `Settings` (keys `fiscal.mode` default `'ncf'`; `fiscal.consumo_id_threshold` default `'250000.00'`), `Client`, `TaxpayerType`.
- Produces: enum `NcfType: string { B01='B01'; B02='B02'; B03='B03'; B04='B04'; B11='B11'; B13='B13'; B14='B14'; B15='B15'; B16='B16'; B17='B17' }` with `label()` ("Factura de Crédito Fiscal", "Factura de Consumo", "Nota de Débito", "Nota de Crédito", "Comprobante de Compras", "Gastos Menores", "Regímenes Especiales", "Gubernamental", "Exportaciones", "Pagos al Exterior"), `expires(): bool` (false for `B02`, `B04`), `isSalesType(): bool` (B01, B02, B03, B04, B14, B15, B16); `NcfSequence` (`ncf_type`, `range_from`, `range_to`, `next_number`, `expires_on`, `authorization_number`, `is_active`, `exhausted_at`, `notes`; accessors `remaining`, `used`); `FiscalNumber` readonly (`string $ncf`, `NcfType $type`, `?CarbonImmutable $expiresOn`, `int $sequenceId`); `FiscalNumberingService::assign(NcfType $type, CarbonInterface $issueDate): FiscalNumber` (locks the active sequence; throws `FiscalNumberingException` with messages below); `FiscalNumberingService::mode(): string` (`'ncf'`); `NcfTypeResolver::forSale(Client $client): NcfType`, `::forCreditNote(): NcfType` (B04), `::forDebitNote(): NcfType` (B03); `SequenceAlerts::current(): list<array{ncf_type: string, label: string, remaining: int, expires_on: ?string, reason: 'low'|'expiring'|'expired'|'missing'}>`; job `CheckNcfSequences` (daily 07:30) notifying users with `fiscal.sequences.manage`; routes `admin.ncf-sequences.{index,create,store,edit,update}` (view: `fiscal.sequences.view`; others `fiscal.sequences.manage`); helper `validNcfSequencePayload`.

Error messages (`FiscalNumberingException`): `No hay una secuencia activa de {label} ({type}).`, `La secuencia de {type} está agotada; registra una nueva secuencia autorizada por la DGII.`, `La secuencia de {type} venció el {dd/mm/YYYY}; registra una nueva secuencia autorizada por la DGII.`

- [ ] **Step 1: Helper** — append to `tests/Support/helpers.php`:
```php
function validNcfSequencePayload(array $over = []): array
{
    return array_merge(['ncf_type' => 'B01', 'range_from' => 1, 'range_to' => 100, 'expires_on' => now()->addYear()->endOfYear()->toDateString(), 'authorization_number' => '7001234567', 'notes' => '', 'is_active' => 'on'], $over);
}

function activeSequence(string $type = 'B01', array $over = []): \App\Models\NcfSequence
{
    return \App\Models\NcfSequence::factory()->create(array_merge(['ncf_type' => $type, 'range_from' => 1, 'range_to' => 100, 'next_number' => 1, 'expires_on' => in_array($type, ['B02', 'B04'], true) ? null : now()->addYear()->toDateString(), 'is_active' => true], $over));
}
```

- [ ] **Step 2: Failing tests**

`tests/Feature/Fiscal/FiscalNumberingTest.php`:
```php
<?php

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\NcfSequence;
use App\Services\Fiscal\FiscalNumberingService;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

test('assigns consecutive 11-character ncfs and tracks exhaustion', function () {
    $seq = activeSequence('B01', ['range_from' => 5, 'range_to' => 6, 'next_number' => 5]);
    $s = app(FiscalNumberingService::class);

    $a = $s->assign(NcfType::B01, Carbon::today());
    $b = $s->assign(NcfType::B01, Carbon::today());

    expect($a->ncf)->toBe('B0100000005')->and(strlen($a->ncf))->toBe(11)->and($a->expiresOn?->toDateString())->toBe($seq->expires_on->toDateString())
        ->and($b->ncf)->toBe('B0100000006')
        ->and($seq->fresh()->next_number)->toBe(7)->and($seq->fresh()->exhausted_at)->not->toBeNull()->and($seq->fresh()->remaining)->toBe(0)
        ->and(Activity::inLog('ncf_sequences')->where('event', 'exhausted')->where('subject_id', $seq->id)->exists())->toBeTrue();
});

test('issuing with an exhausted sequence is refused', function () {
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::today()))
        ->toThrow(FiscalNumberingException::class, 'La secuencia de B01 está agotada; registra una nueva secuencia autorizada por la DGII.');
});

test('issuing with an expired sequence is refused', function () {
    activeSequence('B01', ['expires_on' => '2025-12-31']);
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::parse('2026-01-01')))
        ->toThrow(FiscalNumberingException::class, 'La secuencia de B01 venció el 31/12/2025; registra una nueva secuencia autorizada por la DGII.');
});

test('missing sequence is refused with the type label', function () {
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B14, Carbon::today()))
        ->toThrow(FiscalNumberingException::class, 'No hay una secuencia activa de Regímenes Especiales (B14).');
});

test('consumo and credit note sequences never expire', function () {
    activeSequence('B02', ['expires_on' => null]);
    $n = app(FiscalNumberingService::class)->assign(NcfType::B02, Carbon::parse('2030-01-01'));
    expect($n->ncf)->toBe('B0200000001')->and($n->expiresOn)->toBeNull()->and(NcfType::B02->expires())->toBeFalse()->and(NcfType::B04->expires())->toBeFalse()->and(NcfType::B01->expires())->toBeTrue();
});

test('fiscal numbers are assigned inside a locking transaction and never repeat', function () {
    activeSequence('B02', ['range_to' => 50]);
    $s = app(FiscalNumberingService::class);
    $all = [];
    foreach (range(1, 30) as $i) {
        $all[] = $s->assign(NcfType::B02, Carbon::today())->ncf;
    }
    expect(array_unique($all))->toHaveCount(30);
    expect(fn () => NcfSequence::factory()->create(['ncf_type' => 'B02', 'range_from' => 1, 'range_to' => 10]))->toThrow(\Illuminate\Database\QueryException::class); // unique (ncf_type, range_from)
});

test('when two active sequences exist the one with the earliest range is used first', function () {
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    activeSequence('B01', ['range_from' => 200, 'range_to' => 300]);
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::today())->ncf)->toBe('B0100000200');
});
```
`tests/Feature/Fiscal/NcfTypeResolverTest.php`:
```php
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
```
`tests/Feature/Fiscal/NcfSequenceAdminTest.php`:
```php
<?php

use App\Jobs\CheckNcfSequences;
use App\Models\NcfSequence;
use App\Notifications\NcfSequenceAlert;
use App\Services\Fiscal\SequenceAlerts;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

test('index requires view permission and accounting can manage', function () {
    actingAsRole('sales');
    $this->get(route('admin.ncf-sequences.index'))->assertForbidden();
    actingAsRole('management');
    $this->get(route('admin.ncf-sequences.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/ncf-sequences/index')->where('canManage', false));
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload())->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload())->assertRedirect(route('admin.ncf-sequences.index'))->assertSessionHas('success', 'Secuencia registrada');
    expect(NcfSequence::count())->toBe(1)->and(NcfSequence::first()->next_number)->toBe(1)
        ->and(Activity::inLog('ncf_sequences')->where('event', 'created')->exists())->toBeTrue();
});

test('validation: range, expiry required for expiring types, overlap and consumo without expiry', function () {
    actingAsRole('owner');
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 10, 'range_to' => 5]))->assertSessionHasErrors(['range_to']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['expires_on' => '']))->assertSessionHasErrors(['expires_on']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['ncf_type' => 'B02', 'expires_on' => '']))->assertRedirect(route('admin.ncf-sequences.index'));
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 50, 'range_to' => 150]))->assertRedirect();
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 100, 'range_to' => 120]))->assertSessionHasErrors(['range_from' => 'El rango se solapa con otra secuencia del mismo tipo.']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 99999999, 'range_to' => 100000000]))->assertSessionHasErrors(['range_to']);
});

test('a used sequence cannot shrink below next_number nor change type', function () {
    actingAsRole('owner');
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 40]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_to' => 30]))->assertSessionHasErrors(['range_to' => 'La secuencia ya emitió hasta el número 39.']);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['ncf_type' => 'B02', 'range_to' => 100]))->assertSessionHasErrors(['ncf_type']);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_to' => 200]))->assertRedirect(route('admin.ncf-sequences.index'));
    expect($seq->fresh()->range_to)->toBe(200);
});

test('alerts report low remaining, expiring, expired and missing sales sequences and the job notifies', function () {
    Notification::fake();
    $owner = actingAsRole('owner');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 95]);                 // 6 left = 6 % → low
    activeSequence('B02', ['range_from' => 1, 'range_to' => 1000]);                                        // fine
    activeSequence('B04', ['range_from' => 1, 'range_to' => 10, 'next_number' => 11, 'exhausted_at' => now()]); // exhausted → low (0)
    activeSequence('B14', ['expires_on' => now()->addDays(10)->toDateString()]);                            // expiring
    activeSequence('B15', ['expires_on' => now()->subDay()->toDateString()]);                               // expired

    $alerts = collect(app(SequenceAlerts::class)->current());
    expect($alerts->firstWhere('ncf_type', 'B01')['reason'])->toBe('low')
        ->and($alerts->firstWhere('ncf_type', 'B04')['reason'])->toBe('low')
        ->and($alerts->firstWhere('ncf_type', 'B14')['reason'])->toBe('expiring')
        ->and($alerts->firstWhere('ncf_type', 'B15')['reason'])->toBe('expired')
        ->and($alerts->firstWhere('ncf_type', 'B03')['reason'])->toBe('missing')
        ->and($alerts->firstWhere('ncf_type', 'B02'))->toBeNull();

    (new CheckNcfSequences)->handle(app(SequenceAlerts::class));
    Notification::assertSentTo($owner, NcfSequenceAlert::class);
    expect(Activity::inLog('ncf_sequences')->where('event', 'alerts_checked')->exists())->toBeTrue();
});
```

- [ ] **Step 3: Migration, enum, model, factory**

`ncf_sequences`: `id()`, `string('ncf_type', 3)->index()`, `unsignedInteger('range_from')`, `unsignedInteger('range_to')`, `unsignedInteger('next_number')`, `date('expires_on')->nullable()`, `string('authorization_number', 50)->nullable()`, `boolean('is_active')->default(true)`, `timestamp('exhausted_at')->nullable()`, `string('notes', 255)->nullable()`, `timestamps()`, `unique(['ncf_type', 'range_from'])`.
`app/Enums/NcfType.php`:
```php
<?php

namespace App\Enums;

enum NcfType: string
{
    case B01 = 'B01';
    case B02 = 'B02';
    case B03 = 'B03';
    case B04 = 'B04';
    case B11 = 'B11';
    case B13 = 'B13';
    case B14 = 'B14';
    case B15 = 'B15';
    case B16 = 'B16';
    case B17 = 'B17';

    public function label(): string
    {
        return match ($this) {
            self::B01 => 'Factura de Crédito Fiscal', self::B02 => 'Factura de Consumo', self::B03 => 'Nota de Débito', self::B04 => 'Nota de Crédito',
            self::B11 => 'Comprobante de Compras', self::B13 => 'Gastos Menores', self::B14 => 'Regímenes Especiales', self::B15 => 'Gubernamental',
            self::B16 => 'Exportaciones', self::B17 => 'Pagos al Exterior',
        };
    }

    /** Norma General 06-2018 art. 6 / Guía NCF §6: consumo and notas de crédito do not expire. */
    public function expires(): bool
    {
        return ! in_array($this, [self::B02, self::B04], true);
    }

    public function isSalesType(): bool
    {
        return in_array($this, [self::B01, self::B02, self::B03, self::B04, self::B14, self::B15, self::B16], true);
    }
}
```
`app/Models/NcfSequence.php`: `use HasFactory, LogsModelActivity;` fillable all; casts `ncf_type` → `NcfType::class`, ints for ranges, `expires_on` → `date:Y-m-d`, `is_active` boolean, `exhausted_at` datetime; accessors `protected function remaining(): Attribute { return Attribute::get(fn () => max(0, $this->range_to - $this->next_number + 1)); }` and `used(): Attribute` (`$this->next_number - $this->range_from`); scope `active()`.
`NcfSequenceFactory::definition()`: `['ncf_type' => 'B01', 'range_from' => 1, 'range_to' => 100, 'next_number' => 1, 'expires_on' => now()->addYear()->endOfYear()->toDateString(), 'authorization_number' => fake()->numerify('70########'), 'is_active' => true, 'exhausted_at' => null, 'notes' => null]`.

- [ ] **Step 4: Services, exception, job, notification**

`app/Support/Fiscal/FiscalNumber.php`: `final readonly class FiscalNumber { public function __construct(public string $ncf, public NcfType $type, public ?CarbonImmutable $expiresOn, public int $sequenceId) {} }`.
`app/Exceptions/FiscalNumberingException.php`: `class FiscalNumberingException extends \RuntimeException {}`.
`app/Services/Fiscal/FiscalNumberingService.php`:
```php
<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\NcfSequence;
use App\Services\Settings;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FiscalNumberingService
{
    public function __construct(private readonly Settings $settings) {}

    public function mode(): string
    {
        return (string) $this->settings->get('fiscal.mode', 'ncf');
    }

    /** Traditional NCF assignment (Phase 3 delegates to the EcfProvider when mode() === 'ecf'). */
    public function assign(NcfType $type, CarbonInterface $issueDate): FiscalNumber
    {
        return DB::transaction(function () use ($type, $issueDate): FiscalNumber {
            $candidates = NcfSequence::query()->where('ncf_type', $type->value)->where('is_active', true)->orderBy('range_from')->lockForUpdate()->get();
            if ($candidates->isEmpty()) {
                throw new FiscalNumberingException("No hay una secuencia activa de {$type->label()} ({$type->value}).");
            }
            $usable = $candidates->first(fn (NcfSequence $s) => $s->next_number <= $s->range_to && ! $this->isExpired($s, $type, $issueDate));
            if ($usable === null) {
                $last = $candidates->last();
                if ($this->isExpired($last, $type, $issueDate)) {
                    throw new FiscalNumberingException("La secuencia de {$type->value} venció el {$last->expires_on->format('d/m/Y')}; registra una nueva secuencia autorizada por la DGII.");
                }
                throw new FiscalNumberingException("La secuencia de {$type->value} está agotada; registra una nueva secuencia autorizada por la DGII.");
            }
            $number = $usable->next_number;
            $usable->next_number = $number + 1;
            if ($usable->next_number > $usable->range_to) {
                $usable->exhausted_at = now();
            }
            $usable->save();
            if ($usable->exhausted_at !== null) {
                activity('ncf_sequences')->performedOn($usable)->event('exhausted')->log("Secuencia {$type->value} agotada");
            }

            return new FiscalNumber(
                $type->value.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
                $type,
                $usable->expires_on ? CarbonImmutable::parse($usable->expires_on) : null,
                $usable->id,
            );
        });
    }

    private function isExpired(NcfSequence $s, NcfType $type, CarbonInterface $issueDate): bool
    {
        return $type->expires() && $s->expires_on !== null && $issueDate->toDateString() > $s->expires_on->toDateString();
    }
}
```
`app/Services/Fiscal/NcfTypeResolver.php`:
```php
<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Enums\TaxpayerType;
use App\Models\Client;

class NcfTypeResolver
{
    /** Spec §3.3: crédito fiscal when the client has an RNC/cédula and is a persona jurídica/física; régimen especial → B14; gubernamental → B15; otherwise consumo. */
    public function forSale(Client $client): NcfType
    {
        return match ($client->taxpayer_type) {
            TaxpayerType::RegimenEspecial => NcfType::B14,
            TaxpayerType::Gubernamental => NcfType::B15,
            TaxpayerType::Juridica, TaxpayerType::Fisica => $client->tax_id ? NcfType::B01 : NcfType::B02,
            default => NcfType::B02,
        };
    }

    public function forCreditNote(): NcfType
    {
        return NcfType::B04;
    }

    public function forDebitNote(): NcfType
    {
        return NcfType::B03;
    }
}
```
`app/Services/Fiscal/SequenceAlerts.php`:
```php
<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Models\NcfSequence;

class SequenceAlerts
{
    public const LOW_PERCENT = 10;

    public const EXPIRING_DAYS = 30;

    /** @return list<array{ncf_type: string, label: string, remaining: int, expires_on: ?string, reason: string}> */
    public function current(): array
    {
        $alerts = [];
        foreach ([NcfType::B01, NcfType::B02, NcfType::B03, NcfType::B04, NcfType::B14, NcfType::B15] as $type) {
            $sequences = NcfSequence::query()->where('ncf_type', $type->value)->where('is_active', true)->orderBy('range_from')->get();
            $usable = $sequences->filter(fn (NcfSequence $s) => $s->remaining > 0 && ! ($type->expires() && $s->expires_on && $s->expires_on->isPast()));
            if ($sequences->isEmpty()) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => 0, 'expires_on' => null, 'reason' => 'missing'];

                continue;
            }
            if ($usable->isEmpty()) {
                $last = $sequences->last();
                $expired = $type->expires() && $last->expires_on && $last->expires_on->isPast();
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => 0, 'expires_on' => $last->expires_on?->toDateString(), 'reason' => $expired ? 'expired' : 'low'];

                continue;
            }
            $remaining = (int) $usable->sum('remaining');
            $total = (int) $usable->sum(fn (NcfSequence $s) => $s->range_to - $s->range_from + 1);
            $soonest = $usable->filter(fn (NcfSequence $s) => $s->expires_on !== null)->min('expires_on');
            if ($type->expires() && $soonest !== null && now()->diffInDays($soonest, false) <= self::EXPIRING_DAYS) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => $remaining, 'expires_on' => $soonest->toDateString(), 'reason' => 'expiring'];
            } elseif ($total > 0 && ($remaining * 100 / $total) < self::LOW_PERCENT) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => $remaining, 'expires_on' => $soonest?->toDateString(), 'reason' => 'low'];
            }
        }

        return $alerts;
    }
}
```
`app/Jobs/CheckNcfSequences.php` (`ShouldQueue`, `Queueable`, `#[Tries(1)]`): `handle(SequenceAlerts $alerts): void { $list = $alerts->current(); activity('ncf_sequences')->event('alerts_checked')->withProperties(['alerts' => $list])->log('Revisión de secuencias NCF: '.count($list).' alertas'); if ($list === []) { return; } $users = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can('fiscal.sequences.manage')); Notification::send($users, new NcfSequenceAlert($list)); }`.
`app/Notifications/NcfSequenceAlert.php` (`via ['mail', 'database']`; constructor `array $alerts`): mail subject `Alerta de secuencias NCF`, one `->line()` per alert with text by reason — `low`: `{label} ({ncf_type}): quedan {remaining} números.`; `expiring`: `{label} ({ncf_type}): vence el {dd/mm/YYYY}.`; `expired`: `{label} ({ncf_type}): venció el {dd/mm/YYYY}.`; `missing`: `{label} ({ncf_type}): no hay secuencia registrada.`; action `Ver secuencias` → `route('admin.ncf-sequences.index')`; `toArray` → `['alerts' => $this->alerts]`.
`routes/console.php`: `Schedule::job(new CheckNcfSequences)->dailyAt('07:30');`

- [ ] **Step 5: Admin screen**

Routes (admin group): `Route::resource('ncf-sequences', NcfSequenceController::class)->except(['show', 'destroy'])->parameters(['ncf-sequences' => 'ncfSequence']);`
`StoreNcfSequenceRequest` (`$booleans = ['is_active']`; `expires_on`, `authorization_number`, `notes` `''` → `null`):
```php
'ncf_type' => ['required', Rule::enum(NcfType::class)],
'range_from' => ['required', 'integer', 'between:1,99999999'],
'range_to' => ['required', 'integer', 'between:1,99999999', 'gte:range_from'],
'expires_on' => ['nullable', 'date_format:Y-m-d'],
'authorization_number' => ['nullable', 'string', 'max:50'],
'notes' => ['nullable', 'string', 'max:255'],
'is_active' => ['boolean'],
```
`withValidator` (`after`): (a) if `NcfType::from($type)->expires()` and `expires_on` empty → `expires_on`: `Este tipo de comprobante requiere fecha de vencimiento (Norma General 06-2018, art. 6).`; (b) overlap: `NcfSequence::where('ncf_type', $type)->where(fn ($q) => $q->where('range_from', '<=', $to)->where('range_to', '>=', $from))->when($this->route('ncfSequence'), fn ($q, $s) => $q->whereKeyNot($s->id))->exists()` → `range_from`: `El rango se solapa con otra secuencia del mismo tipo.`
`UpdateNcfSequenceRequest extends StoreNcfSequenceRequest` adds in `withValidator`: when the sequence has `next_number > range_from` (used): `ncf_type` must equal the current type (error `ncf_type`: `No se puede cambiar el tipo de una secuencia usada.`) and `range_to >= next_number - 1` (error `range_to`: `La secuencia ya emitió hasta el número {next_number - 1}.`).
`NcfSequenceController` (all `#[Middleware('permission:fiscal.sequences.manage')]` except `index` → `fiscal.sequences.view`): `index` → `TableQuery::apply(NcfSequence::query(), $request, [], ['ncf_type', 'range_from', 'expires_on', 'is_active'], 'ncf_type', 'asc')->through(fn ($s) => [...$s->toArray(), 'ncf_type' => $s->ncf_type->value, 'label' => $s->ncf_type->label(), 'remaining' => $s->remaining, 'used' => $s->used])`, props `alerts` (`SequenceAlerts::current()`), `canManage`, `fiscalMode`; `store` → `NcfSequence::create([...$v, 'next_number' => $v['range_from']])` → `'Secuencia registrada'`; `update` → `'Secuencia actualizada'`; form options `types` (`[{value,label,expires}]` for sales types + B11/B13/B17).
`resources/js/types/fiscal.ts`: `NcfSequenceRow = { id: number; ncf_type: string; label: string; range_from: number; range_to: number; next_number: number; remaining: number; used: number; expires_on: string | null; authorization_number: string | null; is_active: boolean; exhausted_at: string | null; notes: string | null }`, `SequenceAlert = { ncf_type: string; label: string; remaining: number; expires_on: string | null; reason: 'low' | 'expiring' | 'expired' | 'missing' }`.
Pages: index (title "Secuencias NCF"; `Alert` list for `alerts` — destructive for `expired`/`missing`, default for `low`/`expiring`, texts as the notification lines; columns Tipo (`{ncf_type} — {label}`), Rango (`{range_from}–{range_to}` zero-padded to 8), Próximo, Restantes, Vence (`formatDate` or "No vence"), Autorización, Estado; button "Nueva secuencia"); form (`ncf_type` Select from `types`, disabled when editing a used sequence; `range_from`, `range_to` numbers; `expires_on` date (hint "Obligatorio salvo Consumo (B02) y Notas de Crédito (B04)"); `authorization_number`; `notes`; `is_active`).

- [ ] **Step 6: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (365 tests).

- [ ] **Step 7: Commit** — `feat(fiscal): add NCF sequences, fiscal numbering service and alerts`

---

### Task 4: Inventory core — lots, stock balances, movements, reservations, `InventoryService`

**Files:**
- Create: migrations `2026_11_01_000004_create_lots_table.php`, `2026_11_01_000005_create_stock_balances_table.php`, `2026_11_01_000006_create_stock_movements_table.php`, `2026_11_01_000007_create_stock_reservations_table.php`; enums `app/Enums/StockMovementType.php`; models `Lot`, `StockBalance`, `StockMovement`, `StockReservation` (+ factories for `Lot`, `StockBalance`); `app/Exceptions/InventoryException.php`; `app/Support/Inventory/StockLine.php`; `app/Services/Inventory/InventoryService.php`; `tests/Feature/Inventory/InventoryServiceTest.php`

**Interfaces:**
- Consumes: `Product`, `Warehouse`, `Decimal`, `AccountingEventRecorder`.
- Produces: enum `StockMovementType: string { Initial='initial'; Receipt='receipt'; Dispatch='dispatch'; Return='return'; AdjustmentIn='adjustment_in'; AdjustmentOut='adjustment_out'; TransferIn='transfer_in'; TransferOut='transfer_out' }` with `label()` and `direction(): int` (+1 for initial/receipt/return/adjustment_in/transfer_in, −1 otherwise); `Lot(product_id, code, expires_on)`; `StockBalance(product_id, warehouse_id, lot_id, quantity, reserved_quantity, average_cost; accessor available)`; `StockMovement(type, product_id, warehouse_id, lot_id, quantity, unit_cost, total_cost, balance_after, reference_type, reference_id, moved_at, user_id, notes)`; `StockReservation(invoice_line_id, product_id, warehouse_id, quantity, released_at)`; `StockLine` readonly (`int $productId, int $warehouseId, string $quantity, ?string $unitCost = null, ?int $lotId = null, ?string $lotCode = null, ?string $lotExpiresOn = null`); `InventoryService` methods:
  - `receive(StockLine $line, StockMovementType $type, Model $reference, User $actor, ?string $notes = null): StockMovement` — positive entry; creates/finds the lot when the product `tracks_lots` (throws `InventoryException('El producto {sku} controla lotes: indica lote y fecha de vencimiento')` when missing); updates weighted average cost: `avg' = (qty_old × avg_old + qty_in × unit_cost) / (qty_old + qty_in)` at 4 dp HALF_UP (per product+warehouse, lots share the warehouse average).
  - `dispatch(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement` — negative exit at the current average cost; throws `InventoryException('Existencia insuficiente de {sku} en {warehouse}: disponible {n}')` when `quantity < qty` on the balance (lot-level when a lot is given); when the product tracks lots a `lotId` is mandatory.
  - `returnStock(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement` — entry with `Return` type at the given `unitCost` (the original dispatch cost).
  - `reserve(InvoiceLine-like Model $line, int $productId, int $warehouseId, string $quantity): StockReservation` — increments `reserved_quantity` on the warehouse-level balance (no lot), throws `InventoryException('Disponible insuficiente de {sku} en {warehouse}: disponible {n}, solicitado {q}')`.
  - `release(StockReservation $reservation, ?string $quantity = null): void` — decrements (fully or partially) and sets `released_at` when fully released.
  - `available(int $productId, int $warehouseId): string` — `Σ quantity − Σ reserved` over the product's balances in that warehouse (4 dp).
  - `suggestLots(int $productId, int $warehouseId, string $quantity): list<array{lot_id:int, code:string, expires_on:?string, quantity:string}>` — FEFO: lots with stock ordered by `expires_on asc nulls last, id asc`, filling the requested quantity.
  - `lowStock(): Collection` — products whose total stock across warehouses is `< minimum_stock` (and `minimum_stock > 0`).
  - `expiringLots(int $days): Collection` — lots with stock whose `expires_on <= today + days`.

Rules: all quantities/costs are 4-dp strings; `StockBalance` rows are created lazily; every movement writes `balance_after` (product+warehouse+lot level) and the accounting event `inventory_movement` with payload `{type, product_id, sku, warehouse_id, lot_id, quantity, unit_cost, total_cost, currency: 'DOP', exchange_rate: '1.000000'}`; movements are append-only (`StockMovement` uses `ImmutableWhenIssued` overriding `issuedAtColumn()` to return `'moved_at'`; `mutableWhenIssued()` keeps the trait default `[]`).

- [ ] **Step 1: Failing tests `tests/Feature/Inventory/InventoryServiceTest.php`**

```php
<?php

use App\Enums\StockMovementType;
use App\Exceptions\InventoryException;
use App\Models\AccountingEvent;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;

beforeEach(function () {
    (new CurrencySeeder)->run();
    $this->actor = User::factory()->create();
    $this->wh = Warehouse::factory()->create();
    $this->svc = app(InventoryService::class);
});

test('receiving stock creates balances, updates weighted average cost and logs movement and event', function () {
    $p = Product::factory()->create(['sku' => 'A-1']);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '100'), StockMovementType::Initial, $this->actor, $this->actor);
    $m = $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '120'), StockMovementType::Receipt, $this->actor, $this->actor);

    $b = StockBalance::where('product_id', $p->id)->where('warehouse_id', $this->wh->id)->firstOrFail();
    expect($b->quantity)->toBe('20.0000')->and($b->average_cost)->toBe('110.0000')->and($b->available)->toBe('20.0000')
        ->and($m->balance_after)->toBe('20.0000')->and($m->total_cost)->toBe('1200.00')
        ->and(StockMovement::count())->toBe(2)
        ->and(AccountingEvent::where('type', 'inventory_movement')->count())->toBe(2)
        ->and(AccountingEvent::latest('id')->first()->payload['total_cost'])->toBe('1200.00');
});

test('lot-tracked products require lot data and keep per-lot balances', function () {
    $p = Product::factory()->create(['sku' => 'L-1', 'tracks_lots' => true]);
    expect(fn () => $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10'), StockMovementType::Initial, $this->actor, $this->actor))
        ->toThrow(InventoryException::class, 'El producto L-1 controla lotes: indica lote y fecha de vencimiento');

    $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10', null, 'LOTE-A', '2027-01-31'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '7', '10', null, 'LOTE-B', '2026-12-01'), StockMovementType::Initial, $this->actor, $this->actor);

    expect(Lot::where('product_id', $p->id)->count())->toBe(2)
        ->and(StockBalance::where('product_id', $p->id)->whereNotNull('lot_id')->count())->toBe(2)
        ->and($this->svc->available($p->id, $this->wh->id))->toBe('12.0000');
});

test('fefo suggests the lots that expire first', function () {
    $p = Product::factory()->create(['tracks_lots' => true]);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10', null, 'LATE', '2027-06-30'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '3', '10', null, 'SOON', '2026-12-01'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '4', '10', null, 'NOEXP', null), StockMovementType::Initial, $this->actor, $this->actor);

    $s = $this->svc->suggestLots($p->id, $this->wh->id, '6');
    expect($s)->toHaveCount(2)->and($s[0]['code'])->toBe('SOON')->and($s[0]['quantity'])->toBe('3.0000')->and($s[1]['code'])->toBe('LATE')->and($s[1]['quantity'])->toBe('3.0000');
});

test('reservations reduce availability and dispatch refuses when stock is insufficient', function () {
    $p = Product::factory()->create(['sku' => 'R-1']);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '50'), StockMovementType::Initial, $this->actor, $this->actor);
    $res = $this->svc->reserve($this->actor, $p->id, $this->wh->id, '8');
    expect($this->svc->available($p->id, $this->wh->id))->toBe('2.0000');
    expect(fn () => $this->svc->reserve($this->actor, $p->id, $this->wh->id, '3'))->toThrow(InventoryException::class, "Disponible insuficiente de R-1 en {$this->wh->name}: disponible 2.0000, solicitado 3.0000");

    $this->svc->release($res, '3');
    expect($this->svc->available($p->id, $this->wh->id))->toBe('5.0000')->and($res->fresh()->released_at)->toBeNull();
    $this->svc->release($res);
    expect($this->svc->available($p->id, $this->wh->id))->toBe('10.0000')->and($res->fresh()->released_at)->not->toBeNull();

    expect(fn () => $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '11'), $this->actor, $this->actor))
        ->toThrow(InventoryException::class, "Existencia insuficiente de R-1 en {$this->wh->name}: disponible 10.0000");
    $m = $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '4'), $this->actor, $this->actor);
    expect($m->unit_cost)->toBe('50.0000')->and($m->total_cost)->toBe('200.00')->and($m->balance_after)->toBe('6.0000')->and($m->type)->toBe(StockMovementType::Dispatch);
});

test('returns re-enter stock at the given cost without changing the average', function () {
    $p = Product::factory()->create();
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '100'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '4'), $this->actor, $this->actor);
    $m = $this->svc->returnStock(new StockLine($p->id, $this->wh->id, '1', '100'), $this->actor, $this->actor);
    $b = StockBalance::where('product_id', $p->id)->firstOrFail();
    expect($b->quantity)->toBe('7.0000')->and($b->average_cost)->toBe('100.0000')->and($m->type)->toBe(StockMovementType::Return);
});

test('movements are append-only and low stock and expiring lots are reported', function () {
    $p = Product::factory()->create(['minimum_stock' => '5']);
    $m = $this->svc->receive(new StockLine($p->id, $this->wh->id, '2', '1'), StockMovementType::Initial, $this->actor, $this->actor);
    expect(fn () => $m->update(['quantity' => '99']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
    expect($this->svc->lowStock()->pluck('id')->all())->toBe([$p->id]);

    $l = Product::factory()->create(['tracks_lots' => true]);
    $this->svc->receive(new StockLine($l->id, $this->wh->id, '1', '1', null, 'X', now()->addDays(10)->toDateString()), StockMovementType::Initial, $this->actor, $this->actor);
    expect($this->svc->expiringLots(30))->toHaveCount(1)->and($this->svc->expiringLots(5))->toHaveCount(0);
});
```

- [ ] **Step 2: Migrations**

`lots`: `id()`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `string('code', 50)`, `date('expires_on')->nullable()`, `timestamps()`, `unique(['product_id', 'code'])`.
`stock_balances`: `id()`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`, `foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete()`, `decimal('quantity', 14, 4)->default(0)`, `decimal('reserved_quantity', 14, 4)->default(0)`, `decimal('average_cost', 14, 4)->default(0)`, `timestamps()`, `index(['product_id', 'warehouse_id'])`, plus after `Schema::create`: `DB::statement('CREATE UNIQUE INDEX stock_balances_product_warehouse_lot_unique ON stock_balances (product_id, warehouse_id, COALESCE(lot_id, 0))');` (`down` drops the index before the table).
`stock_movements`: `id()`, `string('type', 20)->index()`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`, `foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete()`, `decimal('quantity', 14, 4)`, `decimal('unit_cost', 14, 4)`, `decimal('total_cost', 14, 2)`, `decimal('balance_after', 14, 4)`, `string('reference_type', 120)->nullable()`, `unsignedBigInteger('reference_id')->nullable()`, `index(['reference_type', 'reference_id'])`, `timestamp('moved_at')->index()`, `foreignId('user_id')->constrained('users')->restrictOnDelete()`, `string('notes', 255)->nullable()`, `timestamps()`.
`stock_reservations`: `id()`, `string('reservable_type', 120)`, `unsignedBigInteger('reservable_id')`, `index(['reservable_type', 'reservable_id'])`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`, `decimal('quantity', 14, 4)`, `timestamp('released_at')->nullable()`, `timestamps()`.

- [ ] **Step 3: Enum, models, factories, value object, exception**

`StockMovementType` enum with the cases/labels ("Existencia inicial", "Entrada", "Salida por despacho", "Devolución", "Ajuste de entrada", "Ajuste de salida", "Transferencia entrada", "Transferencia salida") and `direction()`.
`Lot`: `use HasFactory, LogsModelActivity;` fillable `product_id, code, expires_on`; cast `expires_on` → `date:Y-m-d`; `product()`, `balances(): HasMany`. Factory: `['product_id' => Product::factory(), 'code' => strtoupper(fake()->unique()->bothify('LOT-####')), 'expires_on' => now()->addYear()->toDateString()]`.
`StockBalance`: `use HasFactory, LogsModelActivity;` fillable `product_id, warehouse_id, lot_id, quantity, reserved_quantity, average_cost`; casts `decimal:4` on the three decimals; accessor `available` (`Decimal::qty(Decimal::of($this->quantity)->minus(Decimal::of($this->reserved_quantity)))`); `product()`, `warehouse()`, `lot()`. Factory: `['product_id' => Product::factory(), 'warehouse_id' => Warehouse::factory(), 'lot_id' => null, 'quantity' => '0', 'reserved_quantity' => '0', 'average_cost' => '0']`.
`StockMovement`: `use ImmutableWhenIssued, LogsModelActivity;` with `protected function issuedAtColumn(): string { return 'moved_at'; }`; fillable all; casts `type` → enum, `quantity/unit_cost/balance_after` → `decimal:4`, `total_cost` → `decimal:2`, `moved_at` → datetime; relations `product`, `warehouse`, `lot`, `user`, `reference(): MorphTo`.
`StockReservation`: `use LogsModelActivity;` fillable all; `quantity` `decimal:4`; `released_at` datetime; `reservable(): MorphTo`.
`app/Support/Inventory/StockLine.php`: `final readonly class StockLine { public function __construct(public int $productId, public int $warehouseId, public string $quantity, public ?string $unitCost = null, public ?int $lotId = null, public ?string $lotCode = null, public ?string $lotExpiresOn = null) {} }`.
`app/Exceptions/InventoryException.php`: `class InventoryException extends \RuntimeException {}`.

- [ ] **Step 4: `app/Services/Inventory/InventoryService.php`**

```php
<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InventoryException;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingEventRecorder;
use App\Support\Inventory\StockLine;
use App\Support\Money\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private readonly AccountingEventRecorder $events) {}

    public function receive(StockLine $line, StockMovementType $type, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        if ($type->direction() !== 1) {
            throw new InventoryException('El tipo de movimiento no es una entrada');
        }

        return DB::transaction(function () use ($line, $type, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $lot = $this->resolveLot($product, $line, required: $product->tracks_lots);
            $qty = Decimal::qty($line->quantity);
            $unitCost = Decimal::qty($line->unitCost ?? '0');

            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $oldQty = Decimal::of($warehouseLevel->quantity);
            $newQty = $oldQty->plus(Decimal::of($qty));
            $newAvg = $newQty->isZero() ? '0.0000' : (string) $oldQty->multipliedBy(Decimal::of($warehouseLevel->average_cost))
                ->plus(Decimal::of($qty)->multipliedBy(Decimal::of($unitCost)))
                ->dividedBy($newQty, 4, RoundingMode::HalfUp);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty($newQty), 'average_cost' => $newAvg])->save();

            $balanceAfter = $warehouseLevel->quantity;
            if ($lot !== null) {
                $lotLevel = $this->balance($product->id, $line->warehouseId, $lot->id, lock: true);
                $lotLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($lotLevel->quantity)->plus(Decimal::of($qty))), 'average_cost' => $newAvg])->save();
                $balanceAfter = $lotLevel->quantity;
            }

            return $this->movement($type, $product, $line->warehouseId, $lot?->id, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function dispatch(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        return DB::transaction(function () use ($line, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $warehouse = Warehouse::query()->findOrFail($line->warehouseId);
            if ($product->tracks_lots && $line->lotId === null) {
                throw new InventoryException("El producto {$product->sku} controla lotes: indica el lote a despachar");
            }
            $qty = Decimal::qty($line->quantity);
            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $target = $line->lotId !== null ? $this->balance($product->id, $line->warehouseId, $line->lotId, lock: true) : $warehouseLevel;
            if (Decimal::cmp($target->quantity, $qty) < 0) {
                throw new InventoryException("Existencia insuficiente de {$product->sku} en {$warehouse->name}: disponible ".Decimal::qty($target->quantity));
            }
            $unitCost = Decimal::qty($warehouseLevel->average_cost);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($warehouseLevel->quantity)->minus(Decimal::of($qty)))])->save();
            $balanceAfter = $warehouseLevel->quantity;
            if ($line->lotId !== null) {
                $target->forceFill(['quantity' => Decimal::qty(Decimal::of($target->quantity)->minus(Decimal::of($qty)))])->save();
                $balanceAfter = $target->quantity;
            }

            return $this->movement(StockMovementType::Dispatch, $product, $line->warehouseId, $line->lotId, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function returnStock(StockLine $line, Model $reference, User $actor, ?string $notes = null): StockMovement
    {
        return DB::transaction(function () use ($line, $reference, $actor, $notes): StockMovement {
            $product = Product::query()->findOrFail($line->productId);
            $lot = $this->resolveLot($product, $line, required: $product->tracks_lots);
            $qty = Decimal::qty($line->quantity);
            $unitCost = Decimal::qty($line->unitCost ?? '0');
            $warehouseLevel = $this->balance($product->id, $line->warehouseId, null, lock: true);
            $warehouseLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($warehouseLevel->quantity)->plus(Decimal::of($qty)))])->save(); // average unchanged
            $balanceAfter = $warehouseLevel->quantity;
            if ($lot !== null) {
                $lotLevel = $this->balance($product->id, $line->warehouseId, $lot->id, lock: true);
                $lotLevel->forceFill(['quantity' => Decimal::qty(Decimal::of($lotLevel->quantity)->plus(Decimal::of($qty)))])->save();
                $balanceAfter = $lotLevel->quantity;
            }

            return $this->movement(StockMovementType::Return, $product, $line->warehouseId, $lot?->id, $qty, $unitCost, $balanceAfter, $reference, $actor, $notes);
        });
    }

    public function reserve(Model $reservable, int $productId, int $warehouseId, string $quantity): StockReservation
    {
        return DB::transaction(function () use ($reservable, $productId, $warehouseId, $quantity): StockReservation {
            $product = Product::query()->findOrFail($productId);
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            $qty = Decimal::qty($quantity);
            $balance = $this->balance($productId, $warehouseId, null, lock: true);
            $available = $this->available($productId, $warehouseId);
            if (Decimal::cmp($available, $qty) < 0) {
                throw new InventoryException("Disponible insuficiente de {$product->sku} en {$warehouse->name}: disponible {$available}, solicitado {$qty}");
            }
            $balance->forceFill(['reserved_quantity' => Decimal::qty(Decimal::of($balance->reserved_quantity)->plus(Decimal::of($qty)))])->save();

            return StockReservation::create(['reservable_type' => $reservable::class, 'reservable_id' => $reservable->getKey(), 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $qty]);
        });
    }

    public function release(StockReservation $reservation, ?string $quantity = null): void
    {
        DB::transaction(function () use ($reservation, $quantity): void {
            $qty = Decimal::qty($quantity ?? $reservation->quantity);
            $balance = $this->balance($reservation->product_id, $reservation->warehouse_id, null, lock: true);
            $balance->forceFill(['reserved_quantity' => Decimal::qty(Decimal::of($balance->reserved_quantity)->minus(Decimal::of($qty)))])->save();
            $remaining = Decimal::qty(Decimal::of($reservation->quantity)->minus(Decimal::of($qty)));
            $reservation->forceFill(['quantity' => $remaining, 'released_at' => Decimal::isZero($remaining) ? now() : null])->save();
        });
    }

    public function available(int $productId, int $warehouseId): string
    {
        $row = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->whereNull('lot_id')->first();

        return $row === null ? '0.0000' : $row->available;
    }

    /** @return list<array{lot_id: int, code: string, expires_on: ?string, quantity: string}> */
    public function suggestLots(int $productId, int $warehouseId, string $quantity): array
    {
        $needed = Decimal::of($quantity);
        $out = [];
        $rows = StockBalance::query()->with('lot')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->whereNotNull('lot_id')->where('quantity', '>', 0)->get()
            ->sortBy([fn ($a, $b) => ($a->lot->expires_on?->timestamp ?? PHP_INT_MAX) <=> ($b->lot->expires_on?->timestamp ?? PHP_INT_MAX) ?: $a->lot_id <=> $b->lot_id]);
        foreach ($rows as $row) {
            if ($needed->isNegativeOrZero()) {
                break;
            }
            $take = Decimal::of($row->quantity)->isGreaterThan($needed) ? $needed : Decimal::of($row->quantity);
            $out[] = ['lot_id' => $row->lot_id, 'code' => $row->lot->code, 'expires_on' => $row->lot->expires_on?->toDateString(), 'quantity' => Decimal::qty($take)];
            $needed = $needed->minus($take);
        }

        return $out;
    }

    public function lowStock(): Collection
    {
        return Product::query()->active()->where('minimum_stock', '>', 0)
            ->withSum(['balances as stock_total' => fn ($q) => $q->whereNull('lot_id')], 'quantity')
            ->get()->filter(fn (Product $p) => Decimal::cmp((string) ($p->stock_total ?? '0'), (string) $p->minimum_stock) < 0)->values();
    }

    public function expiringLots(int $days): Collection
    {
        return StockBalance::query()->with(['lot', 'product', 'warehouse'])->whereNotNull('lot_id')->where('quantity', '>', 0)
            ->whereHas('lot', fn ($q) => $q->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days)->toDateString()))->get();
    }

    private function resolveLot(Product $product, StockLine $line, bool $required): ?Lot
    {
        if ($line->lotId !== null) {
            return Lot::query()->findOrFail($line->lotId);
        }
        if ($line->lotCode !== null && $line->lotCode !== '') {
            return Lot::query()->firstOrCreate(['product_id' => $product->id, 'code' => $line->lotCode], ['expires_on' => $line->lotExpiresOn]);
        }
        if ($required) {
            throw new InventoryException("El producto {$product->sku} controla lotes: indica lote y fecha de vencimiento");
        }

        return null;
    }

    private function balance(int $productId, int $warehouseId, ?int $lotId, bool $lock = false): StockBalance
    {
        $query = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId);
        $lotId === null ? $query->whereNull('lot_id') : $query->where('lot_id', $lotId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? StockBalance::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId, 'quantity' => '0', 'reserved_quantity' => '0', 'average_cost' => '0']);
    }

    private function movement(StockMovementType $type, Product $product, int $warehouseId, ?int $lotId, string $qty, string $unitCost, string $balanceAfter, Model $reference, User $actor, ?string $notes): StockMovement
    {
        $movement = StockMovement::create([
            'type' => $type, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId, 'quantity' => $qty, 'unit_cost' => $unitCost,
            'total_cost' => Decimal::mul($qty, $unitCost, Decimal::MONEY), 'balance_after' => $balanceAfter, 'reference_type' => $reference::class, 'reference_id' => $reference->getKey(),
            'moved_at' => now(), 'user_id' => $actor->id, 'notes' => $notes,
        ]);
        $this->events->record('inventory_movement', $movement, [
            'type' => $type->value, 'product_id' => $product->id, 'sku' => $product->sku, 'warehouse_id' => $warehouseId, 'lot_id' => $lotId,
            'quantity' => $qty, 'unit_cost' => $unitCost, 'total_cost' => $movement->total_cost, 'currency' => 'DOP', 'exchange_rate' => '1.000000',
        ]);

        return $movement;
    }
}
```
Add to `Product`: `/** @return HasMany<StockBalance, $this> */ public function balances(): HasMany { return $this->hasMany(StockBalance::class); }`. Add `reservations(): MorphMany` nowhere yet (Task 9 adds it to `InvoiceLine`).

- [ ] **Step 5: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT indexname FROM pg_indexes WHERE tablename='stock_balances' AND indexname='stock_balances_product_warehouse_lot_unique'" && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → index name printed; passed (371 tests).

- [ ] **Step 6: Commit** — `feat(inventory): add lots, stock balances, movements, reservations and inventory service`

---

### Task 5: Initial stock — manual entry screen and Excel importer (with lots and cost)

**Files:**
- Create: `app/Actions/Inventory/LoadInitialStock.php`, `app/Http/Controllers/Inventory/InitialStockController.php`, `app/Http/Requests/Inventory/StoreInitialStockRequest.php`, `app/Imports/InitialStockImporter.php`, `resources/js/pages/inventory/initial-stock/form.tsx`, `resources/js/types/inventory.ts` (append), `tests/Feature/Inventory/InitialStockTest.php`, `tests/Feature/DataImport/InitialStockImporterTest.php`
- Modify: `routes/inventory.php`, `routes/admin.php` (the imports group middleware becomes `permission:catalog.clients.import|catalog.suppliers.import|catalog.products.import|catalog.price-lists.import|inventory.initial-stock.import|sales.historical-invoices.import`), `app/Imports/ImporterRegistry.php` (append `InitialStockImporter::class` to `IMPORTERS`), `app/Http/Controllers/DataImport/ImportController.php` (`metaOptions`: `initial_stock` → `['warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])]`), `resources/js/pages/admin/imports/index.tsx` (warehouse `Select name="warehouse_id"` labelled "Almacén" when `importer.meta.warehouses` exists, same pattern as the existing price-list select), `resources/js/nav.ts` (Inventario → `{ title: 'Existencias iniciales', href: '/inventory/initial-stock', icon: PackagePlus, permission: 'inventory.initial-stock.import' }`, add `PackagePlus` to the lucide import)

**Interfaces:**
- Produces: `LoadInitialStock::handle(array $lines /* list<array{product_id:int, warehouse_id:int, quantity:string, unit_cost:string, lot_code?:?string, lot_expires_on?:?string}> */, User $actor, string $note): list<StockMovement>` (one transaction; every line goes through `InventoryService::receive(..., StockMovementType::Initial, $batchReference, …)` where the reference is an `InitialStockLoad` row); model `InitialStockLoad(note, user_id, lines_count)` (table `initial_stock_loads`, migration `2026_11_01_000008`); routes `inventory.initial-stock.{create,store}` (GET/POST `/inventory/initial-stock`, permission `inventory.initial-stock.import`); importer type `initial_stock` ("Existencias iniciales"), permission `inventory.initial-stock.import`, meta `warehouse_id` (required), columns `sku`/SKU/yes/`RON-750`, `quantity`/Cantidad/yes/`24`, `unit_cost`/Costo unitario/yes/`350.50`, `lot_code`/Lote/no/`L-2026-01`, `lot_expires_on`/Vencimiento/no/`2027-01-31`.

- [ ] **Step 1: Failing tests**

`tests/Feature/Inventory/InitialStockTest.php`:
```php
<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

test('manual initial stock requires permission and loads lines with lots and cost', function () {
    $wh = Warehouse::factory()->create();
    $plain = Product::factory()->create();
    $lotted = Product::factory()->create(['tracks_lots' => true]);
    $payload = ['note' => 'Carga inicial', 'lines' => [
        ['product_id' => $plain->id, 'warehouse_id' => $wh->id, 'quantity' => '10', 'unit_cost' => '100', 'lot_code' => '', 'lot_expires_on' => ''],
        ['product_id' => $lotted->id, 'warehouse_id' => $wh->id, 'quantity' => '5', 'unit_cost' => '20', 'lot_code' => 'L1', 'lot_expires_on' => '2027-01-31'],
    ]];

    actingAsRole('sales');
    $this->post(route('inventory.initial-stock.store'), $payload)->assertForbidden();

    actingAsRole('warehouse');
    $this->get(route('inventory.initial-stock.create'))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/initial-stock/form'));
    $this->post(route('inventory.initial-stock.store'), $payload)->assertRedirect(route('inventory.stock.index'))->assertSessionHas('success', 'Existencias iniciales cargadas: 2 líneas');

    expect(StockMovement::where('type', 'initial')->count())->toBe(2)
        ->and(StockBalance::where('product_id', $plain->id)->first()->quantity)->toBe('10.0000')
        ->and(StockBalance::where('product_id', $lotted->id)->whereNotNull('lot_id')->first()->quantity)->toBe('5.0000')
        ->and(Activity::inLog('initial_stock_loads')->where('event', 'created')->exists())->toBeTrue();
});

test('validation: lot data required for lot-tracked products and positive quantities', function () {
    $wh = Warehouse::factory()->create();
    $lotted = Product::factory()->create(['tracks_lots' => true]);
    actingAsRole('warehouse');
    $this->post(route('inventory.initial-stock.store'), ['note' => '', 'lines' => [['product_id' => $lotted->id, 'warehouse_id' => $wh->id, 'quantity' => '0', 'unit_cost' => '-1', 'lot_code' => '', 'lot_expires_on' => '']]])
        ->assertSessionHasErrors(['note', 'lines.0.quantity', 'lines.0.unit_cost', 'lines.0.lot_code' => 'El producto controla lotes: indica el lote.']);
});
```
`tests/Feature/DataImport/InitialStockImporterTest.php`:
```php
<?php

use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('initial stock import needs a warehouse and loads valid rows', function () {
    $wh = Warehouse::factory()->create();
    Product::factory()->create(['sku' => 'A-1']);
    Product::factory()->create(['sku' => 'L-1', 'tracks_lots' => true]);
    actingAsRole('warehouse');
    $file = makeSpreadsheet([['SKU', 'Cantidad', 'Costo unitario', 'Lote', 'Vencimiento'], ['a-1', '10', '100', '', ''], ['L-1', '5', '20', 'L1', '2027-01-31'], ['L-1', '5', '20', '', ''], ['NOPE', '1', '1', '', '']]);

    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file])->assertSessionHasErrors(['warehouse_id']);
    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file, 'warehouse_id' => $wh->id])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 4)->first()->errors['lot_code'])->toBe('El producto controla lotes: indica el lote.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors)->toHaveKey('sku');

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 2 creados, 0 actualizados');
    expect(StockBalance::whereNull('lot_id')->sum('quantity'))->toBe('15.0000');
});
```

- [ ] **Step 2: Migration, model, action, request, controller, page, importer**

`initial_stock_loads`: `id()`, `string('note', 255)`, `foreignId('user_id')->constrained('users')->restrictOnDelete()`, `unsignedInteger('lines_count')`, `timestamps()`. Model `InitialStockLoad` with `LogsModelActivity`, fillable three columns, `movements(): MorphMany` (`StockMovement`, `reference`).
`LoadInitialStock::handle(array $lines, User $actor, string $note): array` → `DB::transaction`: create the load, then for each line `$inventory->receive(new StockLine($l['product_id'], $l['warehouse_id'], $l['quantity'], $l['unit_cost'], null, $l['lot_code'] ?: null, $l['lot_expires_on'] ?: null), StockMovementType::Initial, $load, $actor, $note)`; returns the movements.
`StoreInitialStockRequest` rules: `'note' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.lot_code' => ['nullable', 'string', 'max:50'], 'lines.*.lot_expires_on' => ['nullable', 'date_format:Y-m-d']`; `withValidator` → for each line whose product `tracks_lots` and `lot_code` empty add error `lines.{i}.lot_code`: `El producto controla lotes: indica el lote.`
`InitialStockController` (`#[Middleware('permission:inventory.initial-stock.import')]`): `create` → page with `warehouses` (active), `defaultWarehouseId`; `store` → action → `redirect()->route('inventory.stock.index')->with('success', "Existencias iniciales cargadas: {$n} líneas")` (the `inventory.stock.index` route is created in Task 18; register a placeholder now: `Route::get('stock', fn () => Inertia::render('dashboard'))->name('stock.index')` in `routes/inventory.php`, replaced in Task 18).
Page `inventory/initial-stock/form.tsx` (title "Existencias iniciales"): `useForm({ note: '', lines: [] })`; `Input` Nota; lines table: Producto (`ProductPicker`, `clientId={null}`), Almacén `Select` (default `defaultWarehouseId`), Cantidad, Costo unitario, Lote, Vencimiento (date) — Lote/Vencimiento inputs disabled when the picked product has `tracks_lots === false`; buttons "Añadir producto", "Quitar", "Cargar existencias".
`InitialStockImporter` (`type()` `initial_stock`; `label()` "Existencias iniciales"; `permission()` `inventory.initial-stock.import`; `metaRules()` → `['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]`; `normalize` uppercases `sku` and `lot_code`; `rules` → `sku` `required|exists:products,sku`, `quantity` `required|numeric|gt:0`, `unit_cost` `required|numeric|min:0`, `lot_code` `nullable|string|max:50` plus a closure rule that fails with `El producto controla lotes: indica el lote.` when the product tracks lots and `lot_code` is empty, `lot_expires_on` `nullable|date_format:Y-m-d`; `naturalKey` → `sku.'|'.(lot_code ?? '')`; `findExisting` → always `null` (every row is a new movement, so confirm reports `N creados`); `persist` → `app(LoadInitialStock::class)->handle([[...line with product_id resolved from sku, warehouse_id from meta]], auth()->user(), 'Importación '.now()->format('Y-m-d'))[0]`). Register in `ImporterRegistry`.

- [ ] **Step 3: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (374 tests).

- [ ] **Step 4: Commit** — `feat(inventory): add initial stock entry and importer`

---

### Task 6: PDF infrastructure (dompdf), company header, document PDF renderer

**Files:**
- Create: `app/Services/Documents/DocumentPdf.php`, `resources/views/pdf/layout.blade.php`, `resources/views/pdf/partials/company-header.blade.php`, `resources/views/pdf/partials/lines-table.blade.php`, `resources/views/pdf/partials/totals.blade.php`, `resources/views/pdf/probe.blade.php`, `tests/Feature/Documents/DocumentPdfTest.php`
- Modify: `composer.json`, `config/dompdf.php` (published)

**Interfaces:**
- Consumes: `Settings` (`company.*`), `Storage` public disk (logo).
- Produces: `DocumentPdf::render(string $view, array $data, string $fileName): \Barryvdh\DomPDF\PDF` (A4 portrait, DejaVu Sans, UTF-8), `DocumentPdf::download(...)`: `Response`, `DocumentPdf::stream(...)`: `Response`, `DocumentPdf::binary(...)`: `string` (for e-mail attachments); Blade partials: `pdf.partials.company-header` (expects `$company` array from `DocumentPdf::company()`: `name, legal_name, rnc, address, phone, email, logo_path_absolute`), `pdf.partials.lines-table` (expects `$lines` list of arrays with `description, quantity, unit_price, discount_percent, itbis_amount, additional_tax_total, line_total` and `$currency`), `pdf.partials.totals` (expects `$totals` array and `$currency`); helper `DocumentPdf::money(string $v, string $currency): string` (`RD$ 1,234.50` / `US$ 1,234.50`).

- [ ] **Step 1: Install dompdf**

Run: `composer require "barryvdh/laravel-dompdf:^3.1" --no-interaction 2>&1 | tail -2 && php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider" --no-interaction && test -f config/dompdf.php && echo OK` → `OK`. In `config/dompdf.php` set `'default_font' => 'DejaVu Sans'` inside `options` (search-docs `["dompdf config default_font"]` if the key path differs; the option key is `defaultFont` in dompdf's own options array — set `'options' => [... 'defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'chroot' => realpath(base_path('public')) ...]`).

- [ ] **Step 2: Failing test `tests/Feature/Documents/DocumentPdfTest.php`**

```php
<?php

use App\Services\Documents\DocumentPdf;
use App\Services\Settings;

test('renders a pdf with the company header and spanish money format', function () {
    app(Settings::class)->set('company.name', 'Gomez International');
    app(Settings::class)->set('company.rnc', '101850043');
    $pdf = app(DocumentPdf::class);

    $binary = $pdf->binary('pdf.probe', ['title' => 'Prueba', 'lines' => [['description' => 'Ron añejo', 'quantity' => '2.0000', 'unit_price' => '100.0000', 'discount_percent' => '0.0000', 'itbis_amount' => '36.00', 'additional_tax_total' => '0.00', 'line_total' => '236.00']], 'totals' => ['subtotal' => '200.00', 'discount_total' => '0.00', 'taxable_total' => '200.00', 'itbis_total' => '36.00', 'additional_tax_total' => '0.00', 'total' => '236.00'], 'currency' => 'DOP'], 'prueba.pdf');

    expect(substr($binary, 0, 4))->toBe('%PDF')->and(strlen($binary))->toBeGreaterThan(1000)
        ->and(DocumentPdf::money('1234.5', 'DOP'))->toBe('RD$ 1,234.50')->and(DocumentPdf::money('1234.5', 'USD'))->toBe('US$ 1,234.50')
        ->and($pdf->company()['name'])->toBe('Gomez International')->and($pdf->company()['rnc_formatted'])->toBe('1-01-85004-3');
});
```

- [ ] **Step 3: Service and views**

`app/Services/Documents/DocumentPdf.php`:
```php
<?php

namespace App\Services\Documents;

use App\Services\Settings;
use App\Support\FiscalId;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentPdf
{
    public function __construct(private readonly Settings $settings) {}

    public static function money(string $value, string $currency): string
    {
        return ($currency === 'USD' ? 'US$ ' : 'RD$ ').number_format((float) $value, 2, '.', ','); // display only
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        $v = $this->settings->many(['company.name', 'company.legal_name', 'company.rnc', 'company.address', 'company.phone', 'company.email', 'company.logo_path']);
        $logo = $v['company.logo_path'] ? Storage::disk('public')->path($v['company.logo_path']) : null;

        return [
            'name' => $v['company.name'], 'legal_name' => $v['company.legal_name'], 'rnc' => $v['company.rnc'],
            'rnc_formatted' => $v['company.rnc'] ? FiscalId::format($v['company.rnc']) : null,
            'address' => $v['company.address'], 'phone' => $v['company.phone'], 'email' => $v['company.email'],
            'logo_path_absolute' => $logo && is_file($logo) ? $logo : null,
        ];
    }

    public function render(string $view, array $data, string $fileName): PDF
    {
        return Pdf::loadView($view, [...$data, 'company' => $this->company(), 'fileName' => $fileName])->setPaper('a4', 'portrait');
    }

    public function download(string $view, array $data, string $fileName): Response
    {
        return $this->render($view, $data, $fileName)->download($fileName);
    }

    public function stream(string $view, array $data, string $fileName): Response
    {
        return $this->render($view, $data, $fileName)->stream($fileName);
    }

    public function binary(string $view, array $data, string $fileName): string
    {
        return $this->render($view, $data, $fileName)->output();
    }
}
```
`resources/views/pdf/layout.blade.php`:
```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $fileName }}</title>
    <style>
        @page { margin: 18mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; }
        .lines th { background: #eee; border-bottom: 1px solid #999; text-align: left; }
        .lines td { border-bottom: 1px solid #ddd; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #666; }
        .box { border: 1px solid #999; padding: 6px 8px; }
        .totals td { padding: 2px 6px; }
        .totals .grand { font-weight: bold; font-size: 12px; border-top: 1px solid #999; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #666; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header')
    @yield('content')
    <div class="footer">{{ $company['name'] }} — Documento generado por Gomez ERP el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
```
`resources/views/pdf/partials/company-header.blade.php`:
```blade
<table style="margin-bottom: 10px;">
    <tr>
        <td style="width: 60%;">
            @if($company['logo_path_absolute'])<img src="{{ $company['logo_path_absolute'] }}" style="max-height: 50px; margin-bottom: 4px;"><br>@endif
            <strong style="font-size: 13px;">{{ $company['legal_name'] ?: $company['name'] }}</strong><br>
            @if($company['legal_name'] && $company['name'] && $company['legal_name'] !== $company['name'])<span>{{ $company['name'] }}</span><br>@endif
            @if($company['rnc_formatted'])RNC: {{ $company['rnc_formatted'] }}<br>@endif
            @if($company['address']){{ $company['address'] }}<br>@endif
            @if($company['phone'])Tel.: {{ $company['phone'] }}@endif @if($company['email']) · {{ $company['email'] }}@endif
        </td>
        <td style="width: 40%; text-align: right;">@yield('document-box')</td>
    </tr>
</table>
```
`resources/views/pdf/partials/lines-table.blade.php`:
```blade
<table class="lines">
    <thead><tr><th>Descripción</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Desc. %</th><th class="num">ITBIS</th><th class="num">Imp. adic.</th><th class="num">Total</th></tr></thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            <td>{{ $line['description'] }}</td>
            <td class="num">{{ number_format((float) $line['quantity'], 2) }}</td>
            <td class="num">{{ number_format((float) $line['unit_price'], 2) }}</td>
            <td class="num">{{ number_format((float) $line['discount_percent'], 2) }}</td>
            <td class="num">{{ number_format((float) $line['itbis_amount'], 2) }}</td>
            <td class="num">{{ number_format((float) $line['additional_tax_total'], 2) }}</td>
            <td class="num">{{ number_format((float) $line['line_total'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
```
`resources/views/pdf/partials/totals.blade.php`:
```blade
<table class="totals" style="width: 45%; margin-left: 55%; margin-top: 8px;">
    <tr><td>Subtotal</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['subtotal'], $currency) }}</td></tr>
    <tr><td>Descuento</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['discount_total'], $currency) }}</td></tr>
    <tr><td>Base imponible</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['taxable_total'], $currency) }}</td></tr>
    @if((float) $totals['additional_tax_total'] > 0)<tr><td>Impuestos adicionales</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['additional_tax_total'], $currency) }}</td></tr>@endif
    <tr><td>ITBIS</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['itbis_total'], $currency) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['total'], $currency) }}</td></tr>
</table>
```
`resources/views/pdf/probe.blade.php`: `@extends('pdf.layout') @section('document-box')<div class="box"><strong>{{ $title }}</strong></div>@endsection @section('content') @include('pdf.partials.lines-table') @include('pdf.partials.totals') @endsection`.
(`number_format((float) …)` in Blade is display-only formatting of already-rounded server strings; no arithmetic happens in views.)

- [ ] **Step 4: Gate** — `vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (375 tests).

- [ ] **Step 5: Commit** — `feat(documents): add dompdf-based PDF renderer and layout`

---

### Task 7: E-mail infrastructure — Gmail send-as-user, templates, sent-mail log

**Files:**
- Create: migrations `2026_11_01_000009_add_gmail_fields_to_users_table.php`, `2026_11_01_000010_create_email_templates_table.php`, `2026_11_01_000011_create_sent_emails_table.php`; models `EmailTemplate`, `SentEmail`; `database/seeders/EmailTemplateSeeder.php`; `app/Contracts/GmailSender.php`; `app/Support/Mail/OutgoingEmail.php`; `app/Support/Mail/SentEmailResult.php`; `app/Services/Mail/LogGmailSender.php`; `app/Services/Mail/GoogleGmailSender.php`; `app/Services/Mail/EmailTemplateRenderer.php`; `app/Actions/Mail/SendDocumentEmail.php`; `app/Exceptions/GmailNotConnectedException.php`; `app/Http/Controllers/Auth/GmailConnectController.php`; `app/Http/Controllers/Admin/EmailTemplateController.php`; `app/Http/Requests/Admin/UpdateEmailTemplateRequest.php`; `resources/js/pages/admin/email-templates/index.tsx`; `resources/js/components/erp/send-email-dialog.tsx`; `resources/js/types/mail.ts`; `tests/Feature/Mail/GmailSenderTest.php`, `tests/Feature/Mail/EmailTemplateTest.php`, `tests/Feature/Mail/GmailConnectTest.php`
- Modify: `composer.json`, `routes/auth.php`, `routes/admin.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `config/erp.php`, `.env.example`, `database/seeders/DatabaseSeeder.php`, `resources/js/components/user-menu-content.tsx`

**Interfaces:**
- Produces: `OutgoingEmail` readonly (`array $to, array $cc, string $subject, string $bodyHtml, array $attachments /* list<array{name: string, mime: string, content: string}> */`); `SentEmailResult` readonly (`string $messageId, ?string $threadId`); contract `GmailSender::send(User $sender, OutgoingEmail $email): SentEmailResult`; `LogGmailSender` (writes to the `mail` log channel, returns `log-<uuid>`); `GoogleGmailSender` (google/apiclient, refresh-token flow); binding chosen by `config('erp.gmail.driver')` (`log` default in local/testing, `google` otherwise); `EmailTemplateRenderer::render(string $key, array $vars): array{subject: string, body_html: string}` (placeholders `{{cliente}}`, `{{numero}}`, `{{empresa}}`, `{{total}}`, `{{fecha}}`, `{{vendedor}}`, `{{usuario}}`); `SendDocumentEmail::handle(User $sender, Model $document, string $templateKey, array $to, array $cc, array $vars, array $attachments, ?string $customBody = null): SentEmail` (logs `sent_emails` row + `activity('<document table>')->event('emailed')`); routes `auth.gmail.connect` (GET `/auth/gmail/connect`), `auth.gmail.callback` (GET `/auth/gmail/callback`), `auth.gmail.disconnect` (DELETE `/auth/gmail`), `admin.email-templates.index/update`; users columns `gmail_refresh_token` (encrypted), `gmail_connected_at`, `gmail_email`; React `SendEmailDialog` (`{ open, onOpenChange, defaultTo, defaultSubject?, templateKey, submitUrl, attachmentsLabel }`), shared prop `auth.gmail_connected: boolean`.

Template keys and seeded Spanish defaults (`email_templates`): `proforma` — subject `Proforma {{numero}} de {{empresa}}`, body `<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la proforma {{numero}} por {{total}}, válida hasta la fecha indicada en el documento.</p><p>Quedamos atentos a su confirmación.</p><p>{{vendedor}}<br>{{empresa}}</p>`; `invoice` — subject `Factura {{numero}} de {{empresa}}`, body `<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la factura {{numero}} con fecha {{fecha}} por {{total}}.</p><p>Gracias por su preferencia.</p><p>{{vendedor}}<br>{{empresa}}</p>`; `statement` — subject `Estado de cuenta de {{empresa}}`, body `<p>Estimado/a {{cliente}}:</p><p>Adjuntamos su estado de cuenta al {{fecha}}. Saldo pendiente: {{total}}.</p><p>{{usuario}}<br>{{empresa}}</p>`; `credit_note` — subject `Nota de crédito {{numero}} de {{empresa}}`, body `<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la nota de crédito {{numero}} por {{total}}.</p><p>{{usuario}}<br>{{empresa}}</p>`.

- [ ] **Step 1: Install google/apiclient and config**

Run: `composer require "google/apiclient:^2.20" --no-interaction 2>&1 | tail -2 && composer show google/apiclient | grep '^versions'` → `versions : * v2.2<x>`. Add to `config/erp.php`: `'gmail' => ['driver' => env('GMAIL_DRIVER', env('APP_ENV') === 'production' ? 'google' : 'log'), 'scope' => 'https://www.googleapis.com/auth/gmail.send']`. Append `GMAIL_DRIVER=log` to `.env` and `.env.example`. In `AppServiceProvider::register()`: `$this->app->bind(\App\Contracts\GmailSender::class, fn () => config('erp.gmail.driver') === 'google' ? $this->app->make(\App\Services\Mail\GoogleGmailSender::class) : $this->app->make(\App\Services\Mail\LogGmailSender::class));`. Add `'gmail_connected' => $user ? $user->gmail_connected_at !== null : false` and `'gmail_email' => $user?->gmail_email` inside the shared `auth` array of `HandleInertiaRequests`, and `gmail_connected: boolean; gmail_email: string | null;` to the `Auth` TS type (`resources/js/types/auth.ts`). Extend the Phase 1 test `shared props expose roles and permissions` (`tests/Feature/Admin/PermissionCatalogTest.php`) with `->where('auth.gmail_connected', false)`.

- [ ] **Step 2: Failing tests**

`tests/Feature/Mail/GmailSenderTest.php`:
```php
<?php

use App\Actions\Mail\SendDocumentEmail;
use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\Client;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Mail\LogGmailSender;
use App\Support\Mail\OutgoingEmail;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new EmailTemplateSeeder)->run());

test('log driver is bound outside production and returns a message id', function () {
    expect(app(GmailSender::class))->toBeInstanceOf(LogGmailSender::class);
    Log::shouldReceive('channel')->with('mail')->andReturnSelf();
    Log::shouldReceive('info')->once();
    $result = app(GmailSender::class)->send(User::factory()->create(['gmail_connected_at' => now()]), new OutgoingEmail(['a@b.test'], [], 'Asunto', '<p>Hola</p>', []));
    expect($result->messageId)->toStartWith('log-');
});

test('send document email renders the template, logs the sent email and the document activity', function () {
    $sender = User::factory()->create(['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    $client = Client::factory()->create(['business_name' => 'Cliente SRL']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');

    $sent = app(SendDocumentEmail::class)->handle($sender, $client, 'proforma', ['c@x.test'], ['cc@x.test'], ['cliente' => 'Cliente SRL', 'numero' => 'COT-000001', 'empresa' => 'Gomez', 'total' => 'RD$ 100.00', 'fecha' => '29/10/2026', 'vendedor' => 'Ana', 'usuario' => 'Ana'], [['name' => 'COT-000001.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 x']]);

    expect($sent->subject)->toBe('Proforma COT-000001 de Gomez')
        ->and($sent->body_html)->toContain('Estimado/a Cliente SRL')
        ->and($sent->to)->toBe(['c@x.test'])->and($sent->cc)->toBe(['cc@x.test'])->and($sent->attachments)->toBe(['COT-000001.pdf'])
        ->and($sent->status)->toBe('sent')->and($sent->gmail_message_id)->toStartWith('log-')->and($sent->mailable_id)->toBe($client->id)
        ->and(Activity::inLog('clients')->where('event', 'emailed')->where('subject_id', $client->id)->exists())->toBeTrue()
        ->and(SentEmail::count())->toBe(1);
});

test('sending without a connected gmail is refused', function () {
    $sender = User::factory()->create(['gmail_connected_at' => null]);
    expect(fn () => app(SendDocumentEmail::class)->handle($sender, Client::factory()->create(), 'invoice', ['a@b.test'], [], [], []))
        ->toThrow(GmailNotConnectedException::class, 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
});
```
`tests/Feature/Mail/EmailTemplateTest.php`:
```php
<?php

use App\Models\EmailTemplate;
use App\Services\Mail\EmailTemplateRenderer;
use Database\Seeders\EmailTemplateSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new EmailTemplateSeeder)->run());

test('seeder creates the four templates idempotently and the renderer replaces placeholders', function () {
    (new EmailTemplateSeeder)->run();
    expect(EmailTemplate::count())->toBe(4);
    $r = app(EmailTemplateRenderer::class)->render('invoice', ['cliente' => 'ACME', 'numero' => 'FAC-000001', 'empresa' => 'Gomez', 'total' => 'RD$ 1.00', 'fecha' => '01/11/2026', 'vendedor' => 'Ana', 'usuario' => 'Ana']);
    expect($r['subject'])->toBe('Factura FAC-000001 de Gomez')->and($r['body_html'])->toContain('con fecha 01/11/2026 por RD$ 1.00');
});

test('templates page requires settings manage and saves edits with audit', function () {
    actingAsRole('accounting');
    $this->get(route('admin.email-templates.index'))->assertForbidden();
    actingAsRole('owner');
    $this->get(route('admin.email-templates.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/email-templates/index')->has('templates', 4));
    $this->put(route('admin.email-templates.update', 'invoice'), ['subject' => 'Su factura {{numero}}', 'body_html' => '<p>Hola {{cliente}}</p>'])->assertRedirect(route('admin.email-templates.index'))->assertSessionHas('success', 'Plantilla actualizada');
    $this->put(route('admin.email-templates.update', 'invoice'), ['subject' => '', 'body_html' => '<script>x</script>'])->assertSessionHasErrors(['subject', 'body_html']);
    expect(EmailTemplate::where('key', 'invoice')->first()->subject)->toBe('Su factura {{numero}}')
        ->and(Activity::inLog('email_templates')->where('event', 'updated')->exists())->toBeTrue();
});
```
`tests/Feature/Mail/GmailConnectTest.php`:
```php
<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Spatie\Activitylog\Models\Activity;

test('connect flow stores the refresh token encrypted and disconnect clears it', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    $this->actingAs($user);
    Socialite::fake('google');
    $this->get(route('auth.gmail.connect'))->assertRedirect();

    Socialite::fake('google', GoogleUser::fake(['email' => 'ana@empresa.test', 'refreshToken' => 'rt-123', 'approvedScopes' => ['https://www.googleapis.com/auth/gmail.send']]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('success', 'Gmail conectado: ana@empresa.test');
    $user->refresh();
    expect($user->gmail_refresh_token)->toBe('rt-123')->and($user->gmail_email)->toBe('ana@empresa.test')->and($user->gmail_connected_at)->not->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('gmail_refresh_token'))->not->toBe('rt-123')
        ->and(Activity::inLog('auth')->where('event', 'gmail_connected')->where('causer_id', $user->id)->exists())->toBeTrue();

    $this->delete(route('auth.gmail.disconnect'))->assertRedirect()->assertSessionHas('success', 'Gmail desconectado');
    expect($user->fresh()->gmail_refresh_token)->toBeNull()->and($user->fresh()->gmail_connected_at)->toBeNull();
});

test('callback refuses a google account different from the user and a missing send scope', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    $this->actingAs($user);
    Socialite::fake('google', GoogleUser::fake(['email' => 'otra@empresa.test', 'refreshToken' => 'rt', 'approvedScopes' => ['https://www.googleapis.com/auth/gmail.send']]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('error', 'Debes conectar la misma cuenta de Google con la que iniciaste sesión (ana@empresa.test).');
    Socialite::fake('google', GoogleUser::fake(['email' => 'ana@empresa.test', 'refreshToken' => 'rt', 'approvedScopes' => []]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('error', 'Google no concedió el permiso para enviar correos (gmail.send).');
});
```

- [ ] **Step 3: Migrations, models, seeder**

`add_gmail_fields_to_users_table`: `text('gmail_refresh_token')->nullable()`, `string('gmail_email', 255)->nullable()`, `timestamp('gmail_connected_at')->nullable()`. `User`: add the three to `$fillable`, casts `'gmail_refresh_token' => 'encrypted'`, `'gmail_connected_at' => 'datetime'`; add `gmail_refresh_token` to `$hidden`; `LogsModelActivity` must not log the token — alias the trait method and override it:
```php
use CausesActivity, HasFactory, HasRoles, LogsModelActivity {
    LogsModelActivity::getActivitylogOptions as protected baseActivitylogOptions;
}
use Notifiable;

public function getActivitylogOptions(): LogOptions
{
    return $this->baseActivitylogOptions()->logExcept(['password', 'remember_token', 'gmail_refresh_token']);
}
```
(`logExcept` replaces the previous list, hence all three names.) Add to `tests/Feature/Mail/GmailConnectTest.php` first test: `->and(Activity::inLog('users')->where('subject_id', $user->id)->get()->every(fn ($a) => ! str_contains(json_encode($a->properties), 'rt-123')))->toBeTrue()`.
`email_templates`: `id()`, `string('key', 40)->unique()`, `string('subject', 200)`, `text('body_html')`, `timestamps()`. Model `EmailTemplate` (`LogsModelActivity`, fillable three). Seeder `EmailTemplateSeeder`: `firstOrCreate(['key' => …], ['subject' => …, 'body_html' => …])` for the four keys above; add to `DatabaseSeeder`.
`sent_emails`: `id()`, `foreignId('user_id')->constrained('users')->restrictOnDelete()`, `string('mailable_type', 120)`, `unsignedBigInteger('mailable_id')`, `index(['mailable_type', 'mailable_id'])`, `string('template_key', 40)->nullable()`, `jsonb('to')`, `jsonb('cc')->nullable()`, `string('subject', 200)`, `text('body_html')`, `jsonb('attachments')->nullable()`, `string('gmail_message_id', 120)->nullable()`, `string('gmail_thread_id', 120)->nullable()`, `string('status', 10)`, `text('error')->nullable()`, `timestamp('sent_at')->nullable()`, `timestamps()`. Model `SentEmail` (`LogsModelActivity`; casts `to`, `cc`, `attachments` → array, `sent_at` datetime; `mailable(): MorphTo`, `user()`).

- [ ] **Step 4: Contract, value objects, senders, renderer, action**

`app/Support/Mail/OutgoingEmail.php`: `final readonly class OutgoingEmail { /** @param list<string> $to @param list<string> $cc @param list<array{name: string, mime: string, content: string}> $attachments */ public function __construct(public array $to, public array $cc, public string $subject, public string $bodyHtml, public array $attachments = []) {} }`.
`app/Support/Mail/SentEmailResult.php`: `final readonly class SentEmailResult { public function __construct(public string $messageId, public ?string $threadId = null) {} }`.
`app/Contracts/GmailSender.php`: `interface GmailSender { public function send(User $sender, OutgoingEmail $email): SentEmailResult; }`.
`app/Exceptions/GmailNotConnectedException.php`: `class GmailNotConnectedException extends \RuntimeException { public function __construct() { parent::__construct('Conecta tu cuenta de Gmail para enviar correos desde el sistema.'); } }`.
`app/Services/Mail/LogGmailSender.php`:
```php
<?php

namespace App\Services\Mail;

use App\Contracts\GmailSender;
use App\Models\User;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogGmailSender implements GmailSender
{
    public function send(User $sender, OutgoingEmail $email): SentEmailResult
    {
        $id = 'log-'.Str::uuid();
        Log::channel('mail')->info('Gmail (log driver) message', ['id' => $id, 'from' => $sender->gmail_email ?? $sender->email, 'to' => $email->to, 'cc' => $email->cc, 'subject' => $email->subject, 'attachments' => array_column($email->attachments, 'name')]);

        return new SentEmailResult($id, null);
    }
}
```
Add a `mail` channel to `config/logging.php` channels: `'mail' => ['driver' => 'single', 'path' => storage_path('logs/mail.log'), 'level' => 'info']`.
`app/Services/Mail/GoogleGmailSender.php`:
```php
<?php

namespace App\Services\Mail;

use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\User;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class GoogleGmailSender implements GmailSender
{
    public function send(User $sender, OutgoingEmail $email): SentEmailResult
    {
        if ($sender->gmail_refresh_token === null) {
            throw new GmailNotConnectedException;
        }
        $client = new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setAccessType('offline');
        $token = $client->fetchAccessTokenWithRefreshToken($sender->gmail_refresh_token);
        if (isset($token['error'])) {
            throw new \RuntimeException('Google rechazó el token de Gmail: '.$token['error']);
        }
        $client->setAccessToken($token);

        $mime = (new Email)->from(new Address($sender->gmail_email ?? $sender->email, $sender->name))->to(...$email->to)->subject($email->subject)->html($email->bodyHtml);
        if ($email->cc !== []) {
            $mime->cc(...$email->cc);
        }
        foreach ($email->attachments as $attachment) {
            $mime->attach($attachment['content'], $attachment['name'], $attachment['mime']);
        }
        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($mime->toString()), '+/', '-_'), '='));
        $sent = (new Gmail($client))->users_messages->send('me', $message);

        return new SentEmailResult((string) $sent->getId(), $sent->getThreadId());
    }
}
```
`app/Services/Mail/EmailTemplateRenderer.php`: `render(string $key, array $vars): array` → loads `EmailTemplate::where('key', $key)->firstOrFail()`; replaces every `{{name}}` (regex `/\{\{\s*([a-z_]+)\s*\}\}/`) with `e($vars[$name] ?? '')` in both subject (no HTML escaping needed but strip tags) and body; returns `['subject' => …, 'body_html' => …]`.
`app/Actions/Mail/SendDocumentEmail.php`:
```php
<?php

namespace App\Actions\Mail;

use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Mail\EmailTemplateRenderer;
use App\Support\Mail\OutgoingEmail;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SendDocumentEmail
{
    public function __construct(private readonly GmailSender $sender, private readonly EmailTemplateRenderer $renderer) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  array<string, string>  $vars
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     */
    public function handle(User $user, Model $document, string $templateKey, array $to, array $cc, array $vars, array $attachments, ?string $customBody = null, ?string $customSubject = null): SentEmail
    {
        if ($user->gmail_connected_at === null) {
            throw new GmailNotConnectedException;
        }
        $rendered = $this->renderer->render($templateKey, $vars);
        $subject = $customSubject ?: $rendered['subject'];
        $body = $customBody ?: $rendered['body_html'];
        $record = SentEmail::create([
            'user_id' => $user->id, 'mailable_type' => $document::class, 'mailable_id' => $document->getKey(), 'template_key' => $templateKey,
            'to' => $to, 'cc' => $cc, 'subject' => $subject, 'body_html' => $body, 'attachments' => array_column($attachments, 'name'), 'status' => 'pending',
        ]);
        try {
            $result = $this->sender->send($user, new OutgoingEmail($to, $cc, $subject, $body, $attachments));
            $record->update(['status' => 'sent', 'gmail_message_id' => $result->messageId, 'gmail_thread_id' => $result->threadId, 'sent_at' => now()]);
            activity($document->getTable())->causedBy($user)->performedOn($document)->event('emailed')->withProperties(['to' => $to, 'cc' => $cc, 'subject' => $subject, 'sent_email_id' => $record->id])->log('Enviado por correo a '.implode(', ', $to));
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error' => $e->getMessage()]);
            activity($document->getTable())->causedBy($user)->performedOn($document)->event('email_failed')->withProperties(['to' => $to, 'error' => $e->getMessage()])->log('Falló el envío por correo');
            throw $e;
        }

        return $record->fresh();
    }
}
```

- [ ] **Step 5: Gmail connect routes and controller, templates admin**

`routes/auth.php` (auth middleware): `Route::get('auth/gmail/connect', [GmailConnectController::class, 'connect'])->name('auth.gmail.connect'); Route::get('auth/gmail/callback', [GmailConnectController::class, 'callback'])->name('auth.gmail.callback'); Route::delete('auth/gmail', [GmailConnectController::class, 'disconnect'])->name('auth.gmail.disconnect');`
`GmailConnectController`: `connect()` → `/** @var \Laravel\Socialite\Two\GoogleProvider $driver */ $driver = Socialite::driver('google'); return $driver->scopes([config('erp.gmail.scope')])->with(['access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $request->user()->email])->redirectUrl(route('auth.gmail.callback'))->redirect();` (the `@var` is the Phase 1 PHPStan ruling for Socialite); `callback()` → same `@var` then `$g = $driver->redirectUrl(route('auth.gmail.callback'))->user(); $raw = $g instanceof \Laravel\Socialite\Two\User ? $g : null;` — if `$raw === null` → error `Google no devolvió un token de acceso persistente; vuelve a intentarlo.`; read `$raw->refreshToken` and `$raw->approvedScopes`; if `strtolower($g->getEmail()) !== strtolower($request->user()->email)` → error `Debes conectar la misma cuenta de Google con la que iniciaste sesión ({email}).`; if `! in_array(config('erp.gmail.scope'), $g->approvedScopes ?? [], true)` → error `Google no concedió el permiso para enviar correos (gmail.send).`; if `$g->refreshToken` empty → error `Google no devolvió un token de acceso persistente; vuelve a intentarlo.`; else `forceFill(['gmail_refresh_token' => $g->refreshToken, 'gmail_email' => strtolower($g->getEmail()), 'gmail_connected_at' => now()])->save()`, `activity('auth')->causedBy($user)->event('gmail_connected')->log('Gmail conectado')`, redirect dashboard `'Gmail conectado: {email}'`; `disconnect()` → null the three columns, `activity('auth')…event('gmail_disconnected')`, back with `'Gmail desconectado'`.
`user-menu-content.tsx`: add item "Conectar Gmail" (`<a href={connect.url()}>`) when `!auth.gmail_connected`, or "Desconectar Gmail" (`router.delete(disconnect.url())`) when connected.
Templates admin: routes `Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index'); Route::put('email-templates/{emailTemplate:key}', [EmailTemplateController::class, 'update'])->name('email-templates.update');` both `permission:admin.settings.manage`. `UpdateEmailTemplateRequest` rules: `'subject' => ['required', 'string', 'max:200'], 'body_html' => ['required', 'string', 'max:20000', 'regex:/^(?!.*<script).*$/is']` with message `body_html.regex` → `La plantilla no puede contener scripts.`. Controller `index` → `templates` (`key, subject, body_html`) + `placeholders` list (`cliente, numero, empresa, total, fecha, vendedor, usuario`); `update` → save → `'Plantilla actualizada'`. Page `admin/email-templates/index.tsx` (title "Plantillas de correo"): one `Card` per template (title = key label: Proforma, Factura, Estado de cuenta, Nota de crédito) with `<Form {...update.form(key)}>`: `subject` Input "Asunto", `body_html` Textarea "Cuerpo (HTML)" rows 8, hint listing the placeholders, button "Guardar".
`resources/js/components/erp/send-email-dialog.tsx`: `Dialog` "Enviar por correo" with fields Para (`Input` comma-separated, default `defaultTo.join(', ')`), CC, Asunto (prefilled from a `GET` of the rendered template? no — leave empty = use template), Mensaje (`Textarea`, empty = template), note "Se enviará desde tu Gmail ({gmail_email}) con el PDF adjunto"; when `!auth.gmail_connected` show instead an `Alert` "Conecta tu cuenta de Gmail para enviar correos" with a link to `connect.url()`; submit → `router.post(submitUrl, { to, cc, subject, body })`.

- [ ] **Step 6: Migrate, seed, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan db:seed --class=EmailTemplateSeeder --no-interaction && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (382 tests).

- [ ] **Step 7: Commit** — `feat(mail): add Gmail send-as-user, email templates and sent-mail log`

---

### Task 8: Proformas — shared sales-document infrastructure, CRUD, transitions, duplicate, PDF, e-mail, expiry job

**Files:**
- Create: migrations `2026_11_01_000012_create_proformas_table.php`, `2026_11_01_000013_create_proforma_lines_table.php`; `app/Support/Documents/DocumentSchema.php`; `app/Enums/ProformaStatus.php`; `app/Models/Proforma.php`, `app/Models/ProformaLine.php`, `database/factories/ProformaFactory.php`; `app/Models/Concerns/VisibleToSalesperson.php`; `app/Policies/SalesDocumentPolicy.php`, `app/Policies/ProformaPolicy.php`; `app/Exceptions/DocumentStateException.php`; `app/Services/Documents/SalespersonResolver.php`, `app/Services/Documents/DocumentCurrency.php`, `app/Services/Documents/DocumentLines.php`; `app/Http/Requests/Sales/Concerns/ValidatesDocumentLines.php`, `app/Http/Requests/Sales/StoreProformaRequest.php`, `app/Http/Requests/Sales/TransitionNoteRequest.php`, `app/Http/Requests/Sales/SendDocumentEmailRequest.php`; `app/Actions/Sales/SaveProforma.php`, `app/Actions/Sales/TransitionProforma.php`, `app/Actions/Sales/DuplicateProforma.php`; `app/Jobs/ExpireProformas.php`; `app/Http/Controllers/Sales/ProformaController.php`, `app/Http/Controllers/Catalog/ClientSearchController.php`; `resources/views/pdf/sales/proforma.blade.php`, `resources/views/pdf/partials/client-block.blade.php`; `resources/js/components/erp/status-badge.tsx`, `resources/js/components/erp/client-picker.tsx`, `resources/js/components/erp/document-header.tsx`; `resources/js/pages/sales/proformas/{index,form,show}.tsx`; `tests/Feature/Sales/ProformaTest.php`, `tests/Feature/Catalog/ClientSearchTest.php`
- Modify: `routes/sales.php`, `routes/catalog.php`, `routes/console.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php` (policy map), `resources/js/types/sales.ts`, `tests/Support/helpers.php`, `lang/es/validation.php` (attributes)

**Interfaces:**
- Consumes: Task 1 (`DocumentNumbering`, `DocumentKind`), Task 2 (`DocumentTotalsCalculator`, `LineInput`, `ComputedTotals`, `DocumentLinesEditor`, `ProductPicker`, `useTotalsPreview`), Task 6 (`DocumentPdf`), Task 7 (`SendDocumentEmail`, `SendEmailDialog`), Phase 1 `ExchangeRateService::rateFor`, `Client::visibleTo`, `AuditHistory::for`, `TableQuery`, `TableExport`.
- Produces (reused by Tasks 9–16; every method of `DocumentSchema`, `SalespersonResolver`, `DocumentCurrency` and `DocumentLines` is `public static`):
  - `DocumentSchema::totals(Blueprint $t): void` adds `decimal('subtotal', 14, 2)`, `discount_total`, `taxable_total`, `itbis_total`, `additional_tax_total`, `total`, `total_base` (all `decimal(14,2)->default(0)`); `DocumentSchema::lineAmounts(Blueprint $t): void` adds `unsignedSmallInteger('line_number')`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `string('description', 200)`, `decimal('quantity', 14, 4)`, `decimal('unit_price', 14, 4)`, `decimal('discount_percent', 7, 4)->default(0)`, `decimal('line_subtotal', 14, 2)`, `decimal('discount_amount', 14, 2)`, `decimal('taxable_base', 14, 2)`, `decimal('itbis_rate', 7, 2)`, `decimal('itbis_amount', 14, 2)`, `jsonb('additional_taxes')`, `decimal('additional_tax_total', 14, 2)`, `decimal('line_total', 14, 2)`.
  - trait `VisibleToSalesperson` → `scopeVisibleTo(Builder $query, User $user): Builder` (model declares `public const VIEW_ALL_PERMISSION = 'sales.<resource>.view-all'`; without it the user sees rows whose `salesperson_id` equals `$user->salesperson?->id`, else none).
  - `abstract class SalesDocumentPolicy { abstract protected function viewAllPermission(): string; public function view(User $user, Model $document): bool; public function update(User $user, Model $document): bool; public function delete(User $user, Model $document): bool; }` — all three return `$user->can($this->viewAllPermission()) || ($user->salesperson !== null && $user->salesperson->id === $document->getAttribute('salesperson_id'))`.
  - `DocumentStateException extends \RuntimeException` — rendered by `bootstrap/app.php` as `back()->with('error', $e->getMessage())` (same rendering for `InventoryException`, `FiscalNumberingException`, `ImmutableDocumentException`, `GmailNotConnectedException`, `MissingExchangeRateException`).
  - `SalespersonResolver::resolve(User $user, ?int $requested, string $viewAllPermission): int` — user with the permission: returns `$requested` (throws `ValidationException` `salesperson_id` → `Selecciona el vendedor.` when null); without it: ignores `$requested`, returns the user's own salesperson id or throws `ValidationException` `salesperson_id` → `Tu usuario no está vinculado a un vendedor.`
  - `DocumentCurrency::resolve(int $currencyId, CarbonInterface $date): array{currency: Currency, exchange_rate: string}` — base currency → `'1.000000'`; else `ExchangeRateService::rateFor()->rate` (6 dp) or `ValidationException` `currency_id` → `No hay tasa de cambio registrada para {CODE} en {dd/mm/YYYY} ni fechas anteriores.`
  - `DocumentLines::write(Model $document, ComputedTotals $computed, array $extraPerLine = []): void` (deletes `$document->lines()` then creates one row per computed line with `line_number` = index + 1, the `ComputedLine::toArray()` keys and `$extraPerLine[$i]` merged) and `DocumentLines::totals(ComputedTotals $computed, string $exchangeRate): array` (`subtotal, discount_total, taxable_total, itbis_total, additional_tax_total, total, total_base = Decimal::mul(total, exchange_rate, 2)`).
  - trait `ValidatesDocumentLines` → `protected function lineRules(int $max = 200): array` (`lines` required array min:1 max:$max; per line the Task 2 preview rules but `product_id` uses `Rule::exists('products', 'id')->where('is_active', true)`) and `protected function lineMessages(): array` (`lines.required` → `Agrega al menos una línea.`, `lines.*.product_id.exists` → `El producto no existe o está inactivo.`, `lines.*.quantity.gt` → `La cantidad debe ser mayor que cero.`).
  - `TransitionNoteRequest` (`note` nullable string max 255) and `SendDocumentEmailRequest` (`to` required array min:1 of `email`, `cc` nullable array of `email`, `subject` nullable string max 200, `body` nullable string max 20000 without `<script`) with messages `to.required` → `Indica al menos un destinatario.`
  - routes `catalog.clients.search` (GET `/catalog/clients/search?q=`, permission `catalog.clients.view`, scoped with `Client::visibleTo`, active only, returns `data: [{id, code, business_name, trade_name, tax_id, formatted_tax_id, currency_code, price_list_id, credit_days, email, salesperson_id}]`, limit 20).
  - React: `StatusBadge({ status, label })` (variants: draft→`secondary`; sent/pending/partially_paid→`outline`; approved/issued/confirmed/posted/paid/invoiced→`default`; rejected/expired/cancelled→`destructive`), `ClientPicker({ value, onPick, disabled })` (Dialog "Buscar cliente", search input placeholder "Código, razón social o RNC", table Código/Razón social/RNC; shows the picked client's name in the trigger button, "Seleccionar cliente" when none), `DocumentHeader({ title, number, status, statusLabel, meta: {label, value}[], actions })` (card with title + number + `StatusBadge` + a 2-column dl of meta + actions slot). TS types in `resources/js/types/sales.ts`: `PickedClient`, `Proforma`, `ProformaLine`, `DocumentTotals`.
  - helper `validProformaPayload(array $over = []): array` and `salesUserWithSalesperson(): array{user: User, salesperson: Salesperson}` in `tests/Support/helpers.php`.

Proforma rules (spec §3.3): statuses `draft → sent → approved | rejected`, `sent → expired` (job), `approved → invoiced` (Task 9). `approve`/`reject` are allowed from `draft` or `sent`; `mark-sent` only from `draft`; edit/delete only `draft`. Number `COT-000001` assigned at creation. Prices come from the client's price list (picker); the server recomputes everything. Currency = client's preferred currency by default; USD proformas store the rate of `issue_date`.

- [ ] **Step 1: Helpers** — append to `tests/Support/helpers.php`:
```php
/** A sales-role user linked to a salesperson (the shape every scoped-document test needs). */
function salesUserWithSalesperson(): array
{
    $user = actingAsRole('sales');
    $salesperson = \App\Models\Salesperson::factory()->create(['user_id' => $user->id]);

    return ['user' => $user, 'salesperson' => $salesperson];
}

function validProformaPayload(array $over = []): array
{
    $client = \App\Models\Client::factory()->create();
    $product = \App\Models\Product::factory()->create(['itbis_rate' => '18.00']);

    return array_merge([
        'client_id' => $client->id, 'salesperson_id' => '', 'currency_id' => \App\Models\Currency::base()->id, 'issue_date' => now()->toDateString(),
        'valid_until' => now()->addDays(15)->toDateString(), 'price_list_id' => '', 'notes' => '',
        'lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']],
    ], $over);
}
```

- [ ] **Step 2: Failing tests**

`tests/Feature/Sales/ProformaTest.php`:
```php
<?php

use App\Enums\ProformaStatus;
use App\Jobs\ExpireProformas;
use App\Models\Client;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Salesperson;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
});

test('a sales user creates a proforma for an own client with server-computed totals and a number', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $client = Client::factory()->create(['salesperson_id' => $sp->id]);
    $other = Salesperson::factory()->create();

    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $client->id, 'salesperson_id' => $other->id]))
        ->assertRedirect()->assertSessionHas('success', 'Proforma COT-000001 creada');

    $p = Proforma::firstOrFail();
    expect($p->number)->toBe('COT-000001')->and($p->status)->toBe(ProformaStatus::Draft)->and($p->salesperson_id)->toBe($sp->id)
        ->and($p->subtotal)->toBe('200.00')->and($p->itbis_total)->toBe('36.00')->and($p->total)->toBe('236.00')->and($p->total_base)->toBe('236.00')
        ->and($p->exchange_rate)->toBe('1.000000')->and($p->lines()->count())->toBe(1)->and($p->lines->first()->line_total)->toBe('236.00')
        ->and(Activity::inLog('proformas')->where('event', 'created')->where('subject_id', $p->id)->exists())->toBeTrue()
        ->and(Activity::inLog('proforma_lines')->where('event', 'created')->count())->toBe(1);
});

test('inactive client, product or salesperson and foreign clients are rejected', function () {
    actingAsRole('owner');
    $inactiveClient = Client::factory()->create(['is_active' => false]);
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    $inactiveSp = Salesperson::factory()->create(['is_active' => false]);
    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $inactiveClient->id, 'salesperson_id' => $inactiveSp->id, 'lines' => [['product_id' => $inactiveProduct->id, 'quantity' => '1', 'unit_price' => '1', 'discount_percent' => '0', 'description' => '']]]))
        ->assertSessionHasErrors(['client_id' => 'El cliente no existe o está inactivo.', 'salesperson_id' => 'El vendedor no existe o está inactivo.', 'lines.0.product_id' => 'El producto no existe o está inactivo.']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => '']))->assertSessionHasErrors(['salesperson_id' => 'Selecciona el vendedor.']);

    salesUserWithSalesperson();
    $foreign = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $foreign->id]))->assertSessionHasErrors(['client_id' => 'No puedes crear documentos para clientes de otros vendedores.']);
    actingAsRole('sales');
    $this->post(route('sales.proformas.store'), validProformaPayload())->assertSessionHasErrors(['salesperson_id' => 'Tu usuario no está vinculado a un vendedor.']);
});

test('users without view-all only see and open their own proformas', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    $own = Proforma::factory()->create(['salesperson_id' => $mine->id]);
    $foreign = Proforma::factory()->create();

    $this->get(route('sales.proformas.index'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/proformas/index')->has('rows.data', 1)->where('rows.data.0.id', $own->id));
    $this->get(route('sales.proformas.show', $foreign))->assertForbidden();
    $this->get(route('sales.proformas.show', $own))->assertOk()->assertInertia(fn ($p) => $p->component('sales/proformas/show')->where('proforma.number', $own->number)->has('history'));
    actingAsRole('management');
    $this->get(route('sales.proformas.index'))->assertInertia(fn ($p) => $p->has('rows.data', 2));
});

test('validation: dates, lines and numeric bounds', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'valid_until' => now()->subDay()->toDateString(), 'lines' => []]))
        ->assertSessionHasErrors(['valid_until', 'lines' => 'Agrega al menos una línea.']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '0', 'unit_price' => '99999999999', 'discount_percent' => '101', 'description' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'La cantidad debe ser mayor que cero.', 'lines.0.unit_price', 'lines.0.discount_percent']);
});

test('usd proformas store the exchange rate of the issue date and total_base in DOP', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $usd = Currency::where('code', 'USD')->firstOrFail();
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'currency_id' => $usd->id]))
        ->assertSessionHasErrors(['currency_id' => 'No hay tasa de cambio registrada para USD en '.now()->format('d/m/Y').' ni fechas anteriores.']);
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->subDays(2)->toDateString(), 'rate' => '60.500000']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'currency_id' => $usd->id]))->assertRedirect();
    $p = Proforma::firstOrFail();
    expect($p->exchange_rate)->toBe('60.500000')->and($p->total)->toBe('236.00')->and($p->total_base)->toBe('14278.00');
});

test('only drafts can be edited or deleted', function () {
    actingAsRole('owner');
    $draft = Proforma::factory()->create();
    $sent = Proforma::factory()->sent()->create();
    $payload = validProformaPayload(['salesperson_id' => $draft->salesperson_id, 'client_id' => $draft->client_id]);

    $this->put(route('sales.proformas.update', $draft), $payload)->assertRedirect(route('sales.proformas.show', $draft))->assertSessionHas('success', "Proforma {$draft->number} actualizada");
    expect($draft->fresh()->lines()->count())->toBe(1)->and($draft->fresh()->total)->toBe('236.00');
    $this->put(route('sales.proformas.update', $sent), $payload)->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar proformas en borrador');
    $this->get(route('sales.proformas.edit', $sent))->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar proformas en borrador');
    $this->delete(route('sales.proformas.destroy', $sent))->assertRedirect()->assertSessionHas('error', 'Solo se pueden eliminar proformas en borrador');
    $this->delete(route('sales.proformas.destroy', $draft))->assertRedirect(route('sales.proformas.index'))->assertSessionHas('success', "Proforma {$draft->number} eliminada");
    expect(Proforma::count())->toBe(1);
});

test('transitions mark sent, approve and reject with audit rows and refuse invalid states', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->create();
    $this->post(route('sales.proformas.approve', $p), ['note' => 'Aprobada por correo del 01/11'])->assertRedirect()->assertSessionHas('success', "Proforma {$p->number} aprobada");
    expect($p->fresh()->status)->toBe(ProformaStatus::Approved)->and($p->fresh()->approved_by)->toBe($user->id)->and($p->fresh()->approval_note)->toBe('Aprobada por correo del 01/11')
        ->and(Activity::inLog('proformas')->where('event', 'approved')->where('subject_id', $p->id)->where('causer_id', $user->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.reject', $p), ['note' => 'x'])->assertRedirect()->assertSessionHas('error', 'La proforma está Aprobada y no admite esta acción');

    $q = Proforma::factory()->create();
    $this->post(route('sales.proformas.mark-sent', $q))->assertRedirect()->assertSessionHas('success', "Proforma {$q->number} marcada como enviada");
    expect($q->fresh()->status)->toBe(ProformaStatus::Sent)->and($q->fresh()->sent_at)->not->toBeNull();
    $this->post(route('sales.proformas.mark-sent', $q))->assertSessionHas('error', 'La proforma está Enviada y no admite esta acción');
    $this->post(route('sales.proformas.reject', $q), ['note' => 'Precio alto'])->assertSessionHas('success', "Proforma {$q->number} rechazada");
    expect($q->fresh()->status)->toBe(ProformaStatus::Rejected)->and($q->fresh()->rejection_note)->toBe('Precio alto')
        ->and(Activity::inLog('proformas')->where('event', 'rejected')->where('subject_id', $q->id)->exists())->toBeTrue();

    actingAsRole('sales');
    $this->post(route('sales.proformas.approve', Proforma::factory()->create()))->assertForbidden();
});

test('duplicate creates a new draft with the same lines and today dates', function () {
    actingAsRole('owner');
    $src = Proforma::factory()->sent()->withLines(2)->create(['issue_date' => '2026-01-10', 'valid_until' => '2026-01-20']);
    $response = $this->post(route('sales.proformas.duplicate', $src));
    $copy = Proforma::latest('id')->firstOrFail();
    $response->assertRedirect(route('sales.proformas.edit', $copy))->assertSessionHas('success', 'Proforma COT-000001 creada a partir de '.$src->number); // factory numbers are random; the sequence starts at 000001
    expect($copy->status)->toBe(ProformaStatus::Draft)->and($copy->lines()->count())->toBe(2)->and($copy->issue_date->toDateString())->toBe(now()->toDateString())
        ->and($copy->total)->toBe($src->total)->and($copy->client_id)->toBe($src->client_id)
        ->and(Activity::inLog('proformas')->where('event', 'duplicated')->where('subject_id', $copy->id)->exists())->toBeTrue();
});

test('pdf renders and sending by email attaches it, marks the draft as sent and logs the email', function () {
    $user = actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $p = Proforma::factory()->withLines(1)->create();

    $this->get(route('sales.proformas.pdf', $p))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->post(route('sales.proformas.send', $p), ['to' => ['cliente@x.test'], 'cc' => [], 'subject' => '', 'body' => ''])
        ->assertRedirect()->assertSessionHas('success', 'Correo enviado a cliente@x.test');
    expect($p->fresh()->status)->toBe(ProformaStatus::Sent)
        ->and(\App\Models\SentEmail::where('mailable_type', Proforma::class)->where('mailable_id', $p->id)->first()->attachments)->toBe([$p->number.'.pdf'])
        ->and(Activity::inLog('proformas')->where('event', 'emailed')->where('subject_id', $p->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.send', $p), ['to' => [], 'cc' => []])->assertSessionHasErrors(['to' => 'Indica al menos un destinatario.']);
    actingAsRole('owner', ['gmail_connected_at' => null]);
    $this->post(route('sales.proformas.send', $p), ['to' => ['a@b.test']])->assertRedirect()->assertSessionHas('error', 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
});

test('the expiry job expires sent proformas past their validity and logs each', function () {
    $old = Proforma::factory()->sent()->create(['valid_until' => now()->subDay()->toDateString()]);
    $fresh = Proforma::factory()->sent()->create(['valid_until' => now()->toDateString()]);
    $draft = Proforma::factory()->create(['valid_until' => now()->subDay()->toDateString()]);
    (new ExpireProformas)->handle();
    expect($old->fresh()->status)->toBe(ProformaStatus::Expired)->and($fresh->fresh()->status)->toBe(ProformaStatus::Sent)->and($draft->fresh()->status)->toBe(ProformaStatus::Draft)
        ->and(Activity::inLog('proformas')->where('event', 'expired')->where('subject_id', $old->id)->exists())->toBeTrue();
});

test('index filters by status and export is logged and scoped', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    Proforma::factory()->create(['salesperson_id' => $mine->id]);
    Proforma::factory()->sent()->create(['salesperson_id' => $mine->id]);
    Proforma::factory()->create();
    $this->get(route('sales.proformas.index', ['status' => 'sent']))->assertInertia(fn ($p) => $p->has('rows.data', 1));
    $this->get(route('sales.proformas.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['row_count'])->toBe(2);
});
```
`tests/Feature/Catalog/ClientSearchTest.php`:
```php
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
```

- [ ] **Step 3: Schema helper, migrations, enum, models, factory**

`app/Support/Documents/DocumentSchema.php`: `final class DocumentSchema { public static function totals(Blueprint $table): void {...} public static function lineAmounts(Blueprint $table): void {...} }` with the columns from Interfaces.
`proformas` (000012): `id()`, `string('number', 12)->unique()`, `string('status', 20)->index()`, `foreignId('client_id')->constrained('clients')->restrictOnDelete()`, `foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete()`, `foreignId('currency_id')->constrained('currencies')->restrictOnDelete()`, `decimal('exchange_rate', 12, 6)`, `date('issue_date')->index()`, `date('valid_until')`, `foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete()`, `DocumentSchema::totals($table)`, `text('notes')->nullable()`, `timestamp('sent_at')->nullable()`, `timestamp('approved_at')->nullable()`, `foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()`, `string('approval_note', 255)->nullable()`, `timestamp('rejected_at')->nullable()`, `string('rejection_note', 255)->nullable()`, `timestamp('invoiced_at')->nullable()`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`.
`proforma_lines` (000013): `id()`, `foreignId('proforma_id')->constrained('proformas')->cascadeOnDelete()`, `DocumentSchema::lineAmounts($table)`, `decimal('invoiced_quantity', 14, 4)->default(0)`, `timestamps()`, `unique(['proforma_id', 'line_number'])`.
`app/Enums/ProformaStatus.php`: `enum ProformaStatus: string { case Draft = 'draft'; case Sent = 'sent'; case Approved = 'approved'; case Rejected = 'rejected'; case Expired = 'expired'; case Invoiced = 'invoiced'; }` with `label()` → `Borrador`, `Enviada`, `Aprobada`, `Rechazada`, `Vencida`, `Facturada`.
`Proforma`: `use HasFactory, LogsModelActivity, VisibleToSalesperson;` `public const VIEW_ALL_PERMISSION = 'sales.proformas.view-all';` fillable all except id/timestamps; casts: `status` → enum, `exchange_rate` → `decimal:6`, `issue_date`/`valid_until` → `date:Y-m-d`, the seven totals → `decimal:2`, `sent_at/approved_at/rejected_at/invoiced_at` → datetime; relations `client()`, `salesperson()`, `currency()`, `priceList()`, `approver()` (`approved_by`), `creator()` (`created_by`), `lines(): HasMany` ordered by `line_number`; `public function isDraft(): bool`.
`ProformaLine`: `use LogsModelActivity;` fillable all; casts `quantity/unit_price` → `decimal:4`, `discount_percent` → `decimal:4`, `itbis_rate` → `decimal:2`, money → `decimal:2`, `additional_taxes` → array, `invoiced_quantity` → `decimal:4`; `proforma()`, `product()`.
`ProformaFactory::definition()`: `['number' => 'COT-'.str_pad((string) fake()->unique()->numberBetween(1, 899999), 6, '0', STR_PAD_LEFT), 'status' => 'draft', 'client_id' => Client::factory(), 'salesperson_id' => Salesperson::factory(), 'currency_id' => fn () => Currency::query()->where('is_base', true)->value('id') ?? Currency::factory()->create(['code' => 'DOP', 'is_base' => true])->id, 'exchange_rate' => '1.000000', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(), 'subtotal' => '0', 'discount_total' => '0', 'taxable_total' => '0', 'itbis_total' => '0', 'additional_tax_total' => '0', 'total' => '0', 'total_base' => '0', 'created_by' => User::factory()]`; states `sent()` (`status sent`, `sent_at now`), `approved()` (`status approved`, `approved_at now`), `withLines(int $n)` → `afterCreating`: for `$n` products (`itbis_rate 18.00`) builds `LineInput($product->id, '1', '100')`, computes with `DocumentTotalsCalculator`, calls `DocumentLines::write($proforma, $computed)` and `$proforma->forceFill(DocumentLines::totals($computed, $proforma->exchange_rate))->save()`.

- [ ] **Step 4: Shared services, trait, policy, exception rendering**

`VisibleToSalesperson`:
```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if ($user->can(static::VIEW_ALL_PERMISSION)) {
        return $query;
    }

    return $query->where($query->getModel()->getTable().'.salesperson_id', $user->salesperson instanceof Salesperson ? $user->salesperson->id : -1);
}
```
`SalesDocumentPolicy`, `ProformaPolicy` (`viewAllPermission()` → `'sales.proformas.view-all'`), `DocumentStateException`, `SalespersonResolver`, `DocumentCurrency`, `DocumentLines`, `ValidatesDocumentLines` exactly as Interfaces describe. Register policies in `AppServiceProvider::boot`: `Gate::policy(Proforma::class, ProformaPolicy::class);` (Phase 1 relies on auto-discovery for `ClientPolicy`; explicit registration is fine for both).
`bootstrap/app.php` `withExceptions`: add
```php
$exceptions->render(function (\RuntimeException $e, Request $request) {
    $flashable = [\App\Exceptions\DocumentStateException::class, \App\Exceptions\InventoryException::class, \App\Exceptions\FiscalNumberingException::class, \App\Exceptions\ImmutableDocumentException::class, \App\Exceptions\GmailNotConnectedException::class, \App\Exceptions\MissingExchangeRateException::class];
    foreach ($flashable as $class) {
        if ($e instanceof $class && ! $request->expectsJson()) {
            return back()->with('error', $e->getMessage());
        }
    }

    return null;
});
```
(All six already extend `\RuntimeException`; `MissingExchangeRateException` was verified on 2026-10-30.)

- [ ] **Step 5: Requests, actions, job**

`StoreProformaRequest` (`use ValidatesDocumentLines`; `prepareForValidation`: `''` → `null` for `salesperson_id`, `price_list_id`, `notes`): rules `client_id` → `['required', 'integer', Rule::exists('clients', 'id')->where('is_active', true)]`, `salesperson_id` → `['nullable', 'integer', Rule::exists('salespeople', 'id')->where('is_active', true)]`, `currency_id` → `['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)]`, `issue_date` → `['required', 'date_format:Y-m-d']`, `valid_until` → `['required', 'date_format:Y-m-d', 'after_or_equal:issue_date']`, `price_list_id` → `['nullable', 'integer', Rule::exists('price_lists', 'id')->where('is_active', true)]`, `notes` → `['nullable', 'string', 'max:2000']`, `...$this->lineRules()`; messages: `client_id.exists` → `El cliente no existe o está inactivo.`, `salesperson_id.exists` → `El vendedor no existe o está inactivo.`, `currency_id.exists` → `La moneda no existe o está inactiva.`, `price_list_id.exists` → `La lista de precios no existe o está inactiva.`, `valid_until.after_or_equal` → `La validez no puede ser anterior a la fecha de emisión.`, `...$this->lineMessages()`; `withValidator` → `after`: when `client_id` valid and `! Client::query()->visibleTo($this->user())->whereKey($this->integer('client_id'))->exists()` → `client_id`: `No puedes crear documentos para clientes de otros vendedores.`
`SaveProforma::handle(array $data, User $actor, ?Proforma $proforma = null): Proforma` — `DB::transaction`: if `$proforma !== null && ! $proforma->isDraft()` throw `DocumentStateException('Solo se pueden editar proformas en borrador')`; `$salespersonId = SalespersonResolver::resolve($actor, $data['salesperson_id'] !== null ? (int) $data['salesperson_id'] : null, Proforma::VIEW_ALL_PERMISSION)`; `['currency' => $c, 'exchange_rate' => $rate] = DocumentCurrency::resolve((int) $data['currency_id'], CarbonImmutable::parse($data['issue_date']))`; `$computed = $calculator->compute(array_map(LineInput::fromArray(...), $data['lines']))`; header attributes = `client_id, salesperson_id, currency_id, exchange_rate, issue_date, valid_until, price_list_id, notes` + `DocumentLines::totals($computed, $rate)`; create (`number => DocumentNumbering::next(DocumentKind::Proforma)`, `status => Draft`, `created_by => $actor->id`) or update; `DocumentLines::write($proforma, $computed)`; return fresh.
`TransitionProforma` with `markSent(Proforma, User)`, `approve(Proforma, User, ?string $note)`, `reject(Proforma, User, ?string $note)`: each checks the allowed source statuses (markSent: `[Draft]`; approve/reject: `[Draft, Sent]`) else `throw new DocumentStateException("La proforma está {$proforma->status->label()} y no admite esta acción")`; sets status + timestamps (`sent_at`, `approved_at`/`approved_by`/`approval_note`, `rejected_at`/`rejection_note`); `activity('proformas')->causedBy($user)->performedOn($proforma)->event('sent'|'approved'|'rejected')->withProperties(['from' => $old, 'to' => $new, 'note' => $note])->log("Proforma {$number} marcada como enviada" | "… aprobada" | "… rechazada")`.
`DuplicateProforma::handle(Proforma $source, User $actor): Proforma` — builds `$data` from the source (same client/salesperson resolution via `SalespersonResolver`, currency, `issue_date` today, `valid_until` today + `Settings::get('sales.proforma_validity_days', 15)`, lines from `source->lines` with `product_id, description, quantity, unit_price, discount_percent`) and calls `SaveProforma`; then `activity('proformas')->causedBy($actor)->performedOn($copy)->event('duplicated')->withProperties(['source_id' => $source->id, 'source_number' => $source->number])->log("Proforma {$copy->number} creada a partir de {$source->number}")`.
`ExpireProformas` (`ShouldQueue`, `#[Tries(1)]`): `handle(): void` → for each `Proforma::query()->where('status', ProformaStatus::Sent->value)->whereDate('valid_until', '<', now()->toDateString())->get()`: update status `Expired` and `activity('proformas')->performedOn($p)->event('expired')->log("Proforma {$p->number} vencida")`. `routes/console.php`: `Schedule::job(new ExpireProformas)->dailyAt('00:10');`

- [ ] **Step 6: Routes and controller**

`routes/catalog.php`: `Route::get('clients/search', ClientSearchController::class)->name('clients.search');` before the clients resource. `ClientSearchController::__invoke(Request $request): JsonResponse` (`#[Middleware('permission:catalog.clients.view')]`): `q` trimmed; `Client::query()->visibleTo($request->user())->where('is_active', true)->where(fn ($w) => $w->where('code', 'ILIKE', "%{$q}%")->orWhere('business_name', 'ILIKE', "%{$q}%")->orWhere('trade_name', 'ILIKE', "%{$q}%")->orWhere('tax_id', 'ILIKE', '%'.FiscalId::normalize($q).'%'))->with('currency:id,code')->orderBy('business_name')->limit(20)->get()` → `data` rows per Interfaces (`formatted_tax_id` via the accessor, `currency_code` from the relation). When `FiscalId::normalize($q) === ''` skip the tax-id clause.
`routes/sales.php` inside the existing group:
```php
Route::get('proformas/export', [ProformaController::class, 'export'])->name('proformas.export');
Route::get('proformas/{proforma}/pdf', [ProformaController::class, 'pdf'])->name('proformas.pdf');
Route::post('proformas/{proforma}/send', [ProformaController::class, 'send'])->name('proformas.send');
Route::post('proformas/{proforma}/mark-sent', [ProformaController::class, 'markSent'])->name('proformas.mark-sent');
Route::post('proformas/{proforma}/approve', [ProformaController::class, 'approve'])->name('proformas.approve');
Route::post('proformas/{proforma}/reject', [ProformaController::class, 'reject'])->name('proformas.reject');
Route::post('proformas/{proforma}/duplicate', [ProformaController::class, 'duplicate'])->name('proformas.duplicate');
Route::resource('proformas', ProformaController::class);
```
`ProformaController` middleware per method: `index/show/pdf` → `permission:sales.proformas.view`; `create/store/duplicate` → `sales.proformas.create`; `edit/update` → `sales.proformas.update`; `destroy` → `sales.proformas.delete`; `approve/reject` → `sales.proformas.approve`; `send/markSent` → `sales.proformas.send`; `export` → `sales.proformas.export`. Every method receiving a `Proforma` calls `Gate::authorize('view'|'update'|'delete', $proforma)` (update for edit/update/markSent/approve/reject/send/duplicate; delete for destroy).
- `index`: `TableQuery::apply(Proforma::query()->visibleTo($user)->with(['client:id,business_name', 'salesperson:id,name', 'currency:id,code'])->when(status)->when(client_id)->when(salesperson_id)->when(from/to on issue_date), $request, ['proformas.number'], ['number', 'issue_date', 'valid_until', 'total', 'status'], 'issue_date', 'desc')->through(fn (Proforma $p) => [...$p->toArray(), 'status' => $p->status->value, 'status_label' => $p->status->label()])`; props `rows`, `filters` (`search, sort, dir, per_page, status, client_id, salesperson_id, from, to`), `statuses` (`[{value,label}]`), `salespeople` (active, only when `view-all`), `canViewAll`.
- `create`: `formOptions()` = `currencies` (active `id, code`), `salespeople` (active; when the user lacks view-all only their own), `lockSalesperson`, `defaultCurrencyId`, `validityDays` (`Settings::get('sales.proforma_validity_days', 15)`), `today`.
- `store` → `SaveProforma` → `redirect()->route('sales.proformas.show', $p)->with('success', "Proforma {$p->number} creada")`.
- `show` → `proforma` (`toArray()` + `status_label`, `client` (`id, business_name, trade_name, formatted_tax_id, address, email`), `salesperson.name`, `currency.code`, `lines` (array), `approver.name`), `history` (`AuditHistory::for`), `emails` (`SentEmail` rows for the document: `id, to, subject, status, sent_at, user.name`), `can` (`update, delete, approve, send, invoice` booleans computed from permissions + status).
- `edit` → if not draft: `redirect()->route('sales.proformas.show', $p)->with('error', 'Solo se pueden editar proformas en borrador')`; else form with `proforma` + lines + `formOptions()`.
- `update` → `SaveProforma` → show with `"Proforma {$number} actualizada"`.
- `destroy` → if not draft: back with error `Solo se pueden eliminar proformas en borrador`; else savepoint delete → index with `"Proforma {$number} eliminada"`.
- `markSent/approve/reject` (`TransitionNoteRequest` for approve/reject) → action → back with `"Proforma {$number} marcada como enviada"` / `"… aprobada"` / `"… rechazada"`.
- `duplicate` → `DuplicateProforma` → `redirect()->route('sales.proformas.edit', $copy)->with('success', "Proforma {$copy->number} creada a partir de {$source->number}")`.
- `pdf` → `DocumentPdf::stream('pdf.sales.proforma', $this->pdfData($p), "{$p->number}.pdf")`; `pdfData` = `['proforma' => $p->load('client', 'salesperson', 'lines.product'), 'lines' => lines as arrays, 'totals' => the seven totals, 'currency' => $p->currency->code]`; `activity('proformas')->performedOn($p)->event('pdf_generated')->log("PDF de {$p->number} generado")`.
- `send` (`SendDocumentEmailRequest`) → vars `['cliente' => client business_name, 'numero' => number, 'empresa' => Settings company.name, 'total' => DocumentPdf::money(total, code), 'fecha' => issue_date d/m/Y, 'vendedor' => salesperson name, 'usuario' => user name]`, attachments `[['name' => "{$number}.pdf", 'mime' => 'application/pdf', 'content' => DocumentPdf::binary(...)]]`, `SendDocumentEmail::handle($user, $p, 'proforma', $to, $cc ?? [], $vars, $attachments, $body ?: null, $subject ?: null)`; if the proforma was draft → `TransitionProforma::markSent`; back with `'Correo enviado a '.implode(', ', $to)`.
- `export` → `TableExport::fromQuery(filtered query, 'proformas', ['Número' => 'number', 'Fecha' => fn => issue_date d/m/Y, 'Cliente' => 'client.business_name', 'Vendedor' => 'salesperson.name', 'Válida hasta' => fn, 'Moneda' => 'currency.code', 'Subtotal' => 'subtotal', 'ITBIS' => 'itbis_total', 'Total' => 'total', 'Estado' => fn => label], format)`.
`lang/es/validation.php` attributes: add `'client_id' => 'el cliente', 'salesperson_id' => 'el vendedor', 'currency_id' => 'la moneda', 'issue_date' => 'la fecha', 'valid_until' => 'la validez', 'lines' => 'las líneas', 'lines.*.product_id' => 'el producto', 'lines.*.quantity' => 'la cantidad', 'lines.*.unit_price' => 'el precio', 'lines.*.discount_percent' => 'el descuento', 'to' => 'los destinatarios'`.

- [ ] **Step 7: PDF views**

`resources/views/pdf/partials/client-block.blade.php` (expects `$client` model): `<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $client->business_name }}@if($client->trade_name) ({{ $client->trade_name }})@endif<br>@if($client->tax_id)<strong>{{ $client->tax_id_type->label() }}:</strong> {{ $client->formatted_tax_id }}<br>@endif @if($client->address){{ $client->address }}@if($client->city), {{ $client->city }}@endif<br>@endif @if($client->email){{ $client->email }}@endif</div>`.
`resources/views/pdf/sales/proforma.blade.php`: `@extends('pdf.layout')`; `document-box`: `<div class="box"><strong style="font-size: 14px;">PROFORMA</strong><br><strong>{{ $proforma->number }}</strong><br>Fecha: {{ $proforma->issue_date->format('d/m/Y') }}<br>Válida hasta: {{ $proforma->valid_until->format('d/m/Y') }}<br>Vendedor: {{ $proforma->salesperson->name }}<br>Moneda: {{ $currency }}</div>`; `content`: `@include('pdf.partials.client-block', ['client' => $proforma->client])`, `@include('pdf.partials.lines-table')`, `@include('pdf.partials.totals')`, `@if($proforma->notes)<p class="muted">{{ $proforma->notes }}</p>@endif`, `<p class="muted" style="margin-top: 12px;">Documento sin valor fiscal. Precios sujetos a cambio después de la fecha de validez.</p>`.

- [ ] **Step 8: Frontend**

`resources/js/types/sales.ts` append:
```ts
export type PickedClient = { id: number; code: string; business_name: string; trade_name: string | null; tax_id: string | null; formatted_tax_id: string | null; currency_code: string; price_list_id: number | null; credit_days: number; email: string | null; salesperson_id: number | null };
export type DocumentTotals = { subtotal: string; discount_total: string; taxable_total: string; itbis_total: string; additional_tax_total: string; total: string; total_base: string };
export type ProformaLine = { id: number; line_number: number; product_id: number; description: string; quantity: string; unit_price: string; discount_percent: string; line_subtotal: string; discount_amount: string; taxable_base: string; itbis_rate: string; itbis_amount: string; additional_taxes: { code: string; name: string; amount: string }[]; additional_tax_total: string; line_total: string; invoiced_quantity: string; product?: { id: number; sku: string; name: string } };
export type Proforma = DocumentTotals & { id: number; number: string; status: string; status_label: string; client_id: number; salesperson_id: number; currency_id: number; exchange_rate: string; issue_date: string; valid_until: string; price_list_id: number | null; notes: string | null; sent_at: string | null; approved_at: string | null; approval_note: string | null; rejected_at: string | null; rejection_note: string | null; invoiced_at: string | null; client?: { id: number; business_name: string; trade_name: string | null; formatted_tax_id: string | null; address: string | null; email: string | null }; salesperson?: { id: number; name: string }; currency?: { id: number; code: string }; approver?: { id: number; name: string } | null; lines?: ProformaLine[] };
export type SentEmailRow = { id: number; to: string[]; subject: string; status: string; sent_at: string | null; user?: { name: string } };
```
Components: `status-badge.tsx` (`Badge` with the variant map), `client-picker.tsx` (same structure as `ProductPicker`, fetching `search.url({ query: { q } })` from `@/routes/catalog/clients`), `document-header.tsx`.
Pages (`AppLayout` breadcrumbs `[{ title: 'Proformas', href: index() }, ...]`, `Head` titles "Proformas" / "Nueva proforma" / "Proforma COT-…"):
- `index.tsx`: `DataTable` columns Número (link to show), Fecha (`formatDate`), Cliente, Vendedor, Válida hasta, Total (`formatMoney(total, currency.code)`), Estado (`StatusBadge`); toolbar: status `Select` ("Todos los estados" + `statuses`), salesperson `Select` when `canViewAll`, date inputs Desde/Hasta; actions: "Nueva proforma" (`can('sales.proformas.create')`), `ExportMenu` (`can('sales.proformas.export')`).
- `form.tsx`: `useForm<ProformaFormData>` (`client_id: number | null, salesperson_id, currency_id, issue_date, valid_until, price_list_id, notes, lines: DocumentLineInput[]`); on `ClientPicker` pick: sets `client_id`, `currency_id` from `currency_code` (lookup in `currencies`), `price_list_id`; `FormField`s: Cliente (picker), Vendedor (`Select`, disabled when `lockSalesperson`), Moneda, Fecha, Válida hasta (default `today + validityDays`), Notas (`Textarea`); `DocumentLinesEditor` with `useTotalsPreview(lines)`; submit "Guardar proforma" (`post(store.url())` / `put(update.url(id))`), "Cancelar" link. Lines are posted as `{ product_id, description, quantity, unit_price, discount_percent }`.
- `show.tsx`: `DocumentHeader` (meta: Cliente, RNC, Vendedor, Fecha, Válida hasta, Moneda, Tasa (when ≠ 1)); action buttons by `can`: "Editar" (`Link` to edit), "Marcar como enviada", "Aprobar" / "Rechazar" (each opens a `Dialog` with a `Textarea` Nota and confirms via `router.post`), "Duplicar", "PDF" (`<a target="_blank" href={pdf.url(id)}>`), "Enviar por correo" (`SendEmailDialog` with `defaultTo = [client.email]` filtered, `submitUrl = send.url(id)`), "Facturar" (rendered only when `can.invoice`; Task 10 wires it — render it disabled with title "Disponible en la siguiente tarea" is NOT acceptable: omit the button entirely until Task 10), `ConfirmDelete` (draft only). `Tabs`: "Líneas" (`DocumentLinesEditor readOnly` fed with `lines` mapped to `DocumentLineInput` and a `computed` object built from the stored line amounts), "Correos" (table of `emails`), "Historial" (`RecordHistory`).

- [ ] **Step 9: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (394 tests).

- [ ] **Step 10: Commit** — `feat(sales): add proformas with transitions, PDF, email and shared document services`

---

### Task 9: Invoices (backend) — draft, issue with NCF, credit limit, consumo threshold, reservations, cancel, conversion from proforma

**Files:**
- Create: migrations `2026_11_01_000014_create_invoices_table.php`, `2026_11_01_000015_create_invoice_lines_table.php`; `app/Enums/InvoiceStatus.php`, `app/Enums/InvoiceKind.php`, `app/Enums/PaymentTerms.php`; `app/Models/Invoice.php`, `app/Models/InvoiceLine.php`, `database/factories/InvoiceFactory.php`; `app/Policies/InvoicePolicy.php`; `app/Services/Sales/ClientAccount.php`; `app/Http/Requests/Sales/StoreInvoiceRequest.php`, `app/Http/Requests/Sales/IssueInvoiceRequest.php`, `app/Http/Requests/Sales/CancelInvoiceRequest.php`, `app/Http/Requests/Sales/InvoiceFromProformaRequest.php`; `app/Actions/Sales/SaveInvoice.php`, `app/Actions/Sales/IssueInvoice.php`, `app/Actions/Sales/CancelInvoice.php`, `app/Actions/Sales/CreateInvoiceFromProforma.php`; `app/Http/Controllers/Sales/InvoiceController.php`; `tests/Feature/Sales/InvoiceDraftTest.php`, `tests/Feature/Sales/InvoiceIssueTest.php`, `tests/Feature/Sales/InvoiceFromProformaTest.php`
- Modify: `routes/sales.php`, `app/Providers/AppServiceProvider.php` (policy), `tests/Support/helpers.php`, `app/Models/Proforma.php` (`invoices(): HasMany`)

**Interfaces:**
- Consumes: Task 3 (`FiscalNumberingService::assign`, `NcfTypeResolver::forSale`, `FiscalNumberingException`), Task 4 (`InventoryService::reserve/release/available`, `StockReservation`), Task 8 services, `Settings` (`fiscal.consumo_id_threshold` default `'250000.00'`, `fiscal.mode` default `'ncf'`).
- Produces: enums `InvoiceStatus: string { Draft='draft'; Issued='issued'; PartiallyPaid='partially_paid'; Paid='paid'; Cancelled='cancelled' }` (labels `Borrador`, `Emitida`, `Pagada parcialmente`, `Pagada`, `Anulada`), `InvoiceKind: string { Sale='sale'; Historical='historical' }` (`Venta`, `Histórica`), `PaymentTerms: string { Cash='cash'; Credit='credit' }` (`Contado`, `Crédito`); `Invoice` (columns below; `public const VIEW_ALL_PERMISSION = 'sales.invoices.view-all'`; `isDraft()`, `isOpen()` (issued or partially_paid), `refreshFinancials(): void` — recomputes `balance = total + debited_amount − credited_amount − paid_amount` and status: cancelled stays; balance ≤ 0 → `Paid`; `paid_amount > 0` → `PartiallyPaid`; else `Issued`; `mutableWhenIssued()` → `['status', 'paid_amount', 'credited_amount', 'debited_amount', 'balance', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'sent_at', 'dispatch_status']`); `InvoiceLine` (`dispatched_quantity`, `returned_quantity`, `credited_quantity`, `dispatched_cost`; `reservations(): MorphMany` to `StockReservation` as `reservable`; `remainingToDispatch()` = `quantity − dispatched_quantity − credited_quantity` (4 dp)); `ClientAccount::outstandingBase(Client $client): string` (Σ `balance × exchange_rate` of open invoices, 2 dp) and `::availableCredit(Client): ?string` (`null` when `credit_limit` is 0); actions `SaveInvoice::handle(array $data, User $actor, ?Invoice $invoice = null): Invoice`, `IssueInvoice::handle(Invoice $invoice, User $actor, bool $creditOverride = false, ?string $creditOverrideReason = null): Invoice`, `CancelInvoice::handle(Invoice $invoice, User $actor, string $reason): Invoice`, `CreateInvoiceFromProforma::handle(Proforma $proforma, array $quantities /* array<int line_id, string qty> */, User $actor): Invoice`; routes `sales.invoices.{index,create,store,show,edit,update,destroy,issue,cancel,export}` and `sales.proformas.invoice` (POST `/sales/proformas/{proforma}/invoice`); helpers `validInvoicePayload(array $over = []): array` and `issuedInvoice(array $over = [], ?User $actor = null): Invoice` (loads 1000 units at cost 50 for every line product, creates the draft through `SaveInvoice` and issues it through `IssueInvoice`; creates B01/B02 sequences when none is active).
- Accounting events: `invoice_issued` payload `{number, ncf, ncf_type, kind, client_id, client_tax_id, salesperson_id, issue_date, due_date, payment_terms, subtotal, discount_total, taxable_total, itbis_total, additional_tax_total, additional_taxes: [{code, amount}], total, total_base, currency, exchange_rate}`; `invoice_cancelled` payload `{number, ncf, ncf_type, client_id, issue_date, cancelled_on, reason, total, total_base, currency, exchange_rate}`.

Business rules: a draft stores `warehouse_id` (default warehouse); `due_date = issue_date + credit_days` (0 for `cash`); **issue** (a) requires ≥ 1 line, client active, open NCF mode `ncf`; (b) recomputes totals from lines; (c) resolves the NCF type (`NcfTypeResolver::forSale`); (d) consumo threshold: if type is `B02` and `total_base ≥ Settings fiscal.consumo_id_threshold` and the client has no `tax_id` → `DocumentStateException('Las facturas de consumo desde RD$ 250,000.00 requieren el RNC o cédula del comprador (Norma General 10-2018).')` (amount formatted from the setting); (e) credit limit: when `payment_terms = credit`, `credit_limit > 0` and `outstandingBase + total_base > credit_limit` → unless `$creditOverride && $actor->can('sales.invoices.credit-override')` throw `DocumentStateException("El cliente supera su límite de crédito (disponible RD$ {available}). Un usuario con permiso puede autorizar el exceso.")`; with override, log `credit_limit_overridden` (properties `limit, outstanding, total_base, reason`); (f) reserves stock per line in the invoice warehouse (`InventoryService::reserve($line, product_id, warehouse_id, quantity)`; an `InventoryException` aborts the whole issue — nothing is written); (g) assigns `number` (`DocumentNumbering`) and the NCF (`FiscalNumberingService::assign($type, issue_date)`), snapshots the client (`client_name`, `client_tax_id`, `client_tax_id_type`, `client_address`), sets `issued_at = now()`, `status = issued`, `balance = total`, `fiscal_mode = 'ncf'`; (h) writes activity `issued` and the `invoice_issued` event. All inside one `DB::transaction` so a failing step consumes no number. **Cancel**: only `issued`/`partially_paid` with `paid_amount = 0`, `credited_amount = 0`, `debited_amount = 0` and no confirmed dispatch (`Σ dispatched_quantity = 0`), else `DocumentStateException('Solo se pueden anular facturas sin cobros, notas ni despachos; usa una nota de crédito.')`; releases every open reservation, sets `cancelled_at/by/reason`, status `cancelled`, activity `cancelled`, event `invoice_cancelled`. **Conversion**: proforma must be `approved` (else `DocumentStateException('Solo se pueden facturar proformas aprobadas')`); quantities per line `0 ≤ q ≤ quantity − invoiced_quantity`, at least one `> 0`; creates a draft invoice copying client/salesperson/currency/prices/discounts with `proforma_id`, increments `invoiced_quantity`; when every line is fully invoiced → proforma `invoiced` + `invoiced_at`; activity on the proforma `invoiced` (`properties.invoice_id`) and on the invoice `created_from_proforma`.

- [ ] **Step 1: Helpers** — append to `tests/Support/helpers.php`:
```php
function validInvoicePayload(array $over = []): array
{
    $client = \App\Models\Client::factory()->create(['credit_days' => 30, 'credit_limit' => '0']);
    $product = \App\Models\Product::factory()->create(['itbis_rate' => '18.00']);
    $warehouse = \App\Models\Warehouse::default() ?? \App\Models\Warehouse::factory()->create(['is_default' => true]);

    return array_merge([
        'client_id' => $client->id, 'salesperson_id' => '', 'warehouse_id' => $warehouse->id, 'currency_id' => \App\Models\Currency::base()->id,
        'issue_date' => now()->toDateString(), 'payment_terms' => 'credit', 'credit_days' => 30, 'notes' => '',
        'lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']],
    ], $over);
}

/** Loads stock for every product of the payload, creates the draft through SaveInvoice and issues it. */
function issuedInvoice(array $over = [], ?\App\Models\User $actor = null): \App\Models\Invoice
{
    $actor ??= \App\Models\User::factory()->create();
    $payload = validInvoicePayload($over);
    foreach ($payload['lines'] as $line) {
        app(\App\Services\Inventory\InventoryService::class)->receive(new \App\Support\Inventory\StockLine($line['product_id'], $payload['warehouse_id'], '1000', '50'), \App\Enums\StockMovementType::Initial, $actor, $actor);
    }
    if (! \App\Models\NcfSequence::query()->where('is_active', true)->exists()) {
        activeSequence('B01', ['range_to' => 1000]);
        activeSequence('B02', ['range_to' => 1000]);
    }
    $payload['salesperson_id'] = $payload['salesperson_id'] ?: \App\Models\Salesperson::factory()->create()->id;
    $invoice = app(\App\Actions\Sales\SaveInvoice::class)->handle($payload, $actor->givePermissionTo('sales.invoices.view-all'));

    return app(\App\Actions\Sales\IssueInvoice::class)->handle($invoice, $actor);
}
```
(`givePermissionTo` requires the permission to exist: `actingAsRole()` or `TestCase::$seed` already seeded it.)

- [ ] **Step 2: Failing tests**

`tests/Feature/Sales/InvoiceDraftTest.php`:
```php
<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Salesperson;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a draft invoice is created without number or ncf and computes due date from credit days', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $client = \App\Models\Client::factory()->create(['salesperson_id' => $sp->id, 'credit_days' => 45]);
    $this->post(route('sales.invoices.store'), validInvoicePayload(['client_id' => $client->id, 'credit_days' => 45]))->assertRedirect();
    $i = Invoice::firstOrFail();
    expect($i->status)->toBe(InvoiceStatus::Draft)->and($i->number)->toBeNull()->and($i->ncf)->toBeNull()->and($i->kind->value)->toBe('sale')
        ->and($i->due_date->toDateString())->toBe(now()->addDays(45)->toDateString())->and($i->total)->toBe('236.00')->and($i->salesperson_id)->toBe($sp->id)
        ->and(Activity::inLog('invoices')->where('event', 'created')->exists())->toBeTrue();
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->component('sales/invoices/show')->where('invoice.status', 'draft'));
});

test('cash invoices are due on the issue date and credit days are bounded', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'payment_terms' => 'cash', 'credit_days' => 0]))->assertRedirect();
    expect(Invoice::firstOrFail()->due_date->toDateString())->toBe(now()->toDateString());
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'credit_days' => 400]))->assertSessionHasErrors(['credit_days']);
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'warehouse_id' => \App\Models\Warehouse::factory()->create(['is_active' => false])->id]))->assertSessionHasErrors(['warehouse_id' => 'El almacén no existe o está inactivo.']);
});

test('only drafts can be edited or deleted and scoping applies', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id]));
    $draft = Invoice::firstOrFail();
    $this->put(route('sales.invoices.update', $draft), validInvoicePayload(['salesperson_id' => $sp->id, 'client_id' => $draft->client_id, 'lines' => [['product_id' => $draft->lines->first()->product_id, 'quantity' => '5', 'unit_price' => '10', 'discount_percent' => '0', 'description' => '']]]))->assertRedirect(route('sales.invoices.show', $draft));
    expect($draft->fresh()->total)->toBe('59.00');
    $issued = issuedInvoice();
    $this->get(route('sales.invoices.edit', $issued))->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar facturas en borrador');
    $this->delete(route('sales.invoices.destroy', $issued))->assertRedirect()->assertSessionHas('error', 'Solo se pueden eliminar facturas en borrador');
    $this->delete(route('sales.invoices.destroy', $draft))->assertRedirect(route('sales.invoices.index'))->assertSessionHas('success', 'Borrador de factura eliminado');

    salesUserWithSalesperson();
    $this->get(route('sales.invoices.show', $issued))->assertForbidden();
    $this->get(route('sales.invoices.index'))->assertInertia(fn ($p) => $p->has('rows.data', 0));
});
```
`tests/Feature/Sales/InvoiceIssueTest.php`:
```php
<?php

use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\InvoiceStatus;
use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    $this->sp = Salesperson::factory()->create();
});

function draftFor(array $over, User $actor): Invoice
{
    $payload = validInvoicePayload($over);
    foreach ($payload['lines'] as $line) {
        app(InventoryService::class)->receive(new \App\Support\Inventory\StockLine($line['product_id'], $payload['warehouse_id'], '100', '50'), \App\Enums\StockMovementType::Initial, $actor, $actor);
    }

    return app(SaveInvoice::class)->handle($payload, $actor);
}

test('issuing assigns number and ncf, snapshots the client, reserves stock and records the accounting event', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_to' => 10]);
    $client = Client::factory()->create(['business_name' => 'ACME SRL', 'tax_id' => '101850043', 'credit_limit' => '0']);
    $draft = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $user);

    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect(route('sales.invoices.show', $draft))->assertSessionHas('success', 'Factura FAC-000001 emitida con NCF B0100000001');
    $i = $draft->fresh();
    expect($i->status)->toBe(InvoiceStatus::Issued)->and($i->number)->toBe('FAC-000001')->and($i->ncf)->toBe('B0100000001')->and($i->ncf_type->value)->toBe('B01')
        ->and($i->ncf_expires_on)->not->toBeNull()->and($i->fiscal_mode)->toBe('ncf')->and($i->issued_at)->not->toBeNull()
        ->and($i->client_name)->toBe('ACME SRL')->and($i->client_tax_id)->toBe('101850043')->and($i->balance)->toBe('236.00')->and($i->paid_amount)->toBe('0.00')
        ->and(StockReservation::where('reservable_type', \App\Models\InvoiceLine::class)->count())->toBe(1)
        ->and(app(InventoryService::class)->available($i->lines->first()->product_id, $i->warehouse_id))->toBe('98.0000')
        ->and(Activity::inLog('invoices')->where('event', 'issued')->where('subject_id', $i->id)->where('causer_id', $user->id)->exists())->toBeTrue();
    $event = AccountingEvent::where('type', 'invoice_issued')->firstOrFail();
    expect($event->document_id)->toBe($i->id)->and($event->payload['ncf'])->toBe('B0100000001')->and($event->payload['total'])->toBe('236.00')->and($event->payload['itbis_total'])->toBe('36.00')->and($event->payload['currency'])->toBe('DOP');

    expect(fn () => $i->update(['notes' => 'x']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
    $this->post(route('sales.invoices.issue', $i), [])->assertRedirect()->assertSessionHas('error', 'La factura está Emitida y no admite esta acción');
});

test('consumo clients get B02 and the threshold requires the client tax id', function () {
    $user = actingAsRole('accounting');
    activeSequence('B02');
    $consumer = Client::factory()->create(['taxpayer_type' => 'consumidor_final', 'tax_id_type' => 'none', 'tax_id' => null, 'credit_limit' => '0']);
    $small = draftFor(['client_id' => $consumer->id, 'salesperson_id' => $this->sp->id], $user);
    expect(app(IssueInvoice::class)->handle($small, $user)->ncf)->toBe('B0200000001');

    $big = draftFor(['client_id' => $consumer->id, 'salesperson_id' => $this->sp->id, 'lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '0.00'])->id, 'quantity' => '1', 'unit_price' => '250000', 'discount_percent' => '0', 'description' => '']]], $user);
    expect(fn () => app(IssueInvoice::class)->handle($big, $user))->toThrow(\App\Exceptions\DocumentStateException::class, 'Las facturas de consumo desde RD$ 250,000.00 requieren el RNC o cédula del comprador (Norma General 10-2018).');
    expect($big->fresh()->status)->toBe(InvoiceStatus::Draft)->and(NcfSequence::where('ncf_type', 'B02')->first()->next_number)->toBe(2);
    app(Settings::class)->set('fiscal.consumo_id_threshold', '300000.00');
    expect(app(IssueInvoice::class)->handle($big->fresh(), $user)->ncf)->toBe('B0200000002');
});

test('credit limit blocks credit invoices unless an authorised user overrides with a reason', function () {
    $sales = salesUserWithSalesperson()['user'];
    $sales->givePermissionTo('sales.invoices.view-all');
    activeSequence('B01', ['range_to' => 10]);
    $client = Client::factory()->create(['credit_limit' => '300.00']);
    $first = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $sales);
    app(IssueInvoice::class)->handle($first, $sales); // outstanding 236.00
    $second = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $sales);

    $this->post(route('sales.invoices.issue', $second), [])->assertRedirect()->assertSessionHas('error', 'El cliente supera su límite de crédito (disponible RD$ 64.00). Un usuario con permiso puede autorizar el exceso.');
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1', 'credit_override_reason' => 'Autorizado por gerencia'])->assertForbidden();

    $owner = actingAsRole('owner');
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1'])->assertSessionHasErrors(['credit_override_reason']);
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1', 'credit_override_reason' => 'Autorizado por gerencia'])->assertRedirect();
    expect($second->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(Activity::inLog('invoices')->where('event', 'credit_limit_overridden')->where('subject_id', $second->id)->where('causer_id', $owner->id)->first()->properties['reason'])->toBe('Autorizado por gerencia');

    $cash = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id, 'payment_terms' => 'cash', 'credit_days' => 0], $owner);
    expect(app(IssueInvoice::class)->handle($cash, $owner)->status)->toBe(InvoiceStatus::Issued); // cash sales ignore the limit
});

test('issue is refused and nothing is written when the ncf sequence is exhausted or stock is insufficient', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    $draft = draftFor(['salesperson_id' => $this->sp->id], $user);
    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('error', 'La secuencia de B01 está agotada; registra una nueva secuencia autorizada por la DGII.');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft)->and($draft->fresh()->number)->toBeNull()->and(StockReservation::count())->toBe(0)->and(AccountingEvent::where('type', 'invoice_issued')->count())->toBe(0);

    activeSequence('B01', ['range_from' => 10, 'range_to' => 20]);
    $big = app(SaveInvoice::class)->handle(validInvoicePayload(['salesperson_id' => $this->sp->id, 'lines' => [['product_id' => Product::factory()->create(['sku' => 'NOSTOCK'])->id, 'quantity' => '3', 'unit_price' => '1', 'discount_percent' => '0', 'description' => '']]]), $user);
    $this->post(route('sales.invoices.issue', $big), [])->assertSessionHas('error', fn (string $m) => str_starts_with($m, 'Disponible insuficiente de NOSTOCK'));
    expect($big->fresh()->number)->toBeNull()->and(\App\Models\DocumentSequence::where('kind', 'invoice')->first()->next_number)->toBe(1);
});

test('sales users with issue permission can issue their own invoices but not cancel', function () {
    $sales = salesUserWithSalesperson()['user'];
    activeSequence('B01', ['range_to' => 10]);
    $own = draftFor(['salesperson_id' => $this->sp->id, 'client_id' => Client::factory()->create(['salesperson_id' => $sales->salesperson->id, 'credit_limit' => '0'])->id], $sales);
    $this->post(route('sales.invoices.issue', $own), [])->assertRedirect(route('sales.invoices.show', $own));
    $this->post(route('sales.invoices.cancel', $own), ['reason' => 'Error'])->assertForbidden();
});

test('cancel releases reservations, records the event and is refused once paid or dispatched', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.invoices.cancel', $i), ['reason' => ''])->assertSessionHasErrors(['reason']);
    $this->post(route('sales.invoices.cancel', $i), ['reason' => 'Cliente desistió'])->assertRedirect()->assertSessionHas('success', "Factura {$i->number} anulada");
    $i->refresh();
    expect($i->status)->toBe(InvoiceStatus::Cancelled)->and($i->cancel_reason)->toBe('Cliente desistió')->and($i->cancelled_by)->toBe($user->id)
        ->and(StockReservation::whereNull('released_at')->count())->toBe(0)
        ->and(app(InventoryService::class)->available($i->lines->first()->product_id, $i->warehouse_id))->toBe('1000.0000')
        ->and(AccountingEvent::where('type', 'invoice_cancelled')->where('document_id', $i->id)->first()->payload['ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('invoices')->where('event', 'cancelled')->where('subject_id', $i->id)->exists())->toBeTrue();

    $paid = issuedInvoice();
    $paid->forceFill(['paid_amount' => '10.00'])->save();
    $paid->refreshFinancials();
    $this->post(route('sales.invoices.cancel', $paid), ['reason' => 'x'])->assertSessionHas('error', 'Solo se pueden anular facturas sin cobros, notas ni despachos; usa una nota de crédito.');
});

test('index filters, export and the financial status helper', function () {
    actingAsRole('owner');
    $a = issuedInvoice();
    $a->forceFill(['paid_amount' => '236.00'])->save();
    $a->refreshFinancials();
    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($a->fresh()->balance)->toBe('0.00');
    $b = issuedInvoice();
    $b->forceFill(['paid_amount' => '100.00'])->save();
    $b->refreshFinancials();
    expect($b->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($b->fresh()->balance)->toBe('136.00');
    $this->get(route('sales.invoices.index', ['status' => 'partially_paid']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.balance', '136.00'));
    $this->get(route('sales.invoices.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['table'])->toBe('invoices');
});
```
`tests/Feature/Sales/InvoiceFromProformaTest.php`:
```php
<?php

use App\Enums\ProformaStatus;
use App\Models\Invoice;
use App\Models\Proforma;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    \App\Models\Warehouse::factory()->create(['is_default' => true]);
});

test('an approved proforma converts partially then fully into draft invoices', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(2)->create();
    [$l1, $l2] = $p->lines->all();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '0']])->assertRedirect(route('sales.invoices.edit', Invoice::first()))->assertSessionHas('success', 'Borrador de factura creado desde '.$p->number);
    $inv = Invoice::firstOrFail();
    expect($inv->proforma_id)->toBe($p->id)->and($inv->lines()->count())->toBe(1)->and($inv->lines->first()->product_id)->toBe($l1->product_id)->and($inv->total)->toBe('118.00')
        ->and($l1->fresh()->invoiced_quantity)->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved)
        ->and(Activity::inLog('invoices')->where('event', 'created_from_proforma')->where('subject_id', $inv->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '1']])->assertSessionHasErrors(['quantities.'.$l1->id => 'Cantidad pendiente: 0.0000.']);
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '1']])->assertRedirect();
    expect($p->fresh()->status)->toBe(ProformaStatus::Invoiced)->and($p->fresh()->invoiced_at)->not->toBeNull()->and(Invoice::count())->toBe(2)
        ->and(Activity::inLog('proformas')->where('event', 'invoiced')->where('subject_id', $p->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '0']])->assertSessionHasErrors(['quantities' => 'Indica al menos una cantidad a facturar.']);
});

test('only approved proformas can be invoiced', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->sent()->withLines(1)->create();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$p->lines->first()->id => '1']])->assertRedirect()->assertSessionHas('error', 'Solo se pueden facturar proformas aprobadas');
});
```

- [ ] **Step 3: Migrations, enums, models, factory, policy**

`invoices` (000014): `id()`, `string('number', 12)->nullable()->unique()`, `string('kind', 12)->default('sale')->index()`, `string('status', 20)->index()`, `string('fiscal_mode', 10)->nullable()` (`ncf` | `external`; `ecf` in Phase 3), `string('ncf', 11)->nullable()->unique()`, `string('ncf_type', 3)->nullable()`, `date('ncf_expires_on')->nullable()`, `foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete()`, `foreignId('client_id')->constrained('clients')->restrictOnDelete()`, `string('client_name', 200)->nullable()`, `string('client_tax_id', 11)->nullable()`, `string('client_tax_id_type', 10)->nullable()`, `string('client_address', 255)->nullable()`, `foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete()`, `foreignId('proforma_id')->nullable()->constrained('proformas')->nullOnDelete()`, `foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete()`, `foreignId('currency_id')->constrained('currencies')->restrictOnDelete()`, `decimal('exchange_rate', 12, 6)`, `date('issue_date')->index()`, `date('due_date')->index()`, `string('payment_terms', 10)->default('credit')`, `unsignedSmallInteger('credit_days')->default(0)`, `DocumentSchema::totals($table)`, `decimal('paid_amount', 14, 2)->default(0)`, `decimal('credited_amount', 14, 2)->default(0)`, `decimal('debited_amount', 14, 2)->default(0)`, `decimal('balance', 14, 2)->default(0)`, `string('dispatch_status', 10)->default('none')` (`none|partial|complete`), `text('notes')->nullable()`, `timestamp('issued_at')->nullable()`, `timestamp('sent_at')->nullable()`, `timestamp('cancelled_at')->nullable()`, `foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()`, `string('cancel_reason', 255)->nullable()`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`, `index(['client_id', 'status'])`.
`invoice_lines` (000015): `id()`, `foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete()`, `DocumentSchema::lineAmounts($table)`, `decimal('dispatched_quantity', 14, 4)->default(0)`, `decimal('returned_quantity', 14, 4)->default(0)`, `decimal('credited_quantity', 14, 4)->default(0)`, `decimal('dispatched_cost', 14, 2)->default(0)`, `timestamps()`, `unique(['invoice_id', 'line_number'])`.
Enums as in Interfaces. `Invoice`: `use HasFactory, ImmutableWhenIssued, LogsModelActivity, VisibleToSalesperson;` casts (`status`, `kind`, `payment_terms`, `ncf_type` → enums, nullable `NcfType`), dates, decimals; relations `client, salesperson, proforma, warehouse, currency, ncfSequence, canceller, creator, lines (HasMany ordered by line_number)`; `isDraft()`, `isOpen()`, `refreshFinancials()` (uses `Decimal`; saves via `forceFill` — all columns in the mutable list); `mutableWhenIssued()` per Interfaces. `InvoiceLine`: `use LogsModelActivity;` casts; `invoice()`, `product()`, `reservations(): MorphMany` (`StockReservation::class, 'reservable'`), `remainingToDispatch(): string`. `Proforma::invoices(): HasMany`.
`InvoiceFactory::definition()`: draft invoice (`status draft`, `kind sale`, `client_id`, `salesperson_id`, `warehouse_id => Warehouse::factory()`, `currency_id` base, `exchange_rate 1.000000`, `issue_date` today, `due_date` today+30, `payment_terms credit`, `credit_days 30`, zero totals, `created_by => User::factory()`); state `withLines(int $n)` same technique as `ProformaFactory`.
`InvoicePolicy extends SalesDocumentPolicy` (`sales.invoices.view-all`); register with `Gate::policy`.

- [ ] **Step 4: Requests and actions**

`StoreInvoiceRequest` (`use ValidatesDocumentLines`; `prepareForValidation` `''` → `null` for `salesperson_id`, `notes`): rules = the proforma header rules (client, salesperson, currency) + `warehouse_id` → `['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)]` (`El almacén no existe o está inactivo.`), `issue_date` → `['required', 'date_format:Y-m-d']`, `payment_terms` → `['required', Rule::enum(PaymentTerms::class)]`, `credit_days` → `['required', 'integer', 'between:0,365']`, `notes`, lines; same `withValidator` client-visibility check.
`IssueInvoiceRequest`: `credit_override` → `['nullable', 'boolean']`, `credit_override_reason` → `['nullable', 'string', 'max:255', 'required_if:credit_override,1']` (message `required_if` → `Indica el motivo de la autorización.`); `authorize()`: `true`; the controller checks `credit_override && ! can('sales.invoices.credit-override')` → `abort(403)` before calling the action (hence the test's 403 for sales).
`CancelInvoiceRequest`: `reason` → `['required', 'string', 'max:255']`.
`InvoiceFromProformaRequest`: `quantities` → `['required', 'array']`, `quantities.*` → `['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/']`; `withValidator` → for each proforma line id key: must belong to the proforma (else `quantities`: `Línea inválida.`); `q > quantity − invoiced_quantity` → `quantities.{id}`: `Cantidad pendiente: {remaining}.` (4 dp); all zero → `quantities`: `Indica al menos una cantidad a facturar.`
`SaveInvoice::handle(array $data, User $actor, ?Invoice $invoice = null): Invoice` — same shape as `SaveProforma` (`DocumentStateException('Solo se pueden editar facturas en borrador')`), `due_date = payment_terms === 'cash' ? issue_date : issue_date + credit_days`, status `draft`, `balance 0`.
`IssueInvoice::handle(Invoice $invoice, User $actor, bool $creditOverride = false, ?string $creditOverrideReason = null): Invoice`:
```php
return DB::transaction(function () use ($invoice, $actor, $creditOverride, $creditOverrideReason): Invoice {
    $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with(['lines.product', 'client', 'currency'])->firstOrFail();
    if (! $invoice->isDraft()) {
        throw new DocumentStateException("La factura está {$invoice->status->label()} y no admite esta acción");
    }
    if ($invoice->lines->isEmpty()) {
        throw new DocumentStateException('La factura no tiene líneas');
    }
    if (! $invoice->client->is_active) {
        throw new DocumentStateException('El cliente está inactivo');
    }
    if ($this->numbering->mode() !== 'ncf') {
        throw new DocumentStateException('El modo fiscal e-CF se habilita en la fase 3');
    }
    $computed = $this->calculator->compute($invoice->lines->map(fn (InvoiceLine $l) => new LineInput($l->product_id, $l->quantity, $l->unit_price, $l->discount_percent, $l->description))->all());
    $totals = DocumentLines::totals($computed, $invoice->exchange_rate);
    $type = $this->resolver->forSale($invoice->client);
    $this->guardConsumoThreshold($type, $totals['total_base'], $invoice->client);
    $this->guardCreditLimit($invoice, $totals['total_base'], $actor, $creditOverride, $creditOverrideReason);
    DocumentLines::write($invoice, $computed);
    foreach ($invoice->lines()->get() as $line) {
        $this->inventory->reserve($line, $line->product_id, (int) $invoice->warehouse_id, $line->quantity);
    }
    $fiscal = $this->numbering->assign($type, $invoice->issue_date);
    $invoice->forceFill([
        ...$totals, 'number' => $this->documentNumbering->next(DocumentKind::Invoice), 'ncf' => $fiscal->ncf, 'ncf_type' => $fiscal->type, 'ncf_expires_on' => $fiscal->expiresOn?->toDateString(),
        'ncf_sequence_id' => $fiscal->sequenceId, 'fiscal_mode' => 'ncf', 'client_name' => $invoice->client->business_name, 'client_tax_id' => $invoice->client->tax_id,
        'client_tax_id_type' => $invoice->client->tax_id_type->value, 'client_address' => $invoice->client->address, 'status' => InvoiceStatus::Issued, 'balance' => $totals['total'], 'issued_at' => now(),
    ])->save();
    activity('invoices')->causedBy($actor)->performedOn($invoice)->event('issued')->withProperties(['number' => $invoice->number, 'ncf' => $invoice->ncf, 'total' => $invoice->total])->log("Factura {$invoice->number} emitida con NCF {$invoice->ncf}");
    $this->events->record('invoice_issued', $invoice, [/* payload keys from Interfaces */], $invoice->issue_date);

    return $invoice->fresh();
});
```
`guardConsumoThreshold(NcfType $type, string $totalBase, Client $client): void` — `$threshold = Decimal::money((string) $this->settings->get('fiscal.consumo_id_threshold', '250000.00'))`; if `$type === NcfType::B02 && Decimal::cmp($totalBase, $threshold) >= 0 && blank($client->tax_id)` throw `DocumentStateException('Las facturas de consumo desde RD$ '.number_format((float) $threshold, 2).' requieren el RNC o cédula del comprador (Norma General 10-2018).')` (display formatting only).
`guardCreditLimit(Invoice $invoice, string $totalBase, User $actor, bool $override, ?string $reason): void` — skip when `payment_terms === Cash` or `Decimal::isZero($client->credit_limit)`; `$outstanding = ClientAccount::outstandingBase($client)`; `$available = Decimal::money(limit − outstanding)`; if `Decimal::cmp(outstanding + totalBase, limit) > 0`: if `! ($override && $actor->can('sales.invoices.credit-override'))` throw `DocumentStateException('El cliente supera su límite de crédito (disponible RD$ '.number_format((float) $available, 2).'). Un usuario con permiso puede autorizar el exceso.')`; else `activity('invoices')->causedBy($actor)->performedOn($invoice)->event('credit_limit_overridden')->withProperties(['limit' => $limit, 'outstanding' => $outstanding, 'total_base' => $totalBase, 'reason' => $reason])->log('Exceso de límite de crédito autorizado')`.
`ClientAccount::outstandingBase(Client $client): string` → `Decimal::sum(Invoice::query()->where('client_id', $client->id)->whereIn('status', ['issued', 'partially_paid'])->get()->map(fn (Invoice $i) => Decimal::mul($i->balance, $i->exchange_rate, 2)))`.
`CancelInvoice::handle(Invoice $invoice, User $actor, string $reason): Invoice` — lock; allowed statuses `issued|partially_paid`; the four zero checks (`paid_amount`, `credited_amount`, `debited_amount`, Σ `lines.dispatched_quantity`) else the exception from the rules; for each line's open reservations (`released_at null`) → `InventoryService::release($r)`; `forceFill(['status' => Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'balance' => '0.00'])->save()`; activity `cancelled` (`properties.reason`), event `invoice_cancelled` (`cancelled_on` = today).
`CreateInvoiceFromProforma::handle(Proforma $proforma, array $quantities, User $actor): Invoice` — lock the proforma; status must be `Approved`; build the `SaveInvoice` payload: `client_id, salesperson_id (the proforma's, resolved through SalespersonResolver), warehouse_id = Warehouse::default()?->id ?? throw DocumentStateException('No hay un almacén predeterminado'), currency_id, issue_date today, payment_terms credit, credit_days = client credit_days, notes = "Desde proforma {$number}"`, lines = proforma lines with `q > 0` (`product_id, description, quantity = q, unit_price, discount_percent`); `SaveInvoice::handle` then `forceFill(['proforma_id' => …])`; update each line `invoiced_quantity += q`; when `lines->every(invoiced_quantity >= quantity)` → proforma `Invoiced` + `invoiced_at` + activity `invoiced` (`properties.invoice_id`); activity on the invoice `created_from_proforma` (`properties.proforma_id, proforma_number`) description `"Borrador de factura creado desde {$proforma->number}"`.

- [ ] **Step 5: Routes and controller**

`routes/sales.php`:
```php
Route::get('invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
Route::post('proformas/{proforma}/invoice', [InvoiceController::class, 'fromProforma'])->name('proformas.invoice');
Route::resource('invoices', InvoiceController::class);
```
`InvoiceController` permissions: `index/show` → `sales.invoices.view`; `create/store/fromProforma` → `sales.invoices.create`; `edit/update` → `sales.invoices.update`; `destroy` → `sales.invoices.delete`; `issue` → `sales.invoices.issue`; `cancel` → `sales.invoices.cancel`; `export` → `sales.invoices.export`; policies as in Task 8 (`fromProforma` authorises `update` on the proforma). Flash messages: store `Borrador de factura creado`, update `Borrador de factura actualizado`, destroy `Borrador de factura eliminado`, issue `"Factura {$number} emitida con NCF {$ncf}"`, cancel `"Factura {$number} anulada"`, fromProforma `"Borrador de factura creado desde {$proforma->number}"` (redirect to `sales.invoices.edit`). Non-draft edit/update/destroy → back with `Solo se pueden editar facturas en borrador` / `Solo se pueden eliminar facturas en borrador`. `index` filters `status, client_id, salesperson_id, from, to, kind`; searchable `invoices.number, invoices.ncf, invoices.client_name`; sortable `number, ncf, issue_date, due_date, total, balance, status`; rows add `status_label`, `kind_label`, `client.business_name`, `salesperson.name`, `currency.code`. `show` props: `invoice` (with `lines.product`, `client`, `salesperson`, `currency`, `proforma:id,number`, `canceller:id,name`), `history`, `emails`, `can` (`update, delete, issue, cancel, send, creditOverride, dispatch, creditNote, debitNote, receipt`). `export` columns: Número, NCF, Tipo NCF, Fecha, Vencimiento, Cliente, RNC/Cédula, Vendedor, Moneda, Subtotal, ITBIS, Total, Pagado, Saldo, Estado. The Inertia pages `sales/invoices/{index,form,show}` are created in Task 10; for this task's tests `Inertia::render` only needs the component name (Inertia testing does not require the file to exist).

- [ ] **Step 6: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (406 tests).

- [ ] **Step 7: Commit** — `feat(sales): add invoices with NCF issuance, credit limit, reservations and cancellation`

---

### Task 10: Invoices (frontend) — pages, fiscal PDF per Norma General 06-2018 art. 7, e-mail, "Facturar" from proforma

**Files:**
- Create: `resources/js/pages/sales/invoices/{index,form,show}.tsx`, `resources/js/components/erp/invoice-from-proforma-dialog.tsx`, `resources/views/pdf/sales/invoice.blade.php`, `resources/views/pdf/partials/fiscal-box.blade.php`, `tests/Feature/Sales/InvoicePdfEmailTest.php`
- Modify: `app/Http/Controllers/Sales/InvoiceController.php` (`pdf`, `send`), `routes/sales.php`, `resources/js/pages/sales/proformas/show.tsx` ("Facturar" button), `resources/js/types/sales.ts`

**Interfaces:**
- Consumes: Task 9 controller/props, Task 8 components, Task 6/7 services.
- Produces: routes `sales.invoices.pdf` (GET `/sales/invoices/{invoice}/pdf`, `sales.invoices.view` + policy), `sales.invoices.send` (POST, `sales.invoices.send`); Blade partial `pdf.partials.fiscal-box` (expects `$title`, `$ncf`, `$ncfTypeLabel`, `$expiresOn` (nullable), `$number`, `$issueDate`, `$dueDate` (nullable), `$modifiedNcf` (nullable), `$extra` array of `[label => value]`) reused by credit/debit notes and the conduce; TS type `Invoice`, `InvoiceLine`; component `InvoiceFromProformaDialog({ proforma, open, onOpenChange })` posting `quantities` to `invoice.url(proforma.id)` from `@/routes/sales/proformas`.

Printed-format rules (GC-10, NG 06-2018 art. 7): top-right box shows the **document type name** (`Factura de Crédito Fiscal` / `Factura de Consumo` / …), **NCF**, **fecha de vencimiento de la secuencia** (only when the type expires: not for B02), internal number, fecha de emisión, vencimiento (crédito); top-left the company block (RNC, razón social, nombre comercial, dirección, teléfono); below: RNC y razón social del cliente; centre: lines, totals with ITBIS and additional taxes; footer: "Original" / "Copia" label (`$copy` flag, default `Original`). Draft invoices print with the word **BORRADOR** instead of the NCF and a watermark line "Sin valor fiscal". Cancelled invoices print **ANULADA** under the NCF.

- [ ] **Step 1: Failing test `tests/Feature/Sales/InvoicePdfEmailTest.php`**
```php
<?php

use App\Models\Invoice;
use App\Models\SentEmail;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
});

test('issued invoice pdf carries the ncf, type name, sequence expiry and client tax id', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $html = app(\App\Services\Documents\DocumentPdf::class)->render('pdf.sales.invoice', app(\App\Http\Controllers\Sales\InvoiceController::class)->pdfData($i), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain($i->ncf)->toContain('Factura de Crédito Fiscal')->toContain('Válida hasta')->toContain($i->client_name)->toContain(\App\Support\FiscalId::format($i->client_tax_id))->toContain('Original')->not->toContain('BORRADOR');
    $this->get(route('sales.invoices.pdf', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Activity::inLog('invoices')->where('event', 'pdf_generated')->where('subject_id', $i->id)->exists())->toBeTrue();
});

test('draft and cancelled invoices are marked on the pdf', function () {
    actingAsRole('owner');
    $draft = Invoice::factory()->withLines(1)->create();
    $html = app(\App\Services\Documents\DocumentPdf::class)->render('pdf.sales.invoice', app(\App\Http\Controllers\Sales\InvoiceController::class)->pdfData($draft), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('BORRADOR')->toContain('Sin valor fiscal');
    $cancelled = issuedInvoice();
    app(\App\Actions\Sales\CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');
    $html = app(\App\Services\Documents\DocumentPdf::class)->render('pdf.sales.invoice', app(\App\Http\Controllers\Sales\InvoiceController::class)->pdfData($cancelled->fresh()), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('ANULADA');
});

test('sending an invoice by email attaches the pdf, sets sent_at and refuses drafts', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $i = issuedInvoice();
    $this->post(route('sales.invoices.send', $i), ['to' => ['c@x.test']])->assertRedirect()->assertSessionHas('success', 'Correo enviado a c@x.test');
    expect($i->fresh()->sent_at)->not->toBeNull()->and(SentEmail::where('mailable_id', $i->id)->first()->attachments)->toBe([$i->number.'.pdf'])->and(SentEmail::first()->subject)->toContain($i->number);
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.invoices.send', $draft), ['to' => ['c@x.test']])->assertRedirect()->assertSessionHas('error', 'Solo se pueden enviar facturas emitidas');
});
```

- [ ] **Step 2: Controller additions and routes** — `routes/sales.php`: `Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf'); Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');` before the resource. `InvoiceController::pdfData(Invoice $invoice): array` (public, used by tests) → `['invoice' => $invoice->load('lines.product', 'client', 'salesperson', 'currency'), 'lines' => …, 'totals' => …, 'currency' => code, 'copy' => 'Original']`; `pdf()` → stream `"{$number ?? 'borrador-'.$id}.pdf"` + activity `pdf_generated`; `send()` → if `! $invoice->issued_at` → `DocumentStateException('Solo se pueden enviar facturas emitidas')`; template `invoice`; vars as proformas (`numero` = number, `fecha` = issue_date); after success `forceFill(['sent_at' => $invoice->sent_at ?? now()])->save()`; back with `'Correo enviado a …'`.

- [ ] **Step 3: PDF views** — `fiscal-box.blade.php`:
```blade
<div class="box">
    <strong style="font-size: 13px;">{{ $title }}</strong><br>
    @if($ncf)<strong>NCF: {{ $ncf }}</strong><br>@else<strong style="font-size: 14px;">BORRADOR</strong><br>@endif
    @if($expiresOn)Válida hasta: {{ $expiresOn }}<br>@endif
    @if($modifiedNcf)Modifica NCF: {{ $modifiedNcf }}<br>@endif
    @if($number)No. interno: {{ $number }}<br>@endif
    Fecha de emisión: {{ $issueDate }}<br>
    @if($dueDate)Vencimiento: {{ $dueDate }}<br>@endif
    @foreach($extra as $label => $value){{ $label }}: {{ $value }}<br>@endforeach
</div>
```
`pdf/sales/invoice.blade.php`: `@extends('pdf.layout')`; `document-box`: `@include('pdf.partials.fiscal-box', ['title' => $invoice->ncf_type?->label() ?? 'Factura', 'ncf' => $invoice->ncf, 'expiresOn' => $invoice->ncf_expires_on?->format('d/m/Y'), 'modifiedNcf' => null, 'number' => $invoice->number, 'issueDate' => $invoice->issue_date->format('d/m/Y'), 'dueDate' => $invoice->payment_terms->value === 'credit' ? $invoice->due_date->format('d/m/Y') : null, 'extra' => ['Condición' => $invoice->payment_terms->label(), 'Vendedor' => $invoice->salesperson->name, 'Moneda' => $currency]])`; after the box, when `$invoice->status->value === 'cancelled'`: `<div style="color:#b00; font-weight:bold; font-size:16px;">ANULADA</div>`; `content`: client block using the snapshots when issued (`client_name`, `client_tax_id` formatted with `FiscalId::format`, `client_tax_id_type`, `client_address`) else the live client; lines-table; totals; `@if(! $invoice->ncf)<p class="muted">Sin valor fiscal.</p>@endif`; notes; `<p class="muted" style="margin-top:10px;">{{ $copy }}</p>`.

- [ ] **Step 4: Pages**

`types/sales.ts`: `InvoiceLine = ProformaLine minus invoiced_quantity plus { dispatched_quantity: string; returned_quantity: string; credited_quantity: string }`; `Invoice = DocumentTotals & { id, number: string | null, kind, kind_label, status, status_label, fiscal_mode, ncf: string | null, ncf_type: string | null, ncf_expires_on, client_id, client_name, client_tax_id, salesperson_id, proforma_id, warehouse_id, currency_id, exchange_rate, issue_date, due_date, payment_terms, credit_days, paid_amount, credited_amount, debited_amount, balance, dispatch_status, notes, issued_at, sent_at, cancelled_at, cancel_reason, client?, salesperson?, currency?, warehouse?: {id, name}, proforma?: {id, number} | null, canceller?: {name} | null, lines?: InvoiceLine[] }`.
- `index.tsx`: columns Número (link; "Borrador" when null), NCF, Fecha, Vencimiento, Cliente (`client_name ?? client.business_name`), Vendedor, Total, Saldo, Estado; filters status/client/salesperson/dates/kind ("Ventas" / "Históricas"); "Nueva factura" when `can('sales.invoices.create')`; `ExportMenu`.
- `form.tsx`: like the proforma form plus Almacén `Select` (`warehouses`, default `defaultWarehouseId`), Condición (`Select` Contado/Crédito), Días de crédito (`Input type=number min 0 max 365`, disabled and forced to 0 when Contado; default = picked client's `credit_days`). Buttons "Guardar borrador" and, when editing, "Emitir factura" (opens the issue dialog below). `formOptions` on the backend: `warehouses` (active `id, name`), `defaultWarehouseId`, `paymentTerms` (`[{value,label}]`).
- `show.tsx`: `DocumentHeader` (meta: NCF, Tipo, Cliente, RNC, Vendedor, Fecha, Vencimiento, Condición, Moneda, Saldo); actions: "Editar" (draft), "Emitir" (draft, `can.issue`) → `Dialog` "Emitir factura" with the text "Se asignará el NCF y se reservará el inventario. Esta acción no se puede deshacer." and, when `can.creditOverride`, a `Checkbox` "Autorizar exceso de límite de crédito" + `Input` Motivo; on submit `router.post(issue.url(id), { credit_override, credit_override_reason })`; "Anular" (`can.cancel`, open invoices) → `Dialog` with `Textarea` Motivo (required); "PDF"; "Enviar por correo" (issued); "Nuevo conduce" / "Nota de crédito" / "Nota de débito" / "Registrar cobro" `Link`s shown by `can.*` (targets exist from Tasks 11–14; until then the links are rendered but hidden by `can.*` because the controller returns `false` for them — Tasks 11–14 flip each flag); `ConfirmDelete` (draft). Tabs: Líneas (read-only editor; extra columns Despachado/Devuelto from the line fields), Correos, Historial. Alerts: "Esta factura supera el límite de crédito del cliente" is NOT shown client-side (server authority); show `cancel_reason` in a destructive `Alert` when cancelled.
- `invoice-from-proforma-dialog.tsx`: table of proforma lines with Producto, Cantidad, Facturado, Pendiente, and an `Input` "A facturar" per line (default = pending); submit "Crear borrador de factura". Add the "Facturar" button to `proformas/show.tsx` when `can.invoice` (status approved and `sales.invoices.create`); Task 8's `ProformaController::show` already returns `can.invoice`.

- [ ] **Step 5: Generate, gate** — `php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (409 tests).

- [ ] **Step 6: Commit** — `feat(sales): add invoice pages, fiscal PDF and email sending`

---

### Task 11: Credit notes (B04) — returns to stock, corrections and discounts against an issued invoice

**Files:**
- Create: migrations `2026_11_01_000016_create_credit_notes_table.php`, `2026_11_01_000017_create_credit_note_lines_table.php`; `app/Enums/CreditNoteReason.php`; `app/Models/CreditNote.php`, `app/Models/CreditNoteLine.php`; `app/Policies/CreditNotePolicy.php`; `app/Http/Requests/Sales/StoreCreditNoteRequest.php`; `app/Actions/Sales/IssueCreditNote.php`; `app/Http/Controllers/Sales/CreditNoteController.php`; `resources/views/pdf/sales/credit-note.blade.php`; `resources/js/pages/sales/credit-notes/{index,form,show}.tsx`; `tests/Feature/Sales/CreditNoteTest.php`
- Modify: `routes/sales.php`, `app/Providers/AppServiceProvider.php`, `app/Models/Invoice.php` (`creditNotes(): HasMany`), `app/Http/Controllers/Sales/InvoiceController.php` (`can.creditNote`), `resources/js/types/sales.ts`, `resources/js/pages/sales/invoices/show.tsx` (button target), `tests/Support/helpers.php`

**Interfaces:**
- Consumes: Task 9 (`Invoice`, `InvoiceLine`, `refreshFinancials`), Task 4 (`InventoryService::returnStock`, `release`), Task 3 (`NcfTypeResolver::forCreditNote`, `FiscalNumberingService`), Task 8 services, Task 10 fiscal-box partial.
- Produces: enum `CreditNoteReason: string { Return='return'; Correction='correction'; Discount='discount' }` (labels `Devolución de mercancía`, `Corrección / anulación parcial`, `Descuento posterior`); `CreditNote` (`number`, `status` (`issued|cancelled` — only `issued` in this phase), `ncf`, `ncf_type`, `ncf_expires_on`, `ncf_sequence_id`, `fiscal_mode`, `invoice_id`, `modified_ncf`, `client_id`, `salesperson_id`, `warehouse_id`, `currency_id`, `exchange_rate`, `issue_date`, `reason`, totals, `notes`, `issued_at`, `created_by`; `VIEW_ALL_PERMISSION = 'sales.invoices.view-all'`; `mutableWhenIssued()` → `['status']`); `CreditNoteLine` (`invoice_line_id` + line amounts); `IssueCreditNote::handle(array $data, Invoice $invoice, User $actor): CreditNote`; routes `sales.credit-notes.{index,create,store,show,pdf,export}` (`create` takes `?invoice=` query); helper `validCreditNotePayload(Invoice $invoice, array $over = []): array`; accounting event `credit_note_issued` payload `{number, ncf, ncf_type, modified_ncf, invoice_id, invoice_number, client_id, reason, returns_stock, issue_date, subtotal, discount_total, taxable_total, itbis_total, additional_tax_total, total, total_base, currency, exchange_rate, returned_cost}`.

Rules: the invoice must be `issued`/`partially_paid`/`paid` (not draft/cancelled, not historical-with-no-lines unless reason `discount`); every line references an **invoice line** (`invoice_line_id`) and reuses its `product_id`, `description` (editable), `itbis_rate` snapshot (passed to the calculator as `LineInput::$itbisRate`); `unit_price ≤` the invoice line's `unit_price` (`La nota no puede superar el precio facturado (X).`); per reason: `return` → `quantity ≤ dispatched_quantity − returned_quantity` (`Solo se pueden devolver unidades ya despachadas (despachadas N, devueltas M).`), stock re-enters via `returnStock` with `unitCost` = `Decimal::div(dispatched_cost, dispatched_quantity, 4)` and lot (`lot_id` required when the product tracks lots: `Indica el lote al que regresa la mercancía.`), `returned_quantity += q`; `correction` → `quantity ≤ quantity − dispatched_quantity − credited_quantity` (`Cantidad pendiente de despacho: N.`), releases the line's open reservation by `q` (`InventoryService::release($reservation, q)` on the open reservation of that line), `credited_quantity += q`; `discount` → `quantity ≤ invoice line quantity`, no stock effect. Whole-document cap: `total ≤ invoice.balance` → `DocumentStateException("La nota de crédito (RD$ X) supera el saldo pendiente de la factura (RD$ Y).")` (formatted 2 dp in the invoice currency symbol via `DocumentPdf::money`). On success: `number` (`NC-`), NCF B04 (never expires → `ncf_expires_on null`), `modified_ncf = invoice.ncf`, `issued_at now`, invoice `credited_amount += total`, `refreshFinancials()`, activity on the note `issued` and on the invoice `credit_note_applied` (`properties.credit_note_id, total`), event `credit_note_issued`. Historical invoices (no lines) accept only reason `discount` with a single synthetic line? **No**: historical invoices have no lines, so a credit note on them uses `reason = discount` and a free line (`product_id` any active product, `invoice_line_id` null) — the request allows `invoice_line_id` nullable only when `invoice.kind === historical`.

- [ ] **Step 1: Helper** — append to `tests/Support/helpers.php`:
```php
function validCreditNotePayload(\App\Models\Invoice $invoice, array $over = []): array
{
    $line = $invoice->lines->first();

    return array_merge([
        'invoice_id' => $invoice->id, 'issue_date' => now()->toDateString(), 'reason' => 'discount', 'warehouse_id' => $invoice->warehouse_id, 'notes' => '',
        'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => '1', 'unit_price' => '10', 'discount_percent' => '0', 'lot_id' => '']],
    ], $over);
}
```

- [ ] **Step 2: Failing tests `tests/Feature/Sales/CreditNoteTest.php`**
```php
<?php

use App\Enums\InvoiceStatus;
use App\Models\AccountingEvent;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    activeSequence('B04', ['range_to' => 100]);
});

test('a discount credit note is issued with B04, reduces the invoice balance and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice(); // 2 × 100 + 18 % = 236.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => 'Descuento', 'quantity' => '2', 'unit_price' => '10', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('success', 'Nota de crédito NC-000001 emitida con NCF B0400000001');
    $n = CreditNote::firstOrFail();
    expect($n->ncf)->toBe('B0400000001')->and($n->modified_ncf)->toBe($i->ncf)->and($n->total)->toBe('23.60')->and($n->ncf_expires_on)->toBeNull()->and($n->issued_at)->not->toBeNull()
        ->and($i->fresh()->credited_amount)->toBe('23.60')->and($i->fresh()->balance)->toBe('212.40')->and($i->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(AccountingEvent::where('type', 'credit_note_issued')->first()->payload['modified_ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('credit_notes')->where('event', 'issued')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'credit_note_applied')->where('subject_id', $i->id)->exists())->toBeTrue()
        ->and(StockMovement::where('type', 'return')->count())->toBe(0);
    expect(fn () => $n->update(['notes' => 'x']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
});

test('a credit note cannot exceed the invoice balance nor the invoiced unit price', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => '200.00'])->save();
    $i->refreshFinancials(); // balance 36.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('error', 'La nota de crédito (RD$ 118.00) supera el saldo pendiente de la factura (RD$ 36.00).');
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100.5', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.unit_price' => 'La nota no puede superar el precio facturado (100.0000).']);
    expect(CreditNote::count())->toBe(0)->and(\App\Models\NcfSequence::where('ncf_type', 'B04')->first()->next_number)->toBe(1);
});

test('a return cannot exceed the dispatched quantity and re-enters stock at the dispatch cost', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Solo se pueden devolver unidades ya despachadas (despachadas 0.0000, devueltas 0.0000).']);

    // simulate a confirmed dispatch of 2 units at cost 50 (Task 13 does this through dispatches)
    app(\App\Services\Inventory\InventoryService::class)->dispatch(new \App\Support\Inventory\StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(\App\Services\Inventory\InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();

    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('success');
    $m = StockMovement::where('type', 'return')->firstOrFail();
    expect($m->quantity)->toBe('1.0000')->and($m->unit_cost)->toBe('50.0000')->and($m->reference_type)->toBe(CreditNote::class)
        ->and(StockBalance::where('product_id', $line->product_id)->whereNull('lot_id')->first()->quantity)->toBe('999.0000')
        ->and($line->fresh()->returned_quantity)->toBe('1.0000')->and($i->fresh()->balance)->toBe('118.00')
        ->and(AccountingEvent::where('type', 'credit_note_issued')->first()->payload['returned_cost'])->toBe('50.00');
});

test('a correction releases the reservation of undispatched units', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $svc = app(\App\Services\Inventory\InventoryService::class);
    expect($svc->available($line->product_id, $i->warehouse_id))->toBe('998.0000');
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'correction', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '3', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente de despacho: 2.0000.']);
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'correction', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect();
    expect($svc->available($line->product_id, $i->warehouse_id))->toBe('1000.0000')->and($line->fresh()->credited_quantity)->toBe('2.0000')
        ->and($i->fresh()->balance)->toBe('0.00')->and($i->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('credit notes require an open invoice, respect scoping and render index, show, pdf and export', function () {
    actingAsRole('accounting');
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($draft))->assertSessionHasErrors(['invoice_id' => 'La factura debe estar emitida.']);
    $i = issuedInvoice();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertRedirect();
    $n = CreditNote::firstOrFail();
    $this->get(route('sales.credit-notes.index'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/index')->has('rows.data', 1));
    $this->get(route('sales.credit-notes.show', $n))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/show')->where('creditNote.ncf', 'B0400000001')->has('history'));
    $this->get(route('sales.credit-notes.create', ['invoice' => $i->id]))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/form')->where('invoice.id', $i->id)->has('invoice.lines', 1));
    $this->get(route('sales.credit-notes.pdf', $n))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('sales.credit-notes.export', ['format' => 'csv']))->assertOk();
    salesUserWithSalesperson();
    $this->get(route('sales.credit-notes.show', $n))->assertForbidden();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertForbidden();
});
```

- [ ] **Step 3: Migrations, enum, models, policy**

`credit_notes` (000016): `id()`, `string('number', 12)->unique()`, `string('status', 20)->default('issued')->index()`, `string('fiscal_mode', 10)`, `string('ncf', 11)->unique()`, `string('ncf_type', 3)`, `date('ncf_expires_on')->nullable()`, `foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete()`, `foreignId('invoice_id')->constrained('invoices')->restrictOnDelete()`, `string('modified_ncf', 11)->nullable()`, `foreignId('client_id')->constrained('clients')->restrictOnDelete()`, `foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete()`, `foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete()`, `foreignId('currency_id')->constrained('currencies')->restrictOnDelete()`, `decimal('exchange_rate', 12, 6)`, `date('issue_date')->index()`, `string('reason', 20)`, `DocumentSchema::totals($table)`, `decimal('returned_cost', 14, 2)->default(0)`, `text('notes')->nullable()`, `timestamp('issued_at')->nullable()`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`.
`credit_note_lines` (000017): `id()`, `foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete()`, `foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete()`, `foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete()`, `DocumentSchema::lineAmounts($table)`, `timestamps()`, `unique(['credit_note_id', 'line_number'])`.
Models with the usual traits (`CreditNote`: `HasFactory` not needed, `ImmutableWhenIssued`, `LogsModelActivity`, `VisibleToSalesperson`; casts; relations `invoice, client, salesperson, warehouse, currency, creator, lines`); `CreditNoteLine` (`creditNote, invoiceLine, product, lot`). `Invoice::creditNotes(): HasMany`. `CreditNotePolicy extends SalesDocumentPolicy` (`sales.invoices.view-all`).

- [ ] **Step 4: Request and action**

`StoreCreditNoteRequest` (`use ValidatesDocumentLines`; `authorize()`: `$this->user()->can('sales.credit-notes.create')`): rules `invoice_id` → `['required', 'integer', 'exists:invoices,id']`, `issue_date` → `['required', 'date_format:Y-m-d']`, `reason` → `['required', Rule::enum(CreditNoteReason::class)]`, `warehouse_id` → `['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)]`, `notes` → `['nullable', 'string', 'max:2000']`, lines (`lineRules()`) + `lines.*.invoice_line_id` → `['nullable', 'integer', 'exists:invoice_lines,id']`, `lines.*.lot_id` → `['nullable', 'integer', 'exists:lots,id']`; `withValidator` → `after`: load the invoice (`with('lines')`); `! in_array(status, [issued, partially_paid, paid])` → `invoice_id`: `La factura debe estar emitida.`; `! Gate::allows('view', $invoice)` → `invoice_id`: `No puedes emitir notas sobre facturas de otros vendedores.`; `issue_date < invoice.issue_date` → `issue_date`: `La nota no puede ser anterior a la factura.`; per line: when `invoice.kind === sale` → `invoice_line_id` required and must belong to the invoice (`Línea de factura inválida.`), `product_id` must equal the invoice line's product (`El producto no coincide con la línea facturada.`), `unit_price > line.unit_price` → `La nota no puede superar el precio facturado ({unit_price}).`, reason-specific quantity caps with the messages from the rules, `return` + product `tracks_lots` + empty `lot_id` → `lines.{i}.lot_id`: `Indica el lote al que regresa la mercancía.`; when `kind === historical` → reason must be `discount` (`reason`: `Las facturas históricas solo admiten notas de descuento.`) and `invoice_line_id` must be null.
`IssueCreditNote::handle(array $data, Invoice $invoice, User $actor): CreditNote` — `DB::transaction`: lock the invoice; `exchange_rate` = invoice's rate (same currency, same rate — a credit note on a USD invoice uses the invoice rate so balances match); compute with `LineInput(product_id, quantity, unit_price, discount_percent, description, itbisRate: invoiceLine?->itbis_rate)`; cap check against `balance`; `number`, `assign(NcfType::B04, issue_date)`; create note (`status issued`, `fiscal_mode ncf`, `modified_ncf = invoice.ncf`, `client_id/salesperson_id/currency_id` from the invoice, `issued_at now`); `DocumentLines::write($note, $computed, [['invoice_line_id' => …, 'lot_id' => …], …])`; apply per-reason effects (`returnStock(new StockLine(product_id, warehouse_id ?? invoice.warehouse_id, qty, unitCost, lotId), $note, $actor, "NC {$number}")`, `release`, counters) accumulating `returned_cost` (Σ movement `total_cost`); `invoice->forceFill(['credited_amount' => + total])->save(); $invoice->refreshFinancials();` activity `issued` on the note (`"Nota de crédito {$number} emitida con NCF {$ncf}"`), `credit_note_applied` on the invoice, event `credit_note_issued`.

- [ ] **Step 5: Routes, controller, PDF, pages**

Routes: `credit-notes/export`, `credit-notes/{creditNote}/pdf`, `Route::resource('credit-notes', CreditNoteController::class)->only(['index', 'create', 'store', 'show'])->parameters(['credit-notes' => 'creditNote'])`. Permissions: `index/show/pdf` → `sales.credit-notes.view` (+ policy `view`), `create/store` → `sales.credit-notes.create`, `export` → `sales.credit-notes.export`. `create` requires `?invoice=` (else redirect to invoices index with error `Selecciona la factura a la que aplica la nota`); props `invoice` (with `lines` incl. `dispatched_quantity`, `returned_quantity`, `credited_quantity`, `product.tracks_lots`), `reasons`, `warehouses`, `lots` (for lot-tracked products: `Lot` rows `id, code, expires_on, product_id`). `store` → `"Nota de crédito {$number} emitida con NCF {$ncf}"` → show. `show` props `creditNote` (+ lines, invoice `id, number, ncf`, client, salesperson, currency), `history`. `pdf` view `pdf.sales.credit-note` (fiscal-box `title` `Nota de Crédito`, `modifiedNcf` = `modified_ncf`, `extra` `['Motivo' => reason label, 'Factura' => invoice number]`), activity `pdf_generated`. `export` columns: Número, NCF, Factura, NCF modificado, Fecha, Cliente, Motivo, Moneda, Subtotal, ITBIS, Total.
Pages: `index` (Número, NCF, Factura, Fecha, Cliente, Motivo, Total; filters client/dates/reason); `form` (invoice summary card; Motivo `Select`; Almacén (shown for `return`); lines table built from the invoice lines with inputs Cantidad / Precio / Lote (`Select` of the product's lots, only when `tracks_lots` and reason `return`) and a `Checkbox` "Incluir" per line; `useTotalsPreview` over the included lines (passing `itbis_rate` from the invoice line); "Emitir nota de crédito" with an `AlertDialog` "Se asignará un NCF B04. Esta acción no se puede deshacer."); `show` (header, lines read-only, PDF button, Historial). Invoice show: `can.creditNote = can('sales.credit-notes.create') && invoice open/paid` → "Nota de crédito" links to `create.url({ query: { invoice: id } })`.

- [ ] **Step 6: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (414 tests).

- [ ] **Step 7: Commit** — `feat(sales): add credit notes with returns, corrections and discounts`

---

### Task 12: Debit notes (B03)

**Files:**
- Create: migrations `2026_11_01_000018_create_debit_notes_table.php`, `2026_11_01_000019_create_debit_note_lines_table.php`; `app/Enums/DebitNoteReason.php`; `app/Models/DebitNote.php`, `app/Models/DebitNoteLine.php`; `app/Policies/DebitNotePolicy.php`; `app/Http/Requests/Sales/StoreDebitNoteRequest.php`; `app/Actions/Sales/IssueDebitNote.php`; `app/Http/Controllers/Sales/DebitNoteController.php`; `resources/views/pdf/sales/debit-note.blade.php`; `resources/js/pages/sales/debit-notes/{index,form,show}.tsx`; `tests/Feature/Sales/DebitNoteTest.php`
- Modify: `routes/sales.php`, `app/Providers/AppServiceProvider.php`, `app/Models/Invoice.php` (`debitNotes()`), `app/Http/Controllers/Sales/InvoiceController.php` (`can.debitNote`), `resources/js/types/sales.ts`, `tests/Support/helpers.php`

**Interfaces:**
- Produces: enum `DebitNoteReason: string { PriceCorrection='price_correction'; Interest='interest'; Freight='freight'; Other='other' }` (labels `Corrección de precio`, `Intereses por mora`, `Flete`, `Otro cargo`); `DebitNote`/`DebitNoteLine` (same columns as credit notes without `warehouse_id`, `returned_cost`, `lot_id`; `invoice_line_id` nullable — lines may reference any active product); `IssueDebitNote::handle(array $data, Invoice $invoice, User $actor): DebitNote`; routes `sales.debit-notes.{index,create,store,show,pdf,export}`; helper `validDebitNotePayload(Invoice $invoice, array $over = [])`; event `debit_note_issued` payload `{number, ncf, ncf_type, modified_ncf, invoice_id, invoice_number, client_id, reason, issue_date, subtotal, discount_total, taxable_total, itbis_total, additional_tax_total, total, total_base, currency, exchange_rate}`.

Rules: invoice must be `issued|partially_paid|paid`; lines are free (any active product, quantity > 0, unit price > 0); the note increases the invoice's receivable: `debited_amount += total`, `refreshFinancials()` (a paid invoice returns to `partially_paid`). NCF `B03` (expires like B01: `ncf_expires_on` from the sequence). Activity `issued` on the note and `debit_note_applied` on the invoice.

- [ ] **Step 1: Helper** — append to `tests/Support/helpers.php` (FQCNs as in the other helpers) `validDebitNotePayload(\App\Models\Invoice $invoice, array $over = []): array` → `['invoice_id' => $invoice->id, 'issue_date' => now()->toDateString(), 'reason' => 'freight', 'notes' => '', 'lines' => [['product_id' => \App\Models\Product::factory()->create(['name' => 'Flete', 'itbis_rate' => '18.00'])->id, 'description' => 'Flete Santiago', 'quantity' => '1', 'unit_price' => '500', 'discount_percent' => '0']]]` merged with `$over`.

- [ ] **Step 2: Failing tests `tests/Feature/Sales/DebitNoteTest.php`**
```php
<?php

use App\Enums\InvoiceStatus;
use App\Models\AccountingEvent;
use App\Models\DebitNote;
use App\Models\Invoice;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    activeSequence('B03', ['range_to' => 100]);
});

test('a debit note is issued with B03, increases the invoice balance and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => '236.00'])->save();
    $i->refreshFinancials();
    expect($i->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect()->assertSessionHas('success', 'Nota de débito ND-000001 emitida con NCF B0300000001');
    $n = DebitNote::firstOrFail();
    expect($n->ncf)->toBe('B0300000001')->and($n->modified_ncf)->toBe($i->ncf)->and($n->total)->toBe('590.00')->and($n->ncf_expires_on)->not->toBeNull()
        ->and($i->fresh()->debited_amount)->toBe('590.00')->and($i->fresh()->balance)->toBe('590.00')->and($i->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(AccountingEvent::where('type', 'debit_note_issued')->first()->payload['total'])->toBe('590.00')
        ->and(Activity::inLog('debit_notes')->where('event', 'issued')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'debit_note_applied')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $n->delete())->toThrow(\App\Exceptions\ImmutableDocumentException::class);
});

test('debit notes require an open invoice, valid lines and permission', function () {
    actingAsRole('accounting');
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($draft))->assertSessionHasErrors(['invoice_id' => 'La factura debe estar emitida.']);
    $i = issuedInvoice();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['lines' => [], 'reason' => 'nope']))->assertSessionHasErrors(['lines', 'reason']);
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['issue_date' => $i->issue_date->subDay()->toDateString()]))->assertSessionHasErrors(['issue_date' => 'La nota no puede ser anterior a la factura.']);
    salesUserWithSalesperson();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertForbidden();
});

test('index, show, pdf and export work and are scoped', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect();
    $n = DebitNote::firstOrFail();
    $this->get(route('sales.debit-notes.index'))->assertInertia(fn ($p) => $p->component('sales/debit-notes/index')->has('rows.data', 1));
    $this->get(route('sales.debit-notes.show', $n))->assertInertia(fn ($p) => $p->component('sales/debit-notes/show')->where('debitNote.ncf', 'B0300000001'));
    $this->get(route('sales.debit-notes.create', ['invoice' => $i->id]))->assertInertia(fn ($p) => $p->component('sales/debit-notes/form')->where('invoice.id', $i->id));
    $this->get(route('sales.debit-notes.pdf', $n))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('sales.debit-notes.export', ['format' => 'xlsx']))->assertOk();
    salesUserWithSalesperson();
    $this->get(route('sales.debit-notes.show', $n))->assertForbidden();
});
```

- [ ] **Step 3: Implement** — mirror Task 11 exactly (migrations `debit_notes` / `debit_note_lines` with the column differences above; `StoreDebitNoteRequest` without the per-reason quantity logic — `invoice_id` open check, scoping check, `issue_date` check, `reason` enum, `lineRules()`; `IssueDebitNote` without stock effects: `number` (`ND-`), `assign(NcfType::B03, issue_date)`, `modified_ncf`, `invoice.debited_amount += total`, `refreshFinancials()`, activities `issued` / `debit_note_applied`, event `debit_note_issued`; controller with the same six routes and permissions `sales.debit-notes.*`; flash `"Nota de débito {$number} emitida con NCF {$ncf}"`; PDF `pdf.sales.debit-note` with fiscal-box `title` `Nota de Débito` and `expiresOn` from `ncf_expires_on`; pages copied from credit notes with free product lines through `DocumentLinesEditor` + `ProductPicker` (`clientId` = invoice client); invoice show `can.debitNote`).

- [ ] **Step 4: Migrate, generate, gate** — same command as Task 11 → passed (417 tests).

- [ ] **Step 5: Commit** — `feat(sales): add debit notes`

---

### Task 13: Dispatches (conduces) — partial deliveries with FEFO lots, confirmation = stock exit + COGS event, PDF

**Files:**
- Create: migrations `2026_11_01_000020_create_dispatches_table.php`, `2026_11_01_000021_create_dispatch_lines_table.php`; `app/Enums/DispatchStatus.php`; `app/Models/Dispatch.php`, `app/Models/DispatchLine.php`; `app/Http/Requests/Inventory/StoreDispatchRequest.php`; `app/Actions/Inventory/SaveDispatch.php`, `app/Actions/Inventory/ConfirmDispatch.php`; `app/Http/Controllers/Inventory/DispatchController.php`; `resources/views/pdf/inventory/dispatch.blade.php`; `resources/js/pages/inventory/dispatches/{index,form,show}.tsx`; `resources/js/types/inventory.ts` (append); `tests/Feature/Inventory/DispatchTest.php`
- Modify: `routes/inventory.php`, `app/Models/Invoice.php` (`dispatches()`, `refreshDispatchStatus()`), `app/Http/Controllers/Sales/InvoiceController.php` (`can.dispatch`, show prop `dispatches`), `resources/js/pages/sales/invoices/show.tsx` (Conduces tab), `tests/Support/helpers.php`

**Interfaces:**
- Consumes: Task 4 (`InventoryService::dispatch/suggestLots/release`), Task 9 (`InvoiceLine` counters, reservations), Task 6 PDF, Task 10 fiscal-box.
- Produces: enum `DispatchStatus: string { Draft='draft'; Confirmed='confirmed' }` (`Borrador`, `Confirmado`); `Dispatch` (`number` nullable until confirmed, `status`, `invoice_id`, `warehouse_id`, `client_id`, `dispatch_date`, `driver_name`, `vehicle`, `notes`, `total_cost`, `confirmed_at`, `confirmed_by`, `created_by`; `ImmutableWhenIssued` with `issuedAtColumn()` → `'confirmed_at'`, `mutableWhenIssued()` → `[]`); `DispatchLine` (`dispatch_id`, `invoice_line_id`, `product_id`, `lot_id`, `quantity`, `unit_cost`, `total_cost`); `SaveDispatch::handle(array $data, Invoice $invoice, User $actor, ?Dispatch $dispatch = null): Dispatch`; `ConfirmDispatch::handle(Dispatch $dispatch, User $actor): Dispatch`; `Invoice::refreshDispatchStatus(): void` (`none` when Σ dispatched = 0, `complete` when every line `dispatched_quantity + credited_quantity ≥ quantity`, else `partial`); routes `inventory.dispatches.{index,create,store,show,edit,update,destroy,confirm,pdf,export}` (`create` takes `?invoice=`); helper `validDispatchPayload(Invoice $invoice, array $over = [])`; accounting event `dispatch_confirmed` payload `{number, invoice_id, invoice_number, ncf, client_id, dispatch_date, warehouse_id, lines: [{product_id, sku, lot_id, quantity, unit_cost, total_cost}], total_cost, currency: 'DOP', exchange_rate: '1.000000'}`.

Rules: the invoice must be `issued|partially_paid|paid` (`Solo se despachan facturas emitidas`); a dispatch always uses the invoice's warehouse; a draft dispatch may be edited/deleted; **confirm** validates per line `quantity ≤ invoice_line.remainingToDispatch() − Σ quantity in other confirmed dispatches` (already reflected in `dispatched_quantity`, so simply `≤ remainingToDispatch()`), requires `lot_id` for lot-tracked products, then for each line: `InventoryService::dispatch(new StockLine(product_id, warehouse_id, quantity, null, lot_id), $dispatch, $actor, "Conduce {$number}")`, releases the invoice line's open reservation by `quantity` (`release($reservation, $quantity)` — the reservation is warehouse-level so lots do not matter), updates `invoice_line.dispatched_quantity += q`, `dispatched_cost += movement.total_cost`, stores `unit_cost/total_cost` on the dispatch line; assigns `number` (`CND-`), `confirmed_at/by`, status; `invoice->refreshDispatchStatus()`; activity `confirmed` on the dispatch and `dispatched` on the invoice (`properties.dispatch_id, number`); event `dispatch_confirmed`. The FEFO suggestion (`suggestLots`) pre-fills lot rows in the form; the warehouse user can change the lot. A line with quantity 0 is dropped. Visibility: `inventory.dispatches.view` (no salesperson scoping; sales users reach conduces through the invoice page, which is scoped).

- [ ] **Step 1: Helper**
```php
function validDispatchPayload(\App\Models\Invoice $invoice, array $over = []): array
{
    return array_merge([
        'invoice_id' => $invoice->id, 'dispatch_date' => now()->toDateString(), 'driver_name' => 'Pedro', 'vehicle' => 'A123456', 'notes' => '',
        'lines' => $invoice->lines->map(fn ($l) => ['invoice_line_id' => $l->id, 'quantity' => $l->quantity, 'lot_id' => ''])->all(),
    ], $over);
}
```

- [ ] **Step 2: Failing tests `tests/Feature/Inventory/DispatchTest.php`**
```php
<?php

use App\Enums\DispatchStatus;
use App\Models\AccountingEvent;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Inventory\InventoryService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('warehouse creates a draft conduce from an issued invoice and confirms a partial dispatch', function () {
    $user = actingAsRole('warehouse');
    $i = issuedInvoice(); // 2 units reserved, stock 1000 @ 50
    $line = $i->lines->first();
    $this->get(route('inventory.dispatches.create', ['invoice' => $i->id]))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/dispatches/form')->where('invoice.number', $i->number)->where('invoice.lines.0.remaining', '2.0000'));
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]))->assertRedirect()->assertSessionHas('success', 'Conduce guardado como borrador');
    $d = Dispatch::firstOrFail();
    expect($d->status)->toBe(DispatchStatus::Draft)->and($d->number)->toBeNull()->and($d->warehouse_id)->toBe($i->warehouse_id)->and($d->lines()->count())->toBe(1);

    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect(route('inventory.dispatches.show', $d))->assertSessionHas('success', 'Conduce CND-000001 confirmado');
    $d->refresh();
    $line->refresh();
    expect($d->status)->toBe(DispatchStatus::Confirmed)->and($d->number)->toBe('CND-000001')->and($d->confirmed_by)->toBe($user->id)->and($d->total_cost)->toBe('50.00')
        ->and($line->dispatched_quantity)->toBe('1.0000')->and($line->dispatched_cost)->toBe('50.00')->and($i->fresh()->dispatch_status)->toBe('partial')
        ->and(StockMovement::where('type', 'dispatch')->where('reference_id', $d->id)->first()->quantity)->toBe('1.0000')
        ->and(StockReservation::whereNull('released_at')->first()->quantity)->toBe('1.0000')
        ->and(app(InventoryService::class)->available($line->product_id, $i->warehouse_id))->toBe('998.0000')
        ->and(AccountingEvent::where('type', 'dispatch_confirmed')->first()->payload['total_cost'])->toBe('50.00')
        ->and(Activity::inLog('dispatches')->where('event', 'confirmed')->where('subject_id', $d->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'dispatched')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $d->update(['notes' => 'x']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
    $this->post(route('inventory.dispatches.confirm', $d))->assertSessionHas('error', 'El conduce está Confirmado y no admite esta acción');

    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]))->assertRedirect();
    $this->post(route('inventory.dispatches.confirm', Dispatch::latest('id')->first()))->assertRedirect();
    expect($i->fresh()->dispatch_status)->toBe('complete')->and(StockReservation::whereNull('released_at')->count())->toBe(0);
});

test('a dispatch cannot exceed the invoiced quantity', function () {
    actingAsRole('warehouse');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '3', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente de despacho: 2.0000.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines' => 'Indica al menos una cantidad a despachar.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload(Invoice::factory()->withLines(1)->create()))->assertSessionHasErrors(['invoice_id' => 'Solo se despachan facturas emitidas']);
});

test('lot-tracked products get a fefo suggestion and confirm is refused when the lot has no stock', function () {
    $user = actingAsRole('warehouse');
    activeSequence('B01', ['range_to' => 100]);
    $product = Product::factory()->create(['tracks_lots' => true, 'itbis_rate' => '18.00']);
    $wh = \App\Models\Warehouse::factory()->create(['is_default' => true]);
    $svc = app(InventoryService::class);
    $svc->receive(new \App\Support\Inventory\StockLine($product->id, $wh->id, '5', '10', null, 'LATE', '2027-06-30'), \App\Enums\StockMovementType::Initial, $user, $user);
    $svc->receive(new \App\Support\Inventory\StockLine($product->id, $wh->id, '1', '10', null, 'SOON', '2026-12-01'), \App\Enums\StockMovementType::Initial, $user, $user);
    $draft = app(\App\Actions\Sales\SaveInvoice::class)->handle(validInvoicePayload(['warehouse_id' => $wh->id, 'salesperson_id' => \App\Models\Salesperson::factory()->create()->id, 'lines' => [['product_id' => $product->id, 'quantity' => '3', 'unit_price' => '100', 'discount_percent' => '0', 'description' => '']]]), $user->givePermissionTo('sales.invoices.view-all'));
    $i = app(\App\Actions\Sales\IssueInvoice::class)->handle($draft, $user);
    $line = $i->lines->first();
    $soon = Lot::where('code', 'SOON')->firstOrFail();
    $late = Lot::where('code', 'LATE')->firstOrFail();

    $this->get(route('inventory.dispatches.create', ['invoice' => $i->id]))->assertInertia(fn ($p) => $p->where('invoice.lines.0.suggested_lots.0.code', 'SOON')->where('invoice.lines.0.suggested_lots.0.quantity', '1.0000')->where('invoice.lines.0.suggested_lots.1.code', 'LATE')->where('invoice.lines.0.suggested_lots.1.quantity', '2.0000'));
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '3', 'lot_id' => '']]]))->assertSessionHasErrors(['lines.0.lot_id' => 'El producto controla lotes: indica el lote.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '2', 'lot_id' => $soon->id]]]))->assertRedirect();
    $d = Dispatch::firstOrFail();
    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect()->assertSessionHas('error', fn (string $m) => str_starts_with($m, 'Existencia insuficiente de '.$product->sku));
    expect($d->fresh()->status)->toBe(DispatchStatus::Draft)->and(StockMovement::where('type', 'dispatch')->count())->toBe(0);

    $this->put(route('inventory.dispatches.update', $d), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => $soon->id], ['invoice_line_id' => $line->id, 'quantity' => '2', 'lot_id' => $late->id]]]))->assertRedirect();
    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect()->assertSessionHas('success');
    expect(StockMovement::where('type', 'dispatch')->count())->toBe(2)->and($line->fresh()->dispatched_quantity)->toBe('3.0000')->and($i->fresh()->dispatch_status)->toBe('complete');
});

test('permissions, drafts, pdf, index and export', function () {
    actingAsRole('sales');
    $i = issuedInvoice();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i))->assertForbidden();
    actingAsRole('warehouse');
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i))->assertRedirect();
    $d = Dispatch::firstOrFail();
    $this->get(route('inventory.dispatches.index'))->assertInertia(fn ($p) => $p->component('inventory/dispatches/index')->has('rows.data', 1));
    $this->get(route('inventory.dispatches.show', $d))->assertInertia(fn ($p) => $p->component('inventory/dispatches/show')->where('dispatch.status', 'draft')->has('history'));
    $this->get(route('inventory.dispatches.pdf', $d))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->delete(route('inventory.dispatches.destroy', $d))->assertRedirect(route('inventory.dispatches.index'))->assertSessionHas('success', 'Borrador de conduce eliminado');
    expect(Dispatch::count())->toBe(0);
    $this->get(route('inventory.dispatches.export', ['format' => 'csv']))->assertForbidden(); // warehouse never exports (P1 rule)
    actingAsRole('owner');
    $this->get(route('inventory.dispatches.export', ['format' => 'csv']))->assertOk();
});
```

- [ ] **Step 3: Migrations, enum, models** — `dispatches` (000020): `id()`, `string('number', 12)->nullable()->unique()`, `string('status', 20)->index()`, `foreignId('invoice_id')->constrained('invoices')->restrictOnDelete()`, `foreignId('client_id')->constrained('clients')->restrictOnDelete()`, `foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`, `date('dispatch_date')->index()`, `string('driver_name', 100)->nullable()`, `string('vehicle', 50)->nullable()`, `text('notes')->nullable()`, `decimal('total_cost', 14, 2)->default(0)`, `timestamp('confirmed_at')->nullable()`, `foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete()`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`. `dispatch_lines` (000021): `id()`, `foreignId('dispatch_id')->constrained('dispatches')->cascadeOnDelete()`, `foreignId('invoice_line_id')->constrained('invoice_lines')->restrictOnDelete()`, `foreignId('product_id')->constrained('products')->restrictOnDelete()`, `foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete()`, `decimal('quantity', 14, 4)`, `decimal('unit_cost', 14, 4)->default(0)`, `decimal('total_cost', 14, 2)->default(0)`, `timestamps()`. Models with `LogsModelActivity` (+ `ImmutableWhenIssued` on `Dispatch`), casts, relations (`invoice, client, warehouse, confirmer, creator, lines`; line: `dispatch, invoiceLine, product, lot`). `Invoice::dispatches(): HasMany`, `refreshDispatchStatus()`. The export permission `inventory.dispatches.export` already exists (Task 1).

- [ ] **Step 4: Request, actions, controller, PDF, pages**

`StoreDispatchRequest` (`authorize`: `can('inventory.dispatches.create')`): `invoice_id` required exists; `dispatch_date` required `date_format:Y-m-d`; `driver_name` nullable max 100; `vehicle` nullable max 50; `notes` nullable max 2000; `lines` required array min 1; `lines.*.invoice_line_id` required exists; `lines.*.quantity` required numeric min:0 regex 4dp; `lines.*.lot_id` nullable exists lots; `withValidator` → invoice open (`Solo se despachan facturas emitidas`), each line belongs to the invoice (`Línea de factura inválida.`), lot belongs to the product (`El lote no pertenece al producto.`), Σ quantity per `invoice_line_id` (across the posted lines, excluding this draft's own current lines) `≤ remainingToDispatch()` → `lines.{i}.quantity`: `Cantidad pendiente de despacho: {n}.`, product `tracks_lots` and empty lot → `lines.{i}.lot_id`: `El producto controla lotes: indica el lote.`, all quantities zero → `lines`: `Indica al menos una cantidad a despachar.`
`SaveDispatch` (drop zero lines; `client_id/warehouse_id` from the invoice; draft only when updating: `DocumentStateException('Solo se pueden editar conduces en borrador')`). `ConfirmDispatch` per the rules (`DocumentStateException("El conduce está {$label} y no admite esta acción")` when not draft; InventoryException propagates and rolls back). Controller permissions: `index/show/pdf` → `inventory.dispatches.view`; `create/store/edit/update/destroy` → `inventory.dispatches.create`; `confirm` → `inventory.dispatches.confirm`; `export` → `inventory.dispatches.export`. `create` props: `invoice` (`id, number, ncf, client_name, warehouse: {id,name}, lines: [{id, product: {id, sku, name, tracks_lots}, quantity, dispatched_quantity, credited_quantity, remaining, suggested_lots: suggestLots(product_id, warehouse_id, remaining) when tracks_lots else []}]`), `lots` (per lot-tracked product: lots with stock in the warehouse `id, code, expires_on, quantity`). Flash: store `Conduce guardado como borrador`, update `Conduce actualizado`, destroy `Borrador de conduce eliminado`, confirm `"Conduce {$number} confirmado"`. `pdf` view `pdf.inventory.dispatch` (fiscal-box `title` `CONDUCE`, `ncf` null → prints **BORRADOR** when draft; override: pass `ncf` = `$dispatch->number` so the box shows `NCF:`? **No** — add to fiscal-box a `$ncfLabel` variable defaulting to `'NCF'` and pass `'ncfLabel' => 'Conduce'` with `'ncf' => $dispatch->number`; `extra` `['Factura' => invoice number, 'NCF factura' => invoice ncf, 'Chofer' => driver_name, 'Vehículo' => vehicle]`), lines table columns Producto, Lote, Vencimiento, Cantidad (no prices, no costs), signature lines "Entregado por / Recibido por". `index` (Número, Fecha, Factura, Cliente, Almacén, Estado; filters status/dates/warehouse), `form` (invoice summary; per invoice line a sub-table of lot rows (Lote `Select` from `lots` filtered by product, Cantidad) with "Añadir lote" for lot-tracked products, a single Cantidad input otherwise; Chofer, Vehículo, Fecha, Notas; "Guardar borrador"), `show` (header, lines, buttons Confirmar (`AlertDialog` "Se descontará el inventario. Esta acción no se puede deshacer."), Editar/Eliminar (draft), PDF; Historial). Invoice show: `dispatches` prop (`id, number, status, status_label, dispatch_date`) rendered in a "Conduces" tab; `can.dispatch = can('inventory.dispatches.create') && invoice open && dispatch_status !== 'complete'`.

- [ ] **Step 5: Migrate, generate, gate** — standard command → passed (421 tests).

- [ ] **Step 6: Commit** — `feat(inventory): add dispatches with lots, confirmation and cost of goods event`

---

### Task 14: Receipts (cobros) backend — applications, retentions, advances, cancel

**Files:**
- Create: migrations `2026_11_01_000022_create_receipts_table.php`, `2026_11_01_000023_create_receipt_applications_table.php`; `app/Enums/ReceiptStatus.php`, `app/Enums/PaymentMethod.php`; `app/Models/Receipt.php`, `app/Models/ReceiptApplication.php`; `app/Policies/ReceiptPolicy.php`; `app/Http/Requests/Sales/StoreReceiptRequest.php`, `app/Http/Requests/Sales/ApplyAdvanceRequest.php`, `app/Http/Requests/Sales/CancelReceiptRequest.php`; `app/Actions/Sales/PostReceipt.php`, `app/Actions/Sales/ApplyAdvance.php`, `app/Actions/Sales/CancelReceipt.php`; `app/Http/Controllers/Sales/ReceiptController.php`; `tests/Feature/Sales/ReceiptTest.php`
- Modify: `routes/sales.php`, `app/Providers/AppServiceProvider.php`, `app/Models/Invoice.php` (`applications()`), `app/Services/Sales/ClientAccount.php` (`advanceBalance`), `tests/Support/helpers.php`

**Interfaces:**
- Consumes: Task 9 (`Invoice::refreshFinancials`), Phase 1 `FinancialAccount` (`type` bank|cash, `currency_id`, `is_active`), Task 8 services.
- Produces: enums `ReceiptStatus: string { Posted='posted'; Cancelled='cancelled' }` (`Registrado`, `Anulado`), `PaymentMethod: string { Transfer='transfer'; Check='check'; Cash='cash'; Card='card'; Advance='advance' }` (`Transferencia`, `Cheque`, `Efectivo`, `Tarjeta`, `Anticipo`); `Receipt` (`number`, `status`, `client_id`, `salesperson_id` (nullable; the client's at posting), `currency_id`, `exchange_rate`, `receipt_date`, `payment_method`, `financial_account_id` (nullable for `advance`), `reference`, `amount_received`, `retention_itbis`, `retention_isr`, `applied_total`, `unapplied_amount`, `notes`, `posted_at`, `cancelled_at`, `cancelled_by`, `cancel_reason`, `created_by`; `VIEW_ALL_PERMISSION = 'sales.invoices.view-all'`; `ImmutableWhenIssued` with `issuedAtColumn()` → `'posted_at'`, `mutableWhenIssued()` → `['status', 'applied_total', 'unapplied_amount', 'cancelled_at', 'cancelled_by', 'cancel_reason']`); `ReceiptApplication` (`receipt_id`, `invoice_id`, `amount`, `source` (`receipt|advance`), `created_by`); `PostReceipt::handle(array $data, User $actor): Receipt`; `ApplyAdvance::handle(Receipt $receipt, array $applications, User $actor): Receipt`; `CancelReceipt::handle(Receipt $receipt, User $actor, string $reason): Receipt`; `ClientAccount::advanceBalance(Client $client, Currency $currency): string` (Σ `unapplied_amount` of posted receipts in that currency); routes `sales.receipts.{index,create,store,show,cancel,apply,export}`; helper `validReceiptPayload(Invoice $invoice, array $over = [])`; events `receipt_posted` payload `{number, client_id, receipt_date, payment_method, financial_account_id, reference, amount_received, retention_itbis, retention_isr, applications: [{invoice_id, invoice_number, ncf, amount}], applied_total, unapplied_amount, currency, exchange_rate}`, `advance_applied` payload `{number, client_id, applications: [...], applied_total, currency, exchange_rate}`, `receipt_cancelled` payload `{number, client_id, reason, reversed: [{invoice_id, amount}], amount_received, currency, exchange_rate}`.

Rules (spec §3.3 cobros): a receipt is posted at creation (no draft). `credit = amount_received + retention_itbis + retention_isr` is what the client settles; applications must satisfy `Σ amount ≤ credit` (`Las aplicaciones (X) superan el monto del recibo (Y).`) and each `amount ≤ invoice.balance` (`La aplicación supera el saldo de la factura {number} ({balance}).`); the remainder `credit − Σ` becomes `unapplied_amount` (an **advance** / saldo a favor). All invoices applied must belong to the same client and the same currency as the receipt (`La factura {number} es de otro cliente.` / `La factura {number} está en otra moneda.`). Retentions are entered by the user (the client's retention certificate): `retention_itbis ≤ Σ itbis of applied invoices` (`La retención de ITBIS supera el ITBIS de las facturas aplicadas.`), `retention_isr ≤ Σ taxable of applied invoices` (`La retención de ISR supera la base de las facturas aplicadas.`). `payment_method = advance` is reserved for `ApplyAdvance`. Posting updates each invoice `paid_amount += amount` and `refreshFinancials()`. **ApplyAdvance** on a receipt with `unapplied_amount > 0`: creates applications with `source = advance`, `Σ ≤ unapplied_amount` (`Las aplicaciones (X) superan el anticipo disponible (Y).`), same client/currency/balance rules, decrements `unapplied_amount`, increments `applied_total`, updates invoices, activity `advance_applied` on the receipt, event `advance_applied`. **Cancel** (permission `sales.receipts.cancel`): reverses every application (`paid_amount −= amount`, `refreshFinancials()`), sets `applied_total 0`, `unapplied_amount 0`, status cancelled + reason, activity `cancelled`, event `receipt_cancelled`; refused when the receipt is already cancelled (`El recibo ya está anulado`). FX: the receipt stores `exchange_rate` of `receipt_date` (`DocumentCurrency::resolve`); Phase 5 computes realised differences from the events.

- [ ] **Step 1: Helper**
```php
function validReceiptPayload(\App\Models\Invoice $invoice, array $over = []): array
{
    $account = \App\Models\FinancialAccount::factory()->create(['currency_id' => $invoice->currency_id]);

    return array_merge([
        'client_id' => $invoice->client_id, 'currency_id' => $invoice->currency_id, 'receipt_date' => now()->toDateString(), 'payment_method' => 'transfer', 'financial_account_id' => $account->id,
        'reference' => 'TRX-1', 'amount_received' => $invoice->balance, 'retention_itbis' => '0', 'retention_isr' => '0', 'notes' => '',
        'applications' => [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]],
    ], $over);
}
```

- [ ] **Step 2: Failing tests `tests/Feature/Sales/ReceiptTest.php`**
```php
<?php

use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\AccountingEvent;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\Sales\ClientAccount;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a receipt applied to one invoice pays it and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($i))->assertRedirect()->assertSessionHas('success', 'Recibo REC-000001 registrado');
    $r = Receipt::firstOrFail();
    expect($r->status)->toBe(ReceiptStatus::Posted)->and($r->number)->toBe('REC-000001')->and($r->posted_at)->not->toBeNull()->and($r->applied_total)->toBe('236.00')->and($r->unapplied_amount)->toBe('0.00')
        ->and($r->salesperson_id)->toBe($i->salesperson_id)->and($i->fresh()->paid_amount)->toBe('236.00')->and($i->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(ReceiptApplication::count())->toBe(1)->and(ReceiptApplication::first()->source)->toBe('receipt')
        ->and(AccountingEvent::where('type', 'receipt_posted')->first()->payload['applications'][0]['ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('receipts')->where('event', 'posted')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'payment_applied')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $r->update(['reference' => 'x']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
});

test('partial payments, retentions and an unapplied remainder become an advance', function () {
    actingAsRole('accounting');
    $a = issuedInvoice(); // 236.00, itbis 36.00
    $b = issuedInvoice(['client_id' => $a->client_id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '300', 'retention_itbis' => '36', 'retention_isr' => '0', 'applications' => [['invoice_id' => $a->id, 'amount' => '236'], ['invoice_id' => $b->id, 'amount' => '50']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    expect($r->applied_total)->toBe('286.00')->and($r->unapplied_amount)->toBe('50.00')->and($r->retention_itbis)->toBe('36.00')
        ->and($a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($b->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($b->fresh()->balance)->toBe('186.00')
        ->and(ClientAccount::advanceBalance($a->client, $a->currency))->toBe('50.00');
});

test('applications cannot exceed the invoice balance or the receipt amount and must match client and currency', function () {
    actingAsRole('accounting');
    $a = issuedInvoice();
    $other = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['applications' => [['invoice_id' => $a->id, 'amount' => '300']]]))->assertSessionHasErrors(['applications.0.amount' => "La aplicación supera el saldo de la factura {$a->number} (236.00)."]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '100', 'applications' => [['invoice_id' => $a->id, 'amount' => '200']]]))->assertSessionHasErrors(['applications' => 'Las aplicaciones (200.00) superan el monto del recibo (100.00).']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['applications' => [['invoice_id' => $other->id, 'amount' => '10']]]))->assertSessionHasErrors(['applications.0.invoice_id' => "La factura {$other->number} es de otro cliente."]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['retention_itbis' => '40']))->assertSessionHasErrors(['retention_itbis' => 'La retención de ITBIS supera el ITBIS de las facturas aplicadas.']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['payment_method' => 'advance']))->assertSessionHasErrors(['payment_method']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['financial_account_id' => \App\Models\FinancialAccount::factory()->create(['is_active' => false])->id]))->assertSessionHasErrors(['financial_account_id' => 'La cuenta no existe o está inactiva.']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '0', 'retention_itbis' => '0', 'applications' => []]))->assertSessionHasErrors(['amount_received' => 'El recibo debe tener un monto o una retención.']);
    expect(Receipt::count())->toBe(0);
});

test('an advance is later applied to another invoice and the receipt can be cancelled reversing everything', function () {
    $user = actingAsRole('accounting');
    $a = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '500', 'applications' => [['invoice_id' => $a->id, 'amount' => '236']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    $b = issuedInvoice(['client_id' => $a->client_id]);
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '300']]])->assertSessionHasErrors(['applications' => 'Las aplicaciones (300.00) superan el anticipo disponible (264.00).']);
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '200']]])->assertRedirect()->assertSessionHas('success', 'Anticipo aplicado: RD$ 200.00');
    expect($r->fresh()->unapplied_amount)->toBe('64.00')->and($r->fresh()->applied_total)->toBe('436.00')->and($b->fresh()->balance)->toBe('36.00')
        ->and(ReceiptApplication::where('source', 'advance')->count())->toBe(1)
        ->and(AccountingEvent::where('type', 'advance_applied')->count())->toBe(1)
        ->and(Activity::inLog('receipts')->where('event', 'advance_applied')->where('subject_id', $r->id)->exists())->toBeTrue();

    $this->post(route('sales.receipts.cancel', $r), ['reason' => 'Transferencia rebotada'])->assertRedirect()->assertSessionHas('success', 'Recibo REC-000001 anulado');
    expect($r->fresh()->status)->toBe(ReceiptStatus::Cancelled)->and($r->fresh()->cancelled_by)->toBe($user->id)->and($r->fresh()->unapplied_amount)->toBe('0.00')
        ->and($a->fresh()->status)->toBe(InvoiceStatus::Issued)->and($a->fresh()->paid_amount)->toBe('0.00')->and($b->fresh()->balance)->toBe('236.00')
        ->and(AccountingEvent::where('type', 'receipt_cancelled')->first()->payload['reversed'])->toHaveCount(2)
        ->and(Activity::inLog('receipts')->where('event', 'cancelled')->exists())->toBeTrue();
    $this->post(route('sales.receipts.cancel', $r), ['reason' => 'x'])->assertSessionHas('error', 'El recibo ya está anulado');
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '1']]])->assertSessionHas('error', 'El recibo está anulado');
});

test('usd receipts store the rate of the receipt date and reject invoices in another currency', function () {
    actingAsRole('accounting');
    $usd = \App\Models\Currency::where('code', 'USD')->firstOrFail();
    \App\Models\ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->toDateString(), 'rate' => '61.000000']);
    $dop = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($dop, ['currency_id' => $usd->id]))->assertSessionHasErrors(['applications.0.invoice_id' => "La factura {$dop->number} está en otra moneda."]);
    $usdInvoice = issuedInvoice(['currency_id' => $usd->id, 'client_id' => $dop->client_id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($usdInvoice, ['currency_id' => $usd->id]))->assertRedirect();
    expect(Receipt::firstOrFail()->exchange_rate)->toBe('61.000000')->and($usdInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('permissions and scoping: sales can view own receipts but not create or cancel', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $own = issuedInvoice(['client_id' => \App\Models\Client::factory()->create(['salesperson_id' => $sp->id])->id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($own))->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('sales.receipts.store'), validReceiptPayload($own))->assertRedirect();
    $r = Receipt::firstOrFail();
    $this->post(route('sales.receipts.cancel', $r), ['reason' => ''])->assertSessionHasErrors(['reason']);
    salesUserWithSalesperson();
    $this->get(route('sales.receipts.show', $r))->assertForbidden();
    $this->get(route('sales.receipts.index'))->assertInertia(fn ($p) => $p->has('rows.data', 0));
    actingAsRole('management');
    $this->get(route('sales.receipts.index'))->assertInertia(fn ($p) => $p->component('sales/receipts/index')->has('rows.data', 1));
    $this->get(route('sales.receipts.show', $r))->assertInertia(fn ($p) => $p->component('sales/receipts/show')->has('receipt.applications', 1)->has('history'));
    $this->get(route('sales.receipts.export', ['format' => 'csv']))->assertOk();
});
```

- [ ] **Step 3: Migrations, enums, models, policy** — `receipts` (000022): `id()`, `string('number', 12)->unique()`, `string('status', 20)->index()`, `foreignId('client_id')->constrained('clients')->restrictOnDelete()`, `foreignId('salesperson_id')->nullable()->constrained('salespeople')->nullOnDelete()`, `foreignId('currency_id')->constrained('currencies')->restrictOnDelete()`, `decimal('exchange_rate', 12, 6)`, `date('receipt_date')->index()`, `string('payment_method', 12)`, `foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete()`, `string('reference', 100)->nullable()`, `decimal('amount_received', 14, 2)`, `decimal('retention_itbis', 14, 2)->default(0)`, `decimal('retention_isr', 14, 2)->default(0)`, `decimal('applied_total', 14, 2)->default(0)`, `decimal('unapplied_amount', 14, 2)->default(0)`, `text('notes')->nullable()`, `timestamp('posted_at')->nullable()`, `timestamp('cancelled_at')->nullable()`, `foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()`, `string('cancel_reason', 255)->nullable()`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`. `receipt_applications` (000023): `id()`, `foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete()`, `foreignId('invoice_id')->constrained('invoices')->restrictOnDelete()`, `decimal('amount', 14, 2)`, `string('source', 10)->default('receipt')`, `foreignId('created_by')->constrained('users')->restrictOnDelete()`, `timestamps()`, `index(['invoice_id'])`. Models (`Receipt`: `ImmutableWhenIssued`, `LogsModelActivity`, `VisibleToSalesperson`; relations `client, salesperson, currency, financialAccount, canceller, creator, applications (HasMany)`; `ReceiptApplication`: `LogsModelActivity`; `receipt, invoice, creator`). `Invoice::applications(): HasMany`. `ReceiptPolicy extends SalesDocumentPolicy` (`sales.invoices.view-all`). `ClientAccount::advanceBalance`.

- [ ] **Step 4: Requests, actions, controller**

`StoreReceiptRequest` (`authorize`: `can('sales.receipts.create')`; `prepareForValidation` `''` → `null` for `financial_account_id`, `reference`, `notes`, and `'0'` defaults for empty retentions): rules `client_id` (active exists), `currency_id` (active), `receipt_date` (`date_format:Y-m-d`), `payment_method` → `['required', Rule::in(['transfer', 'check', 'cash', 'card'])]`, `financial_account_id` → `['required', 'integer', Rule::exists('financial_accounts', 'id')->where('is_active', true)]` (`La cuenta no existe o está inactiva.`), `reference` nullable max 100, `amount_received` → `['required', 'numeric', 'min:0', 'max:999999999999.99']`, `retention_itbis` / `retention_isr` → same bounds, `notes`, `applications` → `['present', 'array', 'max:100']`, `applications.*.invoice_id` → `['required', 'integer', 'exists:invoices,id', 'distinct']`, `applications.*.amount` → `['required', 'numeric', 'gt:0', 'max:999999999999.99']`; `withValidator` → `after`: `credit = amount_received + retention_itbis + retention_isr`; `Decimal::isZero(credit)` → `amount_received`: `El recibo debe tener un monto o una retención.`; account currency must equal `currency_id` (`financial_account_id`: `La cuenta está en otra moneda.`); load the invoices; per application: invoice not open → `applications.{i}.invoice_id`: `La factura {number} no está pendiente de cobro.`; other client → `… es de otro cliente.`; other currency → `… está en otra moneda.`; `amount > balance` → `applications.{i}.amount`: `La aplicación supera el saldo de la factura {number} ({balance}).`; `Σ amount > credit` → `applications`: `Las aplicaciones ({sum}) superan el monto del recibo ({credit}).`; `retention_itbis > Σ itbis_total` of applied invoices → the ITBIS message; `retention_isr > Σ taxable_total` → the ISR message. (Σ formatted with 2 dp.)
`ApplyAdvanceRequest`: `applications` required array min 1 with the same per-item rules; `withValidator`: same client/currency/balance rules (the receipt's state is checked by the action, in this order: cancelled → `DocumentStateException('El recibo está anulado')`; `unapplied_amount` zero → `DocumentStateException('El recibo no tiene anticipo disponible')`), `Σ > unapplied_amount` → `applications`: `Las aplicaciones ({sum}) superan el anticipo disponible ({unapplied}).`
`CancelReceiptRequest`: `reason` required max 255.
`PostReceipt::handle(array $data, User $actor): Receipt` — `DB::transaction`: `DocumentCurrency::resolve(currency_id, receipt_date)`; `salesperson_id = client.salesperson_id`; lock the applied invoices (`lockForUpdate`) and re-check balances (second guard; throw `DocumentStateException` with the balance message if a concurrent payment changed it); create the receipt (`number` `REC-`, `status posted`, `posted_at now`, `applied_total`, `unapplied_amount = credit − Σ`); create applications (`source receipt`); per invoice `paid_amount += amount`, `refreshFinancials()`, activity on the invoice `payment_applied` (`properties.receipt_id, number, amount`); activity on the receipt `posted` (`"Recibo {$number} registrado"`); event `receipt_posted`.
`ApplyAdvance` / `CancelReceipt` per the rules; `CancelReceipt` flash `"Recibo {$number} anulado"`; `ApplyAdvance` flash `'Anticipo aplicado: '.DocumentPdf::money($sum, $code)`; invoice activity on reversal `payment_reversed`.
Routes: `receipts/export`, `receipts/{receipt}/cancel` (POST), `receipts/{receipt}/apply` (POST), `Route::resource('receipts', ReceiptController::class)->only(['index', 'create', 'store', 'show'])`. Permissions: `index/show` → `sales.receipts.view` (+ policy), `create/store/apply` → `sales.receipts.create`, `cancel` → `sales.receipts.cancel`, `export` → `sales.receipts.export`. `index` filters `client_id, status, payment_method, from, to`; searchable `receipts.number, receipts.reference`; rows add `status_label`, `payment_method_label`, `client.business_name`, `currency.code`, `financial_account.name`. `create` props: `client` (when `?client=`), `openInvoices` (for `?client=`: open invoices `id, number, ncf, issue_date, due_date, total, itbis_total, taxable_total, balance, currency_id`), `accounts` (active `id, name, type, currency_id`), `paymentMethods`, `currencies`, `advances` (posted receipts of the client with `unapplied_amount > 0`: `id, number, unapplied_amount, currency_id`). `show` props: `receipt` (+ `applications.invoice:id,number,ncf,balance`, client, currency, account, canceller), `openInvoices` (same client+currency, for the apply dialog), `history`, `can` (`cancel`, `apply`). `export` columns: Número, Fecha, Cliente, Método, Cuenta, Referencia, Moneda, Monto, Ret. ITBIS, Ret. ISR, Aplicado, Anticipo, Estado. The Inertia pages are built in Task 15.

- [ ] **Step 5: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (427 tests).

- [ ] **Step 6: Commit** — `feat(sales): add receipts with applications, retentions, advances and cancellation`

---

### Task 15: Receipts (frontend) — pages, apply-advance dialog, receipt PDF, "Registrar cobro" from the invoice

**Files:**
- Create: `resources/js/pages/sales/receipts/{index,form,show}.tsx`, `resources/js/components/erp/apply-advance-dialog.tsx`, `resources/views/pdf/sales/receipt.blade.php`, `tests/Feature/Sales/ReceiptPdfTest.php`
- Modify: `app/Http/Controllers/Sales/ReceiptController.php` (`pdf`), `routes/sales.php` (`receipts/{receipt}/pdf`), `app/Http/Controllers/Sales/InvoiceController.php` (`can.receipt`, show prop `applications`), `resources/js/pages/sales/invoices/show.tsx` (Cobros tab + "Registrar cobro" link to `create.url({ query: { client: client_id, invoice: id } })`), `resources/js/types/sales.ts`

**Interfaces:** route `sales.receipts.pdf` (`sales.receipts.view` + policy); TS types `Receipt`, `ReceiptApplication`, `OpenInvoiceRow`.

- [ ] **Step 1: Failing test `tests/Feature/Sales/ReceiptPdfTest.php`**
```php
<?php

use App\Models\Receipt;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('receipt pdf lists the applied invoices, retentions and advance', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($i, ['amount_received' => '250', 'retention_itbis' => '36', 'applications' => [['invoice_id' => $i->id, 'amount' => '236']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    $html = app(\App\Services\Documents\DocumentPdf::class)->render('pdf.sales.receipt', app(\App\Http\Controllers\Sales\ReceiptController::class)->pdfData($r), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('RECIBO DE INGRESO')->toContain($r->number)->toContain($i->ncf)->toContain('Retención ITBIS')->toContain('RD$ 36.00')->toContain('Saldo a favor')->toContain('RD$ 50.00');
    $this->get(route('sales.receipts.pdf', $r))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Activity::inLog('receipts')->where('event', 'pdf_generated')->exists())->toBeTrue();
    $this->get(route('sales.invoices.show', $i))->assertInertia(fn ($p) => $p->has('applications', 1)->where('applications.0.receipt_number', $r->number));
});
```

- [ ] **Step 2: Controller `pdfData(Receipt $receipt): array` + `pdf()`** — data `['receipt' => $receipt->load('applications.invoice', 'client', 'currency', 'financialAccount'), 'currency' => code]`; view `pdf.sales.receipt`: `document-box` `<div class="box"><strong style="font-size:14px;">RECIBO DE INGRESO</strong><br><strong>{{ $receipt->number }}</strong><br>Fecha: {{ d/m/Y }}<br>Método: {{ label }}<br>@if(account)Cuenta: {{ name }}<br>@endif @if(reference)Referencia: {{ reference }}@endif</div>`; `content`: client block, table "Facturas aplicadas" (NCF, Factura, Fecha, Monto aplicado), summary table rows: `Monto recibido`, `Retención ITBIS` (when > 0), `Retención ISR` (when > 0), `Total aplicado`, `Saldo a favor` (when `unapplied_amount` > 0) using `DocumentPdf::money`; "ANULADO" banner when cancelled; signature line "Recibido por". `InvoiceController::show` adds `applications` (`[{id, receipt_id, receipt_number, receipt_date, amount, source}]`) and `can.receipt = can('sales.receipts.create') && invoice open`.

- [ ] **Step 3: Pages** — `index` (Número, Fecha, Cliente, Método, Monto, Aplicado, Anticipo, Estado; filters); `form` (`ClientPicker` → on pick loads open invoices via `router.get(create.url({ query: { client: id } }), {}, { preserveState: true, only: ['openInvoices', 'client', 'advances'] })`; Moneda, Fecha, Método (`Select`), Cuenta (`Select` filtered by currency), Referencia, Monto recibido, Retención ITBIS, Retención ISR, Notas; table "Facturas pendientes" with a `Checkbox` per invoice and an `Input` Monto a aplicar (default = balance, editable); footer showing `Σ aplicado` and `Saldo a favor = monto + retenciones − Σ` computed client-side **for display only** with `Number()` (the server recomputes and validates; the page shows "El sistema validará los montos al guardar"); when `advances` is non-empty, an `Alert` "Este cliente tiene anticipos por RD$ X — aplícalos desde el recibo correspondiente"; "Registrar recibo"); `show` (header meta: Cliente, Fecha, Método, Cuenta, Referencia, Moneda, Monto, Retenciones, Aplicado, Anticipo; applications table; actions: PDF, "Aplicar anticipo" (`can.apply` and `unapplied_amount > 0`) opening `ApplyAdvanceDialog` (open invoices table with amount inputs, posts to `apply.url(id)`), "Anular" (`can.cancel`) with reason dialog; Historial).

- [ ] **Step 4: Generate, gate** — standard command → passed (428 tests).

- [ ] **Step 5: Commit** — `feat(sales): add receipt pages, advance application dialog and receipt PDF`

---

### Task 16: Client statements and aging — service, pages, PDF, e-mail, export

**Files:**
- Create: `app/Services/Sales/ClientStatementService.php`, `app/Exports/RowsExport.php`, `app/Http/Controllers/Sales/StatementController.php`, `resources/views/pdf/sales/statement.blade.php`, `resources/js/pages/sales/statements/{index,show}.tsx`, `tests/Feature/Sales/ClientStatementTest.php`
- Modify: `routes/sales.php`, `resources/js/types/sales.ts`, `app/Services/Sales/ClientAccount.php` (no change if already complete)

**Interfaces:**
- Consumes: Tasks 9, 11, 12, 14 models; Task 6/7; `Client::visibleTo`.
- Produces: `ClientStatementService::statement(Client $client, CarbonInterface $from, CarbonInterface $to): array<string /*currency code*/, array{opening_balance: string, rows: list<array{date: string, type: string, type_label: string, number: ?string, ncf: ?string, description: string, debit: string, credit: string, balance: string}>, closing_balance: string}>` (rows ordered by date then id; types `invoice` (`Factura`, debit = total), `debit_note` (`Nota de débito`, debit), `credit_note` (`Nota de crédito`, credit), `payment` (`Cobro`, credit = application amount, number = receipt number) — cancelled documents are excluded; historical invoices included); `ClientStatementService::aging(User $viewer, CarbonInterface $asOf, ?int $salespersonId = null, ?string $search = null): array{rows: list<array{client_id, client_code, client_name, salesperson, currency_code, current, d1_30, d31_60, d61_90, d90_plus, total, total_base}>, totals: array{current, d1_30, d61_90, d31_60, d90_plus, total, total_base}}` (per open invoice: `days = (int) $invoice->due_date->diffInDays($asOf, false)` — positive when `asOf` is after the due date; `days ≤ 0` → `current`; `1–30` overdue → `d1_30`; `31–60`; `61–90`; `> 90` → `d90_plus`; grouped by client + currency; `total_base` = Σ `balance × exchange_rate`; `totals` summed in base currency per bucket using each invoice's rate); `RowsExport::download(array $rows, string $baseName, array $headings, string $format): BinaryFileResponse` (`FromArray` + `WithHeadings` with `TableExport`'s `csvSafe`/`bindValue`/`getCsvSettings` copied and the same `exports`/`exported` activity row with `row_count = count($rows)`); routes `sales.statements.index` (GET `/sales/statements`), `sales.statements.export`, `sales.statements.show` (GET `/sales/statements/{client}`), `sales.statements.pdf`, `sales.statements.send` (POST).

- [ ] **Step 1: Failing tests `tests/Feature/Sales/ClientStatementTest.php`**
```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Sales\ClientStatementService;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
});

function openInvoiceDue(Client $client, string $dueDate, string $paid = '0'): Invoice
{
    $i = issuedInvoice(['client_id' => $client->id]);
    Invoice::query()->whereKey($i->id)->update(['due_date' => $dueDate, 'paid_amount' => $paid]); // query builder: bypasses the immutability guard on purpose (test fixture)
    $i = $i->fresh();
    $i->refreshFinancials();

    return $i->fresh();
}

test('aging buckets open balances by days overdue and sums in base currency', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['business_name' => 'Aged SRL']);
    $asOf = CarbonImmutable::parse('2026-11-30');
    openInvoiceDue($client, '2026-12-05');            // current
    openInvoiceDue($client, '2026-11-15');            // 15 days
    openInvoiceDue($client, '2026-10-15', '36.00');   // 46 days, balance 200
    openInvoiceDue($client, '2026-09-10');            // 81 days
    openInvoiceDue($client, '2026-07-01');            // 152 days
    $cancelled = openInvoiceDue($client, '2026-07-01');
    app(\App\Actions\Sales\CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');

    $aging = app(ClientStatementService::class)->aging(auth()->user(), $asOf);
    $row = collect($aging['rows'])->firstWhere('client_id', $client->id);
    expect($row['current'])->toBe('236.00')->and($row['d1_30'])->toBe('236.00')->and($row['d31_60'])->toBe('200.00')->and($row['d61_90'])->toBe('236.00')->and($row['d90_plus'])->toBe('236.00')
        ->and($row['total'])->toBe('1144.00')->and($row['total_base'])->toBe('1144.00')->and($aging['totals']['total_base'])->toBe('1144.00');
    $this->get(route('sales.statements.index', ['as_of' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/index')->where('aging.rows.0.client_name', 'Aged SRL')->where('aging.rows.0.d90_plus', '236.00'));
});

test('statement lists invoices, notes and payments with a running balance per currency', function () {
    actingAsRole('accounting');
    activeSequence('B04', ['range_to' => 100]);
    activeSequence('B03', ['range_to' => 100]);
    $client = Client::factory()->create();
    $i = issuedInvoice(['client_id' => $client->id, 'issue_date' => '2026-11-02']);
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['issue_date' => '2026-11-03']))->assertRedirect();      // +590.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['issue_date' => '2026-11-04']))->assertRedirect();   // −11.80
    $this->post(route('sales.receipts.store'), validReceiptPayload($i->fresh(), ['receipt_date' => '2026-11-05', 'amount_received' => '100', 'applications' => [['invoice_id' => $i->id, 'amount' => '100']]]))->assertRedirect();

    $s = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    $rows = $s['DOP']['rows'];
    expect($s)->toHaveKey('DOP')->and($s['DOP']['opening_balance'])->toBe('0.00')->and($rows)->toHaveCount(4)
        ->and($rows[0]['type'])->toBe('invoice')->and($rows[0]['debit'])->toBe('236.00')->and($rows[0]['balance'])->toBe('236.00')
        ->and($rows[1]['type'])->toBe('debit_note')->and($rows[1]['balance'])->toBe('826.00')
        ->and($rows[2]['type'])->toBe('credit_note')->and($rows[2]['credit'])->toBe('11.80')->and($rows[2]['balance'])->toBe('814.20')
        ->and($rows[3]['type'])->toBe('payment')->and($rows[3]['credit'])->toBe('100.00')->and($rows[3]['balance'])->toBe('714.20')
        ->and($s['DOP']['closing_balance'])->toBe('714.20');
    $later = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-30'));
    expect($later['DOP']['opening_balance'])->toBe('826.00')->and($later['DOP']['rows'])->toHaveCount(2);
    $this->get(route('sales.statements.show', [$client, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/show')->where('statement.DOP.closing_balance', '714.20'));
});

test('statements are scoped to the salesperson for sales users', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    $own = Client::factory()->create(['salesperson_id' => $mine->id]);
    $foreign = Client::factory()->create();
    issuedInvoice(['client_id' => $own->id]);
    issuedInvoice(['client_id' => $foreign->id]);
    $this->get(route('sales.statements.index'))->assertInertia(fn ($p) => $p->has('aging.rows', 1));
    $this->get(route('sales.statements.show', $foreign))->assertForbidden();
    $this->get(route('sales.statements.show', $own))->assertOk();
});

test('statement pdf and email use the statement template and the export is logged', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $client = Client::factory()->create(['email' => 'cliente@x.test']);
    issuedInvoice(['client_id' => $client->id]);
    $this->get(route('sales.statements.pdf', $client))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->post(route('sales.statements.send', $client), ['to' => ['cliente@x.test']])->assertRedirect()->assertSessionHas('success', 'Correo enviado a cliente@x.test');
    expect(\App\Models\SentEmail::first()->template_key)->toBe('statement')->and(\App\Models\SentEmail::first()->subject)->toContain('Estado de cuenta')
        ->and(Activity::inLog('clients')->where('event', 'emailed')->where('subject_id', $client->id)->exists())->toBeTrue();
    $this->get(route('sales.statements.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['table'])->toBe('aging');
});
```

- [ ] **Step 2: Service and export** — `ClientStatementService` per Interfaces; queries: invoices `where client_id, whereNotNull issued_at, status != cancelled`; debit/credit notes `where invoice_id in (client invoices)` with `status issued`; applications joined with posted receipts (`receipts.status = posted`). Amounts in the document's currency; grouping key = currency code. `aging()` iterates `Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->whereHas('client', fn ($q) => $q->visibleTo($viewer))->when($salespersonId)->when($search on client business_name/code)->with('client.salesperson', 'currency')`. `RowsExport` per Interfaces.

- [ ] **Step 3: Controller, routes, PDF, pages** — routes: `statements/export`, `statements/{client}/pdf`, `statements/{client}/send` (POST), `statements/{client}` (GET), `statements` (GET). Permissions: `index/show/pdf/export` → `sales.statements.view` + `Gate::authorize('view', $client)` (the Phase 1 `ClientPolicy`); `send` → `sales.statements.send`. `index` props: `aging` (rows + totals), `filters` (`as_of` default today, `salesperson_id`, `search`), `salespeople` (when `catalog.clients.view-all`), `asOf`. `show` props: `client` (`id, code, business_name, formatted_tax_id, email, credit_limit, credit_days`), `statement`, `filters` (`from` default first day of the month 11 months ago, `to` default today), `advances` (`ClientAccount::advanceBalance` per active currency), `canSend`. `pdf` → `pdf.sales.statement` (`document-box`: "ESTADO DE CUENTA", Al: `{to d/m/Y}`, Desde: `{from}`; `content`: client block; per currency a section "Moneda: DOP" with the rows table (Fecha, Tipo, Número, NCF, Descripción, Débito, Crédito, Balance) and "Saldo inicial"/"Saldo final"; then an aging box for the client ("Antigüedad al {to}": Vigente / 1–30 / 31–60 / 61–90 / +90 / Total)); activity `activity('clients')->performedOn($client)->event('statement_generated')`. `send` → template `statement`, vars (`total` = closing balance of the client's main currency formatted; `fecha` = to d/m/Y; `usuario` = user name), attachment `estado-de-cuenta-{code}-{Ymd}.pdf`, document = the client (log `clients`, event `emailed`). `export` → `RowsExport::download(rows, 'aging', ['Código', 'Cliente', 'Vendedor', 'Moneda', 'Vigente', '1-30', '31-60', '61-90', '+90', 'Total', 'Total RD$'], format)`.
Pages: `index.tsx` title "Estados de cuenta y antigüedad" — filters (Fecha de corte `Input type=date`, Vendedor `Select`, Buscar cliente), aging `Table` with the buckets formatted with `formatMoney(v, currency_code)`, totals row (base currency), each client name links to `show`; `ExportMenu`. `show.tsx` title "Estado de cuenta — {business_name}" — Desde/Hasta inputs + "Aplicar"; per-currency `Card` with the movements table and balances; advances `Alert` when any; buttons PDF, "Enviar por correo" (`SendEmailDialog`, template `statement`, `submitUrl = send.url(client.id)`).

- [ ] **Step 4: Generate, gate** — standard command → passed (432 tests).

- [ ] **Step 5: Commit** — `feat(sales): add client statements and aging with PDF, email and export`

---

### Task 17: Stock pages — existencias, kardex, lotes, minimum-stock and expiry alerts

**Files:**
- Create: `app/Http/Controllers/Inventory/StockController.php`, `app/Http/Controllers/Inventory/StockMovementController.php`, `app/Http/Controllers/Inventory/LotController.php`, `app/Jobs/CheckStockAlerts.php`, `app/Notifications/StockAlert.php`, `resources/js/pages/inventory/stock/index.tsx`, `resources/js/pages/inventory/movements/index.tsx`, `resources/js/pages/inventory/lots/index.tsx`, `tests/Feature/Inventory/StockPagesTest.php`
- Modify: `routes/inventory.php` (replace the Task 5 placeholder), `routes/console.php`, `resources/js/types/inventory.ts`, `app/Models/StockMovement.php` (`scopeFiltered`), `app/Enums/StockMovementType.php` (nothing new)

**Interfaces:**
- Consumes: Task 4 (`StockBalance`, `StockMovement`, `Lot`, `InventoryService::lowStock/expiringLots`), `Settings` (`inventory.expiring_days` default `30`), Task 1 permission `inventory.alerts.receive`.
- Produces: routes `inventory.stock.index` (GET `/inventory/stock`), `inventory.stock.export`, `inventory.movements.index` (GET `/inventory/movements`), `inventory.movements.export`, `inventory.lots.index` (GET `/inventory/lots`); job `CheckStockAlerts` (daily 07:35) → `StockAlert` notification (`['mail', 'database']`, subject `Alertas de inventario`, lines `Stock bajo mínimo: {sku} {name} — existencia {qty}, mínimo {min}` and `Lote por vencer: {sku} lote {code} vence el {dd/mm/YYYY} ({qty} unidades)`, action `Ver existencias` → `inventory.stock.index`); activity log `inventory` event `alerts_checked` with `properties.low_stock` (count) and `expiring_lots` (count).

Rules: **Existencias** rows = warehouse-level `StockBalance` rows (`lot_id null`) joined with product + warehouse; columns `sku, name, warehouse, quantity, reserved_quantity, available, minimum_stock, is_low (quantity < minimum_stock && minimum_stock > 0)`; when the user has `inventory.stock.view-costs` the rows also carry `average_cost` and `stock_value = Decimal::mul(quantity, average_cost, 2)`, otherwise those keys are **absent** (not null). Filters: `warehouse_id`, `search` (sku/name), `low_only`. **Kardex** rows = `StockMovement` with `product`, `warehouse`, `lot`, `user`, `reference` (`morphTo`); filters `product_id`, `warehouse_id`, `type`, `from`, `to`; columns `moved_at, type_label, sku, name, warehouse, lot_code, signed_quantity (direction × quantity), balance_after, reference_label ("Factura FAC-000001" / "Conduce CND-000001" / "Nota de crédito NC-000001" / "Carga inicial #id" / class basename otherwise), user_name, notes` + `unit_cost, total_cost` only with `view-costs`. **Lotes** rows = lot-level balances with `quantity > 0`: `sku, name, lot_code, expires_on, warehouse, quantity, days_to_expiry (null when no date), state (expired | expiring | ok)`; filter `expiring_only`, `warehouse_id`, `search`; `expiringDays` from settings.

- [ ] **Step 1: Failing tests `tests/Feature/Inventory/StockPagesTest.php`**
```php
<?php

use App\Enums\StockMovementType;
use App\Jobs\CheckStockAlerts;
use App\Models\Product;
use App\Models\Warehouse;
use App\Notifications\StockAlert;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    $this->wh = Warehouse::factory()->create();
});

test('stock index shows balances, hides costs without permission and filters low stock', function () {
    $user = actingAsRole('warehouse');
    $svc = app(InventoryService::class);
    $low = Product::factory()->create(['sku' => 'LOW-1', 'minimum_stock' => '10']);
    $ok = Product::factory()->create(['sku' => 'OK-1', 'minimum_stock' => '1']);
    $svc->receive(new StockLine($low->id, $this->wh->id, '4', '25'), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($ok->id, $this->wh->id, '8', '10'), StockMovementType::Initial, $user, $user);

    $this->get(route('inventory.stock.index'))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/stock/index')->has('rows.data', 2)->where('canViewCosts', false)->missing('rows.data.0.average_cost')->missing('rows.data.0.stock_value'));
    $this->get(route('inventory.stock.index', ['low_only' => '1']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.sku', 'LOW-1')->where('rows.data.0.is_low', true)->where('rows.data.0.available', '4.0000'));
    $this->get(route('inventory.stock.export', ['format' => 'csv']))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('inventory.stock.index', ['search' => 'low']))->assertInertia(fn ($p) => $p->where('canViewCosts', true)->where('rows.data.0.average_cost', '25.0000')->where('rows.data.0.stock_value', '100.00'));
    $this->get(route('inventory.stock.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->latest('id')->first()->properties['table'])->toBe('stock');
    actingAsRole('sales');
    $this->get(route('inventory.stock.index'))->assertOk();
    $this->get(route('inventory.movements.index'))->assertForbidden();
});

test('kardex lists signed movements with references and filters by product and type', function () {
    $user = actingAsRole('warehouse');
    $svc = app(InventoryService::class);
    $p = Product::factory()->create(['sku' => 'K-1']);
    $svc->receive(new StockLine($p->id, $this->wh->id, '10', '5'), StockMovementType::Initial, $user, $user);
    $svc->dispatch(new StockLine($p->id, $this->wh->id, '3'), $user, $user);
    $this->get(route('inventory.movements.index', ['product_id' => $p->id]))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/movements/index')->has('rows.data', 2)->where('rows.data.0.signed_quantity', '-3.0000')->where('rows.data.0.balance_after', '7.0000')->where('rows.data.1.signed_quantity', '10.0000')->missing('rows.data.0.unit_cost'));
    $this->get(route('inventory.movements.index', ['type' => 'dispatch']))->assertInertia(fn ($p) => $p->has('rows.data', 1));
    $this->get(route('inventory.movements.export'))->assertForbidden();
    actingAsRole('owner');
    $this->get(route('inventory.movements.index'))->assertInertia(fn ($p) => $p->where('rows.data.0.unit_cost', '5.0000'));
    $this->get(route('inventory.movements.export', ['format' => 'xlsx']))->assertOk();
});

test('lots page flags expired and expiring lots using the configured days', function () {
    $user = actingAsRole('warehouse');
    app(\App\Services\Settings::class)->set('inventory.expiring_days', 45);
    $svc = app(InventoryService::class);
    $p = Product::factory()->create(['tracks_lots' => true]);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'OLD', now()->subDay()->toDateString()), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'SOON', now()->addDays(40)->toDateString()), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'FAR', now()->addDays(200)->toDateString()), StockMovementType::Initial, $user, $user);
    $this->get(route('inventory.lots.index'))->assertOk()->assertInertia(fn ($pg) => $pg->component('inventory/lots/index')->has('rows.data', 3)->where('expiringDays', 45)->where('rows.data.0.lot_code', 'OLD')->where('rows.data.0.state', 'expired')->where('rows.data.1.state', 'expiring')->where('rows.data.2.state', 'ok'));
    $this->get(route('inventory.lots.index', ['expiring_only' => '1']))->assertInertia(fn ($pg) => $pg->has('rows.data', 2));
});

test('the alert job notifies users with the alerts permission and logs', function () {
    Notification::fake();
    $user = actingAsRole('warehouse');
    $sales = \App\Models\User::factory()->create();
    $sales->assignRole('sales');
    $svc = app(InventoryService::class);
    $low = Product::factory()->create(['minimum_stock' => '10']);
    $svc->receive(new StockLine($low->id, $this->wh->id, '1', '1'), StockMovementType::Initial, $user, $user);
    (new CheckStockAlerts)->handle($svc, app(\App\Services\Settings::class));
    Notification::assertSentTo($user, StockAlert::class, fn (StockAlert $n) => count($n->lowStock) === 1 && count($n->expiringLots) === 0);
    Notification::assertNotSentTo($sales, StockAlert::class);
    expect(Activity::inLog('inventory')->where('event', 'alerts_checked')->first()->properties['low_stock'])->toBe(1);

    Notification::fake();
    $svc->receive(new StockLine($low->id, $this->wh->id, '20', '1'), StockMovementType::Receipt, $user, $user);
    (new CheckStockAlerts)->handle($svc, app(\App\Services\Settings::class));
    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Controllers, job, notification, routes** — `StockController::index` (`permission:inventory.stock.view`): query `StockBalance::query()->whereNull('lot_id')->join('products', …)->join('warehouses', …)->select('stock_balances.*', 'products.sku', 'products.name', 'products.minimum_stock', 'warehouses.name as warehouse_name')` filtered + `TableQuery::apply(..., ['products.sku', 'products.name'], ['sku', 'name', 'quantity', 'available'], 'sku', 'asc')` → `through` builds the row array (strip costs unless `can('inventory.stock.view-costs')`); props `rows, filters, warehouses, canViewCosts`. `export` (`inventory.stock.export`) → `TableExport::fromQuery(..., 'stock', columns [SKU, Producto, Almacén, Existencia, Reservado, Disponible, Mínimo] + [Costo promedio, Valor] when view-costs)`. `StockMovementController::index` (`inventory.movements.view`), `export` (`inventory.movements.export`; base name `movements`). `LotController::index` (`inventory.lots.view`). `CheckStockAlerts` (`ShouldQueue`, `#[Tries(1)]`, `handle(InventoryService $inventory, Settings $settings): void`), `StockAlert` (`public array $lowStock, public array $expiringLots` — arrays of `['sku', 'name', 'quantity', 'minimum_stock']` / `['sku', 'code', 'expires_on', 'quantity']`). `routes/console.php`: `Schedule::job(new CheckStockAlerts)->dailyAt('07:35');`. Replace the placeholder route in `routes/inventory.php` with: `stock/export`, `stock` (index), `movements/export`, `movements`, `lots`, each `->name(...)`.

- [ ] **Step 3: Pages** — `inventory/stock/index.tsx` (title "Existencias"; filters Almacén, Buscar, `Checkbox` "Solo bajo mínimo"; columns SKU, Producto, Almacén, Existencia, Reservado, Disponible, Mínimo (+ Costo promedio, Valor when `canViewCosts`), row highlighted `text-red-600` when `is_low`; `ExportMenu` when `can('inventory.stock.export')`); `inventory/movements/index.tsx` (title "Movimientos de inventario (kardex)"; filters Producto (`ProductPicker` trigger showing the chosen product), Almacén, Tipo, Desde, Hasta; columns Fecha/hora (`formatDateTime`), Tipo, Producto, Almacén, Lote, Cantidad (signed, red when negative), Saldo, Referencia, Usuario, Notas (+ Costo unit., Costo total when `canViewCosts`)); `inventory/lots/index.tsx` (title "Lotes y vencimientos"; `Alert` "Se consideran por vencer los lotes que vencen en los próximos {expiringDays} días"; filters; columns SKU, Producto, Lote, Vence, Días, Almacén, Existencia, Estado (`StatusBadge` with labels Vencido/Por vencer/Vigente mapped to destructive/outline/default)).

- [ ] **Step 4: Generate, gate** — standard command → passed (436 tests).

- [ ] **Step 5: Commit** — `feat(inventory): add stock, kardex and lots pages with minimum-stock and expiry alerts`

---

### Task 18: Historical open invoices — Excel importer and manual registration (spec §3.12)

**Files:**
- Create: `app/Imports/HistoricalInvoicesImporter.php`, `app/Actions/Sales/RegisterHistoricalInvoice.php`, `app/Http/Requests/Sales/StoreHistoricalInvoiceRequest.php`, `app/Http/Controllers/Sales/HistoricalInvoiceController.php`, `resources/js/pages/sales/invoices/historical-form.tsx`, `tests/Feature/DataImport/HistoricalInvoicesImporterTest.php`, `tests/Feature/Sales/HistoricalInvoiceTest.php`
- Modify: `app/Imports/ImporterRegistry.php`, `routes/sales.php`, `resources/js/pages/sales/invoices/index.tsx` (button "Registrar factura histórica" when `can('sales.historical-invoices.import')`), `resources/views/pdf/sales/invoice.blade.php` (historical banner), `app/Http/Controllers/Sales/InvoiceController.php` (`can.*` false for historical where lines are needed: `dispatch`, `creditNote` stays true (discount), `debitNote` true)

**Interfaces:**
- Produces: importer type `historical_invoices` (label `Facturas históricas (saldos abiertos)`, permission `sales.historical-invoices.import`), columns: `ncf`/NCF/yes/`B0100000123`, `client`/Cliente (RNC, cédula o código)/yes/`1-01-85004-3`, `salesperson_code`/Vendedor (código)/no/`VEN-01`, `issue_date`/Fecha (AAAA-MM-DD)/yes/`2026-09-15`, `due_date`/Vencimiento (AAAA-MM-DD)/no/`2026-10-15`, `currency_code`/Moneda/yes/`DOP`, `taxable_total`/Base imponible/yes/`10000.00`, `itbis_total`/ITBIS/yes/`1800.00`, `total`/Total/yes/`11800.00`, `paid_amount`/Pagado/no/`0`, `notes`/Notas/no/``; `RegisterHistoricalInvoice::handle(array $data, User $actor): Invoice` (`$data` keys: `ncf, client_id, salesperson_id, issue_date, due_date (nullable), currency_id, taxable_total, itbis_total, total, paid_amount, notes`); routes `sales.invoices.historical.create` (GET `/sales/invoices/historical/create`), `sales.invoices.historical.store` (POST `/sales/invoices/historical`), both `permission:sales.historical-invoices.import`; accounting event `historical_invoice_registered` payload `{number, ncf, ncf_type, client_id, salesperson_id, issue_date, due_date, taxable_total, itbis_total, additional_tax_total, total, total_base, paid_amount, balance, currency, exchange_rate}`.

Rules: historical invoices are `kind = historical`, `fiscal_mode = 'external'` (never reported in 607 — Phase 5 filters on it), no lines, `warehouse_id null`, totals from the sheet (`subtotal = taxable_total`, `discount_total 0`, `additional_tax_total = total − taxable_total − itbis_total` must be ≥ 0), internal number `FAC-` from `DocumentNumbering`, `issued_at = issue_date` (so they are immutable from creation), status from `paid_amount` (`refreshFinancials()`), snapshots from the client, `credit_days` = client's, `due_date` = given or `issue_date + credit_days`, `payment_terms credit`; the NCF must match `/^B(01|02|14|15)\d{8}$/` and be unique in `invoices.ncf`; the salesperson comes from `salesperson_code`, else the client's salesperson, else the row is invalid (`El cliente no tiene vendedor asignado; indica el código del vendedor.`). They can receive receipts (Task 14), credit notes of reason `discount` (Task 11) and debit notes (Task 12); they cannot be cancelled through `CancelInvoice` when paid/credited like any invoice.

- [ ] **Step 1: Failing tests**

`tests/Feature/DataImport/HistoricalInvoicesImporterTest.php`:
```php
<?php

use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Salesperson;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('historical invoices import validates rows and registers immutable open invoices', function () {
    actingAsRole('accounting');
    $sp = Salesperson::factory()->create(['code' => 'VEN-01']);
    $withSp = Client::factory()->create(['tax_id' => '101850043', 'salesperson_id' => $sp->id, 'credit_days' => 30]);
    $noSp = Client::factory()->create(['code' => 'CLI-00099', 'salesperson_id' => null]);
    $file = makeSpreadsheet([
        ['NCF', 'Cliente (RNC, cédula o código)', 'Vendedor (código)', 'Fecha (AAAA-MM-DD)', 'Vencimiento (AAAA-MM-DD)', 'Moneda', 'Base imponible', 'ITBIS', 'Total', 'Pagado', 'Notas'],
        ['B0100000123', '1-01-85004-3', '', '2026-09-15', '', 'DOP', '10000', '1800', '11800', '1800', 'Saldo abierto'],
        ['B0200000005', 'CLI-00099', 'VEN-01', '2026-09-20', '2026-10-20', 'DOP', '500', '90', '590', '', ''],
        ['B0200000006', 'CLI-00099', '', '2026-09-21', '', 'DOP', '500', '90', '590', '', ''],
        ['B0100000124', '1-01-85004-3', '', '2026-09-22', '', 'DOP', '500', '90', '500', '', ''],
        ['B0100000123', '1-01-85004-3', '', '2026-09-23', '', 'DOP', '1', '0', '1', '', ''],
        ['X0100000001', '9-99-99999-9', '', '2026-13-01', '', 'EUR', '-1', 'x', '0', '5', ''],
    ]);
    $this->post(route('admin.imports.preview', 'historical_invoices'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(4)
        ->and($batch->rows()->where('row_number', 4)->first()->errors['salesperson_code'])->toBe('El cliente no tiene vendedor asignado; indica el código del vendedor.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors['total'])->toBe('El total no puede ser menor que la base imponible más el ITBIS.')
        ->and($batch->rows()->where('row_number', 6)->first()->errors)->toHaveKey('_row')
        ->and($batch->rows()->where('row_number', 7)->first()->errors)->toHaveKeys(['ncf', 'client', 'issue_date', 'currency_code', 'taxable_total', 'itbis_total', 'total']);

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 2 creados, 0 actualizados');
    $a = Invoice::where('ncf', 'B0100000123')->firstOrFail();
    expect($a->kind->value)->toBe('historical')->and($a->fiscal_mode)->toBe('external')->and($a->number)->toBe('FAC-000001')->and($a->ncf_type->value)->toBe('B01')
        ->and($a->status->value)->toBe('partially_paid')->and($a->balance)->toBe('10000.00')->and($a->due_date->toDateString())->toBe('2026-10-15')->and($a->salesperson_id)->toBe($sp->id)
        ->and($a->client_name)->toBe($withSp->business_name)->and($a->issued_at)->not->toBeNull()->and($a->lines()->count())->toBe(0)
        ->and(AccountingEvent::where('type', 'historical_invoice_registered')->count())->toBe(2)
        ->and(\Spatie\Activitylog\Models\Activity::inLog('invoices')->where('event', 'registered_historical')->count())->toBe(2);
    expect(fn () => $a->update(['notes' => 'x']))->toThrow(\App\Exceptions\ImmutableDocumentException::class);
    expect(Invoice::where('ncf', 'B0200000005')->first()->salesperson_id)->toBe($sp->id);

    $again = makeSpreadsheet([['NCF', 'Cliente (RNC, cédula o código)', 'Fecha (AAAA-MM-DD)', 'Moneda', 'Base imponible', 'ITBIS', 'Total'], ['B0100000123', '1-01-85004-3', '2026-09-15', 'DOP', '1', '0', '1']]);
    $this->post(route('admin.imports.preview', 'historical_invoices'), ['file' => $again])->assertRedirect();
    expect(ImportBatch::latest('id')->first()->rows()->first()->errors['ncf'])->toBe('El NCF ya está registrado.');
});

test('sales users cannot use the historical importer', function () {
    actingAsRole('sales');
    $this->get(route('admin.imports.template', 'historical_invoices'))->assertForbidden();
});
```
`tests/Feature/Sales/HistoricalInvoiceTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Salesperson;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a historical invoice can be registered manually and then collected', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->get(route('sales.invoices.historical.create'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/invoices/historical-form'));
    $payload = ['ncf' => 'B0100000777', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => \App\Models\Currency::base()->id, 'taxable_total' => '1000', 'itbis_total' => '180', 'total' => '1180', 'paid_amount' => '0', 'notes' => ''];
    $this->post(route('sales.invoices.historical.store'), $payload)->assertRedirect()->assertSessionHas('success', 'Factura histórica B0100000777 registrada');
    $i = Invoice::firstOrFail();
    expect($i->kind->value)->toBe('historical')->and($i->balance)->toBe('1180.00')->and($i->due_date->toDateString())->toBe('2026-08-31');
    $this->post(route('sales.invoices.historical.store'), $payload)->assertSessionHasErrors(['ncf' => 'El NCF ya está registrado.']);
    $this->post(route('sales.invoices.historical.store'), [...$payload, 'ncf' => 'B0300000001'])->assertSessionHasErrors(['ncf']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($i))->assertRedirect();
    expect($i->fresh()->status->value)->toBe('paid');
    $this->get(route('sales.invoices.pdf', $i))->assertOk();
});

test('historical invoices appear in the index with their kind and the manual form needs the import permission', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.invoices.historical.store'), ['ncf' => 'B0100000778', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => \App\Models\Currency::base()->id, 'taxable_total' => '1', 'itbis_total' => '0', 'total' => '1', 'paid_amount' => '0', 'notes' => ''])->assertRedirect();
    $this->get(route('sales.invoices.index', ['kind' => 'historical']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.kind_label', 'Histórica'));
    actingAsRole('sales');
    $this->get(route('sales.invoices.historical.create'))->assertForbidden();
});
```

- [ ] **Step 2: Action, importer, request, controller, page** — `RegisterHistoricalInvoice` per the rules (`DB::transaction`; `DocumentCurrency::resolve(currency_id, issue_date)`; `NcfType::from(substr($ncf, 0, 3))`; create via `Invoice::query()->create([...])` with `issued_at` set in the same insert; `refreshFinancials()`; activity `registered_historical` (`"Factura histórica {$ncf} registrada"`); event). `HistoricalInvoicesImporter` (`normalize`: `ncf` upper/trim, `client` → `FiscalId::normalize` when it contains digits only after stripping separators, else upper; `currency_code` upper; `rules`: `ncf` → `['required', 'regex:/^B(01|02|14|15)\d{8}$/', Rule::unique('invoices', 'ncf')]` (`ncf.unique` → `El NCF ya está registrado.`, `ncf.regex` → `El NCF debe ser B01/B02/B14/B15 seguido de 8 dígitos.`), `client` → `['required', closure: resolves `Client` by `tax_id` or `code`, fails `Cliente no encontrado.`]`, `salesperson_code` → `['nullable', Rule::exists('salespeople', 'code')->where('is_active', true)]` + closure: when empty and the resolved client has no `salesperson_id` → the message from the rules, `issue_date` → `['required', 'date_format:Y-m-d']`, `due_date` → `['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date']`, `currency_code` → `['required', 'exists:currencies,code']`, `taxable_total`/`itbis_total` → `['required', 'numeric', 'min:0', 'max:999999999999.99']`, `total` → `['required', 'numeric', 'gt:0', 'max:999999999999.99', closure ≥ taxable + itbis → 'El total no puede ser menor que la base imponible más el ITBIS.']`, `paid_amount` → `['nullable', 'numeric', 'min:0', closure ≤ total → 'El pago no puede superar el total.']`, `notes` nullable max 2000; `naturalKey` → `ncf`; `findExisting` → `null`; `persist` → resolve ids and call the action). Register in `ImporterRegistry`. `StoreHistoricalInvoiceRequest` (same rules with `client_id` active exists, `salesperson_id` nullable active exists, `currency_id`); `withValidator`: salesperson fallback as above (`salesperson_id`: `El cliente no tiene vendedor asignado; selecciona el vendedor.`). `HistoricalInvoiceController` (`create` → page props `currencies`, `salespeople`, `defaultCurrencyId`; `store` → action → `redirect()->route('sales.invoices.show', $i)->with('success', "Factura histórica {$ncf} registrada")`). Page `historical-form.tsx` (title "Registrar factura histórica"; `Alert` "Use esta pantalla solo para facturas emitidas antes de usar el sistema. No se reportan en el 607 ni se reemiten."; fields NCF, Cliente (`ClientPicker`), Vendedor, Fecha, Vencimiento, Moneda, Base imponible, ITBIS, Total, Pagado, Notas; "Registrar"). Invoice PDF: when `kind === historical` the fiscal box title is `Factura histórica (registrada externamente)` and a note "Documento registrado para control de saldos; el comprobante original fue emitido fuera del sistema." replaces the lines table. `InvoiceController::show` `can.dispatch = false` for historical.

- [ ] **Step 3: Generate, gate** — standard command → passed (440 tests).

- [ ] **Step 4: Commit** — `feat(sales): add historical open invoices importer and manual registration`

---

### Task 19: Phase wrap-up — settings fields, navigation/permission consistency, smoke test, docs and release checklist

**Files:**
- Create: `tests/Feature/SalesSmokeTest.php`
- Modify: `app/Http/Requests/Admin/UpdateSettingsRequest.php`, `app/Http/Controllers/Admin/SettingsController.php`, `resources/js/pages/admin/settings/index.tsx`, `tests/Feature/Admin/SettingsTest.php`, `.ai/rules/app.md`, `.ai/rules/js.md`, `README.md`, `docs/superpowers/plans/2026-10-29-00-architecture.md`

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Admin/SettingsTest.php`:
```php
test('sales and inventory settings are saved with bounds', function () {
    actingAsRole('owner');
    $this->put(route('admin.settings.update'), settingsPayload(['fiscal.consumo_id_threshold' => '300000', 'sales.proforma_validity_days' => '20', 'inventory.expiring_days' => '60']))->assertRedirect();
    $s = app(\App\Services\Settings::class);
    expect($s->get('fiscal.consumo_id_threshold'))->toBe('300000.00')->and($s->get('sales.proforma_validity_days'))->toBe(20)->and($s->get('inventory.expiring_days'))->toBe(60);
    $this->put(route('admin.settings.update'), settingsPayload(['fiscal.consumo_id_threshold' => '-1', 'sales.proforma_validity_days' => '0', 'inventory.expiring_days' => '400']))->assertSessionHasErrors(['fiscal.consumo_id_threshold', 'sales.proforma_validity_days', 'inventory.expiring_days']);
    $this->get(route('admin.settings.index'))->assertInertia(fn ($p) => $p->where('values.fiscal.consumo_id_threshold', '300000.00')->where('fiscalMode', 'ncf'));
});
```
`tests/Feature/SalesSmokeTest.php`:
```php
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
```

- [ ] **Step 2: Settings** — `UpdateSettingsRequest` rules add `'fiscal.consumo_id_threshold' => ['required', 'numeric', 'min:0', 'max:999999999999.99']`, `'sales.proforma_validity_days' => ['required', 'integer', 'between:1,365']`, `'inventory.expiring_days' => ['required', 'integer', 'between:1,365']` with attributes `el umbral de facturas de consumo`, `los días de validez de proformas`, `los días de alerta de vencimiento`; `SettingsController` reads/writes the three keys (threshold stored as `Decimal::money`, days as `int`), index props add `fiscalMode` (`Settings::get('fiscal.mode', 'ncf')`) and the defaults (`'250000.00'`, `15`, `30`) when unset; page adds a `Card` "Ventas e inventario" with the three inputs (labels "Umbral de facturas de consumo que requieren RNC/cédula (RD$)", "Días de validez por defecto de las proformas", "Días de anticipación para alertas de vencimiento de lotes") and a read-only line "Modo fiscal: NCF tradicional (el cambio a e-CF llega en la fase 3)". Existing settings tests must keep passing: the three new keys are `required`, so extend the existing `settingsPayload()` helper used by `tests/Feature/Admin/SettingsTest.php` with the defaults `'fiscal.consumo_id_threshold' => '250000', 'sales.proforma_validity_days' => '15', 'inventory.expiring_days' => '30'` (test-only change, listed under "Test corrections").

- [ ] **Step 3: Docs** — `.ai/rules/app.md` append a section `## Sales documents` (4 lines: issued documents are immutable (`ImmutableWhenIssued`), corrections are credit/debit notes; every state transition writes `activity()` + `AccountingEventRecorder` when it has accounting meaning; money through `DocumentTotalsCalculator`/`Decimal`; state errors throw `DocumentStateException` which the exception handler flashes). `.ai/rules/js.md` append `Document pages use DocumentLinesEditor + useTotalsPreview (never compute money in the browser), ClientPicker, ProductPicker, StatusBadge, DocumentHeader, SendEmailDialog.` `README.md` append a "Fase 2 — Ventas con NCF" section: before invoicing register NCF sequences in Administración → Secuencias NCF; each user connects Gmail from the user menu to send documents; scheduler entries (`CheckNcfSequences 07:30`, `CheckStockAlerts 07:35`, `ExpireProformas 00:10`); `php artisan db:seed` after deploy (document sequences, email templates). `docs/superpowers/plans/2026-10-29-00-architecture.md`: §4 first bullet → `RoundingMode::HalfUp` (brick/math 1.x enum); §5.3 inventory line adds `inventory.warehouses.export`, `inventory.dispatches.view`, `inventory.dispatches.export`, `inventory.stock.export`, `inventory.movements.export`, `inventory.alerts.receive`; §12 Phase 2 "Exposes" adds `Receipt`, `ReceiptApplication`, `Dispatch`, `ClientStatementService`, `ClientAccount`, `DocumentStateException`. Stage docs explicitly in this task: `git add docs/superpowers/plans/2026-10-29-00-architecture.md README.md .ai/rules`.

- [ ] **Step 4: Full gate** — `php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && npm run build 2>&1 | tail -2 && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → build succeeds; passed (443 tests). Then `php artisan route:list --name=sales --except-vendor | tail -1` → prints `Showing [N] routes` with N ≥ 40, and `php artisan schedule:list` → lists `CheckNcfSequences`, `CheckStockAlerts`, `ExpireProformas`, `FetchDailyExchangeRates`.

- [ ] **Step 5: Commit** — `chore(phase-2): settings, docs and smoke tests for sales with NCF` (this commit includes the docs files listed in Step 3).

---

## Decisions taken by the planner (spec silent or ambiguous)

1. **Issuing an invoice without available stock is refused** (reservation fails → no NCF consumed). The spec's "reservado por facturas no despachadas" implies stock exists at invoice time; Phase 4 receipts will make this natural. If the business needs to invoice goods in transit, a later setting `sales.allow_negative_stock` can relax `InventoryService::reserve`.
2. **Internal numbers**: proformas and receipts get their number at creation; invoices, credit/debit notes and conduces at issue/confirmation (drafts show "Borrador"), so the sequences never have gaps from deleted drafts.
3. **Debit notes increase the referenced invoice's balance** (`debited_amount`) instead of carrying their own receivable; receipts therefore apply only to invoices.
4. **Credit-note reasons** fix the stock effect: `return` (re-enters stock, needs dispatched units), `correction` (releases the reservation of undelivered units), `discount` (money only). Historical invoices accept only `discount`.
5. **Advances** live on the receipt that produced them (`unapplied_amount`) and are applied later from that receipt; no separate advance ledger.
6. **Dispatch warehouse = invoice warehouse** (reservations are per warehouse); inter-warehouse transfers are Phase 4.
7. **Attachments on documents** (spec §3.14 "Adjuntos en cualquier documento", §3.13 proforma approval attachment) are deferred to Phase 7 (which builds the attachments infrastructure for the client 360 page); Phase 2 stores the approval note as text.
8. **Fiscal mode switch UI** is read-only in Phase 2 (`fiscal.mode = ncf`); Phase 3 adds the switch with its cutover date under `fiscal.mode.manage`.
9. **Retentions** are typed by the user from the client's certificate (no automatic percentage), bounded by the ITBIS/base of the applied invoices.
10. **Aging buckets** add a "Vigente" (not yet due) column before 1–30/31–60/61–90/+90 so the statement total matches the client balance.
11. **Spec §3.12 "saldo único por cliente"** (one opening balance instead of invoice-by-invoice) is delivered by Phase 5's opening-balances tool; Phase 2 ships the recommended invoice-by-invoice path (Task 18) because receipts, retentions and credit notes need a document to apply to.
12. **Historical invoices keep an internal `FAC-` number** (next in the same sequence) so receipts, statements and audit rows reference them like any other invoice; their NCF is the external one and `fiscal_mode = 'external'` excludes them from 607.
13. **Settings for the thresholds** (`fiscal.consumo_id_threshold`, `sales.proforma_validity_days`, `inventory.expiring_days`) live in the existing settings page (Task 19) with the defaults 250,000.00 / 15 / 30 used until saved.
