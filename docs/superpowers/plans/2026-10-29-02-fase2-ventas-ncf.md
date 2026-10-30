# Phase 2 — Sales with traditional NCF + basic inventory: Implementation Plan

> **BORRADOR PARCIAL — NO EJECUTAR.** Written up to Task 7 of ~19 on 2026-10-29 and paused by the user's rule "planificar una fase, codificarla, luego planificar la siguiente". It resumes when Phase 1 is merged. Executors must not start any task of this file until this banner is removed.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Executors are Claude Haiku agents that **decide nothing**: read `docs/superpowers/plans/2026-10-29-00-architecture.md` §11 first, then the Phase 1 plan's Global Constraints (they still apply), then this plan's Global Constraints.

**Goal:** First production release for invoicing: proformas → invoices with traditional NCF (B01/B02/B14/B15) → credit/debit notes (B04/B03) → dispatches (conduces) → receipts (cobros) with retentions and advances, client statements and aging, PDFs, e-mail sending through the user's own Gmail, warehouse stock with lots (FEFO), reservations and returns, initial stock and historical open invoices imports, and an `accounting_events` outbox so Phase 5 can generate journal entries retroactively.

**Architecture:** Documents are Eloquent models with line models; all money math goes through `DocumentTotalsCalculator` (ARCH §4) and `Decimal`. Fiscal numbering is centralised in `FiscalNumberingService` (traditional NCF now; Phase 3 plugs the `EcfProvider` behind the same service). Issued fiscal documents are immutable (`ImmutableWhenIssued`). Inventory changes only through `InventoryService`. Every state transition writes an activity row and, when it has accounting meaning, an `accounting_events` row.

**Tech Stack:** Phase 1 stack + `barryvdh/laravel-dompdf ^3.1` (PDF) + `google/apiclient ^2.20` (Gmail send-as-user).

**Spec:** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/specs/2026-10-29-gomez-erp-design.md` — this plan implements §3.3, §3.4.1, §3.6 (stock, movements, kardex, reserved/available, minimum-stock alerts, lots/FEFO; counts/adjustments/transfers are Phase 4), §3.10 (sending only), §3.12 (initial stock and historical invoices), §3.14 numbering, §9 item 2.

**Architecture doc (inherited):** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/plans/2026-10-29-00-architecture.md` (*ARCH §n*). **Phase 1 plan (interfaces consumed):** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/plans/2026-10-29-01-fase1-base.md` (*P1 GC-n*, *P1 Task n*).

**Branch:** `phase-2-sales-ncf`, created from `main` after Phase 1 is merged. Commit rule: P1 GC-1 (`vendor/bin/pint --dirty && git add -A && git commit -q -m "<subject>" -m "Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>"`). Test totals continue from Phase 1's final count (254).

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
- Document editors (proforma, invoice draft, credit/debit note draft, receipt, dispatch) use Inertia `useForm` (controlled), lines in a `useState` array, and **never compute money in the browser**: on every line change they debounce (400 ms) `POST /sales/totals/preview` (Task 2) and render the returned line amounts and totals. Product search uses `GET /catalog/products/search?q=&client_id=` (Task 2) through `@/components/erp/product-picker` (Dialog + search input + result list; selecting a row fills `product_id`, `description`, `unit_price` from the client's price list or the default list, `itbis_rate`).
- Every document has `index.tsx` (DataTable with status badges and filters), `form.tsx` (create/edit while draft) and `show.tsx` (read-only detail with action buttons, PDF link, "Enviar por correo" dialog, Historial tab via `RecordHistory`).
- Status badges: Spanish labels from the enum; colours: draft `secondary`, sent/pending `outline`, approved/issued/confirmed/posted `default`, paid `default`, rejected/expired/cancelled `destructive`.
- PDF links open `GET …/{id}/pdf` in a new tab (`target="_blank"`).

### GC-13 Audit and accounting events for documents
Every Action that changes a document status writes `activity('<table>')->causedBy($actor)->performedOn($doc)->event('<transition>')->withProperties([...])->log('<Spanish>')` and the task's tests assert it. Accounting-relevant transitions also write an `accounting_events` row (Task 1) — invoice issued/cancelled, credit note issued, debit note issued, receipt posted/cancelled, dispatch confirmed (cost of goods), return received, initial stock loaded — with the amounts Phase 5 needs (payload keys fixed per task). Tests assert the event rows and payload keys.

---

## Review Focus

1. **An invoice whose NCF sequence is exhausted or expired at issue time** must be refused with a clear message and must not consume a number → Task 4 tests `issuing with an exhausted sequence is refused`, `issuing with an expired sequence is refused`.
2. **Two concurrent issues must never produce the same NCF or internal number** → Task 1 test `concurrent next numbers are unique` and Task 4 test `fiscal numbers are assigned inside a locking transaction` (simulated with two sequential calls inside `DB::transaction` + `lockForUpdate`, plus a uniqueness constraint).
3. **A dispatch for more than the invoiced quantity, or from a lot without enough stock**, must be refused → Task 12 tests.
4. **A receipt applied for more than an invoice's balance, or a credit note for more than the remaining quantity/amount**, must be refused → Task 13 and Task 11 tests.
5. **A consumo invoice ≥ RD$250,000 for a client without tax id** must be refused with the Norma 10-2018 message → Task 4 test `consumo invoice at or above the threshold requires the client's tax id`.

---

### Task 1: Phase 2 foundations — permissions, internal numbering, immutability trait, accounting events outbox, settings keys

**Files:**
- Create: `app/Enums/DocumentKind.php`, `app/Services/Documents/DocumentNumbering.php`, `app/Models/DocumentSequence.php`, `app/Models/Concerns/ImmutableWhenIssued.php`, `app/Exceptions/ImmutableDocumentException.php`, `app/Models/AccountingEvent.php`, `app/Services/Accounting/AccountingEventRecorder.php`, `database/migrations/2026_11_01_000001_create_document_sequences_table.php`, `database/migrations/2026_11_01_000002_create_accounting_events_table.php`, `database/seeders/DocumentSequenceSeeder.php`, `tests/Feature/Documents/DocumentNumberingTest.php`, `tests/Feature/Documents/ImmutableWhenIssuedTest.php`, `tests/Feature/Accounting/AccountingEventRecorderTest.php`
- Modify: `app/Support/PermissionCatalog.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/Admin/PermissionCatalogTest.php`, `resources/js/nav.ts`

**Interfaces:**
- Produces: enum `DocumentKind: string { Proforma='proforma'; Invoice='invoice'; CreditNote='credit_note'; DebitNote='debit_note'; Receipt='receipt'; Dispatch='dispatch' }` with `prefix(): string` (`COT`, `FAC`, `NC`, `ND`, `REC`, `CND`) and `label()` ("Proforma", "Factura", "Nota de crédito", "Nota de débito", "Recibo", "Conduce"); `DocumentNumbering::next(DocumentKind $kind): string`; trait `ImmutableWhenIssued` (models declare `protected array $mutableWhenIssued` and `protected string $issuedAtColumn = 'issued_at'`); `AccountingEvent` model (`type`, `document_type`, `document_id`, `occurred_on`, `currency_id`, `exchange_rate`, `payload`, `processed_at`); `AccountingEventRecorder::record(string $type, Model $document, array $payload, ?CarbonInterface $occurredOn = null): AccountingEvent`; new permissions (list below); nav groups "Ventas" and "Inventario" entries.

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
$add('sales.email.send', 'Enviar correos desde el sistema', [...$all, 'sales']);

// fiscal (phase 2 subset)
$add('fiscal.sequences.view', 'Ver secuencias NCF', $all);
$add('fiscal.sequences.manage', 'Administrar secuencias NCF', ['owner', 'accounting']);
$add('fiscal.mode.manage', 'Cambiar modo fiscal (NCF/e-CF)', ['owner']);

// inventory (phase 2 subset)
$add('inventory.stock.view', 'Ver existencias', [...$all, 'sales', 'warehouse']);
$add('inventory.stock.view-costs', 'Ver costos de inventario', $all);
$add('inventory.movements.view', 'Ver movimientos de inventario (kardex)', [...$all, 'warehouse']);
$add('inventory.lots.view', 'Ver lotes y vencimientos', [...$all, 'sales', 'warehouse']);
$add('inventory.dispatches.create', 'Crear conduces', [...$all, 'warehouse']);
$add('inventory.dispatches.confirm', 'Confirmar conduces (salida de almacén)', [...$all, 'warehouse']);
$add('inventory.initial-stock.import', 'Cargar existencias iniciales', ['owner', 'accounting', 'warehouse']);
$add('inventory.receipts.create', 'Registrar entradas de inventario', [...$all, 'warehouse']);
```
Add to `modules()`: `'sales' => 'Ventas'`, `'fiscal' => 'Fiscal'` (inventory exists). In `tests/Feature/Admin/PermissionCatalogTest.php` extend `seeder creates all roles and permissions idempotently` with `->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.issue'))->toBeTrue()->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.cancel'))->toBeFalse()->and(Role::findByName('warehouse')->hasPermissionTo('inventory.dispatches.confirm'))->toBeTrue()->and(Role::findByName('warehouse')->hasPermissionTo('inventory.stock.view-costs'))->toBeFalse()` and update the seeded count assertion in Phase 1's seeder step comment: the DB now holds **108 permissions** (`SELECT count(*) FROM permissions` after `php artisan db:seed`).

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
and extend the "Inventario" group with `{ title: 'Existencias', href: '/inventory/stock', icon: Boxes, permission: 'inventory.stock.view' }`, `{ title: 'Movimientos', href: '/inventory/movements', icon: ArrowLeftRight, permission: 'inventory.movements.view' }`, `{ title: 'Lotes', href: '/inventory/lots', icon: CalendarClock, permission: 'inventory.lots.view' }`, `{ title: 'Conduces', href: '/inventory/dispatches', icon: Truck, permission: 'inventory.dispatches.create' }`; and the "Administración" group with `{ title: 'Secuencias NCF', href: '/admin/ncf-sequences', icon: Hash, permission: 'fiscal.sequences.view' }`, `{ title: 'Plantillas de correo', href: '/admin/email-templates', icon: Mail, permission: 'admin.settings.manage' }` (import the icons `FileText, Receipt, FileMinus, FilePlus, HandCoins, Scale, ArrowLeftRight, CalendarClock, Hash, Mail` from `lucide-react`).

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

    protected array $mutableWhenIssued = ['status'];
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
 * Models declare `protected array $mutableWhenIssued = [...]` (columns that may still change after issue)
 * and optionally `protected string $issuedAtColumn = 'issued_at'`.
 */
trait ImmutableWhenIssued
{
    public static function bootImmutableWhenIssued(): void
    {
        static::updating(function ($model): void {
            $column = $model->issuedAtColumn ?? 'issued_at';
            if ($model->getOriginal($column) === null) {
                return; // the transition that sets issued_at is allowed
            }
            $dirty = array_keys($model->getDirty());
            $allowed = [...($model->mutableWhenIssued ?? []), 'updated_at'];
            if (array_diff($dirty, $allowed) !== []) {
                throw new ImmutableDocumentException;
            }
        });

        static::deleting(function ($model): void {
            $column = $model->issuedAtColumn ?? 'issued_at';
            if ($model->getOriginal($column) !== null) {
                throw new ImmutableDocumentException;
            }
        });
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

- [ ] **Step 6: Migrate, seed, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan db:seed --no-interaction 2>&1 | tail -1 && docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT (SELECT count(*) FROM permissions)||' permissions, '||(SELECT count(*) FROM document_sequences)||' sequences'"` → `108 permissions, 6 sequences`. Then `npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (263 tests).

- [ ] **Step 7: Commit** — `feat(documents): add phase 2 permissions, internal numbering, immutability trait and accounting events`

---

### Task 2: `DocumentTotalsCalculator`, totals preview endpoint, product search endpoint, product picker

**Files:**
- Create: `app/Services/Documents/DocumentTotalsCalculator.php`, `app/Support/Documents/LineInput.php`, `app/Support/Documents/ComputedLine.php`, `app/Support/Documents/ComputedTotals.php`, `app/Http/Controllers/Sales/TotalsPreviewController.php`, `app/Http/Controllers/Catalog/ProductSearchController.php`, `app/Http/Requests/Sales/TotalsPreviewRequest.php`, `routes/sales.php`, `resources/js/components/erp/product-picker.tsx`, `resources/js/components/erp/document-lines-editor.tsx`, `resources/js/types/sales.ts`, `resources/js/hooks/use-totals-preview.ts`, `tests/Feature/Documents/DocumentTotalsCalculatorTest.php`, `tests/Feature/Sales/TotalsPreviewTest.php`, `tests/Feature/Catalog/ProductSearchTest.php`
- Modify: `routes/web.php`, `routes/catalog.php`

**Interfaces:**
- Consumes: `Decimal`, `AdditionalTaxCalculator`, `Product`, `PriceList`, `PriceListItem`, `Client`.
- Produces: `LineInput` (readonly: `int $productId, string $quantity, string $unitPrice, string $discountPercent, ?string $description`); `ComputedLine` (readonly: `int $productId, string $description, string $quantity, string $unitPrice, string $discountPercent, string $lineSubtotal, string $discountAmount, string $taxableBase, string $itbisRate, string $itbisAmount, array $additionalTaxes /* list<array{code,name,amount}> */, string $additionalTaxTotal, string $lineTotal`); `ComputedTotals` (readonly: `array $lines /* list<ComputedLine> */, string $subtotal, string $discountTotal, string $taxableTotal, string $itbisTotal, string $additionalTaxTotal, string $total`); `DocumentTotalsCalculator::compute(array $lines /* list<LineInput> */): ComputedTotals`; `DocumentTotalsCalculator::priceFor(Product $product, ?Client $client): string` (client's list → default list → `'0.0000'`); routes `sales.totals.preview` (POST `/sales/totals/preview`, auth) and `catalog.products.search` (GET `/catalog/products/search`, permission `catalog.products.view`); React `ProductPicker` (`onPick(product: PickedProduct)`), `DocumentLinesEditor` (`lines`, `onChange`, `computed`, `readOnly?`), hook `useTotalsPreview(lines, currencyCode) → { computed, loading }`; TS types `DocumentLineInput`, `ComputedLine`, `ComputedTotals`, `PickedProduct`.

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
    ) {}

    /** @param array{product_id: int|string, quantity: string, unit_price: string, discount_percent?: string|null, description?: string|null} $row */
    public static function fromArray(array $row): self
    {
        return new self((int) $row['product_id'], (string) $row['quantity'], (string) $row['unit_price'], (string) ($row['discount_percent'] ?? '0'), $row['description'] ?? null);
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
            $itbisRate = Decimal::scale((string) $product->itbis_rate, 2);
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
`TotalsPreviewRequest` rules: `'lines' => ['present', 'array', 'max:500'], 'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'], 'lines.*.description' => ['nullable', 'string', 'max:200']`. `TotalsPreviewController::__invoke(TotalsPreviewRequest $request, DocumentTotalsCalculator $calc): JsonResponse` → `response()->json($calc->compute(array_map(LineInput::fromArray(...), $request->validated('lines')))->toArray())`.
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

- [ ] **Step 5: Gate** — `php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (271 tests).

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

- [ ] **Step 6: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (285 tests).

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

Rules: all quantities/costs are 4-dp strings; `StockBalance` rows are created lazily; every movement writes `balance_after` (product+warehouse+lot level) and the accounting event `inventory_movement` with payload `{type, product_id, sku, warehouse_id, lot_id, quantity, unit_cost, total_cost, currency: 'DOP', exchange_rate: '1.000000'}`; movements are append-only (`StockMovement` uses `ImmutableWhenIssued` with `$issuedAtColumn = 'moved_at'` and `$mutableWhenIssued = []`).

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
`StockMovement`: `use ImmutableWhenIssued, LogsModelActivity;` `protected string $issuedAtColumn = 'moved_at'; protected array $mutableWhenIssued = [];` fillable all; casts `type` → enum, `quantity/unit_cost/balance_after` → `decimal:4`, `total_cost` → `decimal:2`, `moved_at` → datetime; relations `product`, `warehouse`, `lot`, `user`, `reference(): MorphTo`.
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
                ->dividedBy($newQty, 4, RoundingMode::HALF_UP);
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
            $balance->forceFill(['reserved_quantity' => Decimal::qty(max(0, (float) 0) === 0 ? Decimal::of($balance->reserved_quantity)->minus(Decimal::of($qty)) : '0')])->save();
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
In `release()`, replace the convoluted `reserved_quantity` line with exactly: `$balance->forceFill(['reserved_quantity' => Decimal::qty(Decimal::of($balance->reserved_quantity)->minus(Decimal::of($qty)))])->save();` (the sketch above shows the intent; use this line). Add to `Product`: `public function balances(): HasMany { return $this->hasMany(StockBalance::class); }`.

- [ ] **Step 5: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT indexname FROM pg_indexes WHERE tablename='stock_balances' AND indexname='stock_balances_product_warehouse_lot_unique'" && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → index name printed; passed (291 tests).

- [ ] **Step 6: Commit** — `feat(inventory): add lots, stock balances, movements, reservations and inventory service`

---

### Task 5: Initial stock — manual entry screen and Excel importer (with lots and cost)

**Files:**
- Create: `app/Actions/Inventory/LoadInitialStock.php`, `app/Http/Controllers/Inventory/InitialStockController.php`, `app/Http/Requests/Inventory/StoreInitialStockRequest.php`, `app/Imports/InitialStockImporter.php`, `resources/js/pages/inventory/initial-stock/form.tsx`, `resources/js/types/inventory.ts` (append), `tests/Feature/Inventory/InitialStockTest.php`, `tests/Feature/DataImport/InitialStockImporterTest.php`
- Modify: `routes/inventory.php`, `app/Imports/ImporterRegistry.php`, `app/Http/Controllers/DataImport/ImportController.php` (meta options: `warehouses` list for `initial_stock`), `resources/js/pages/admin/imports/index.tsx` (warehouse `Select name="warehouse_id"` when `importer.meta.warehouses` exists), `resources/js/nav.ts` (Inventario → `{ title: 'Existencias iniciales', href: '/inventory/initial-stock', icon: PackagePlus, permission: 'inventory.initial-stock.import' }`)

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

- [ ] **Step 3: Migrate, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (294 tests).

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

- [ ] **Step 4: Gate** — `vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (295 tests).

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

Run: `composer require "google/apiclient:^2.20" --no-interaction 2>&1 | tail -2 && composer show google/apiclient | grep '^versions'` → `versions : * v2.2<x>`. Add to `config/erp.php`: `'gmail' => ['driver' => env('GMAIL_DRIVER', env('APP_ENV') === 'production' ? 'google' : 'log'), 'scope' => 'https://www.googleapis.com/auth/gmail.send']`. Append `GMAIL_DRIVER=log` to `.env` and `.env.example`. In `AppServiceProvider::register()`: `$this->app->bind(\App\Contracts\GmailSender::class, fn () => config('erp.gmail.driver') === 'google' ? $this->app->make(\App\Services\Mail\GoogleGmailSender::class) : $this->app->make(\App\Services\Mail\LogGmailSender::class));`. Add `'gmail_connected' => $user ? $user->gmail_connected_at !== null : false` inside the shared `auth` array of `HandleInertiaRequests` and `gmail_connected: boolean` to the `Auth` TS type.

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

`add_gmail_fields_to_users_table`: `text('gmail_refresh_token')->nullable()`, `string('gmail_email', 255)->nullable()`, `timestamp('gmail_connected_at')->nullable()`. `User`: add the three to `$fillable`, casts `'gmail_refresh_token' => 'encrypted'`, `'gmail_connected_at' => 'datetime'`; add `gmail_refresh_token` to `$hidden`; `LogsModelActivity` must not log the token: override in `User` `public function getActivitylogOptions(): LogOptions { return parent-like defaults ... ->logExcept(['gmail_refresh_token', 'password', 'remember_token']); }` — concretely copy the trait's body and append `->logExcept(['gmail_refresh_token', 'password', 'remember_token'])`.
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
`GmailConnectController`: `connect()` → `Socialite::driver('google')->scopes([config('erp.gmail.scope')])->with(['access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $request->user()->email])->redirectUrl(route('auth.gmail.callback'))->redirect()`; `callback()` → `$g = Socialite::driver('google')->redirectUrl(route('auth.gmail.callback'))->user()`; if `strtolower($g->getEmail()) !== strtolower($request->user()->email)` → error `Debes conectar la misma cuenta de Google con la que iniciaste sesión ({email}).`; if `! in_array(config('erp.gmail.scope'), $g->approvedScopes ?? [], true)` → error `Google no concedió el permiso para enviar correos (gmail.send).`; if `$g->refreshToken` empty → error `Google no devolvió un token de acceso persistente; vuelve a intentarlo.`; else `forceFill(['gmail_refresh_token' => $g->refreshToken, 'gmail_email' => strtolower($g->getEmail()), 'gmail_connected_at' => now()])->save()`, `activity('auth')->causedBy($user)->event('gmail_connected')->log('Gmail conectado')`, redirect dashboard `'Gmail conectado: {email}'`; `disconnect()` → null the three columns, `activity('auth')…event('gmail_disconnected')`, back with `'Gmail desconectado'`.
`user-menu-content.tsx`: add item "Conectar Gmail" (`<a href={connect.url()}>`) when `!auth.gmail_connected`, or "Desconectar Gmail" (`router.delete(disconnect.url())`) when connected.
Templates admin: routes `Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index'); Route::put('email-templates/{emailTemplate:key}', [EmailTemplateController::class, 'update'])->name('email-templates.update');` both `permission:admin.settings.manage`. `UpdateEmailTemplateRequest` rules: `'subject' => ['required', 'string', 'max:200'], 'body_html' => ['required', 'string', 'max:20000', 'regex:/^(?!.*<script).*$/is']` with message `body_html.regex` → `La plantilla no puede contener scripts.`. Controller `index` → `templates` (`key, subject, body_html`) + `placeholders` list (`cliente, numero, empresa, total, fecha, vendedor, usuario`); `update` → save → `'Plantilla actualizada'`. Page `admin/email-templates/index.tsx` (title "Plantillas de correo"): one `Card` per template (title = key label: Proforma, Factura, Estado de cuenta, Nota de crédito) with `<Form {...update.form(key)}>`: `subject` Input "Asunto", `body_html` Textarea "Cuerpo (HTML)" rows 8, hint listing the placeholders, button "Guardar".
`resources/js/components/erp/send-email-dialog.tsx`: `Dialog` "Enviar por correo" with fields Para (`Input` comma-separated, default `defaultTo.join(', ')`), CC, Asunto (prefilled from a `GET` of the rendered template? no — leave empty = use template), Mensaje (`Textarea`, empty = template), note "Se enviará desde tu Gmail ({gmail_email}) con el PDF adjunto"; when `!auth.gmail_connected` show instead an `Alert` "Conecta tu cuenta de Gmail para enviar correos" with a link to `connect.url()`; submit → `router.post(submitUrl, { to, cc, subject, body })`.

- [ ] **Step 6: Migrate, seed, generate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan db:seed --class=EmailTemplateSeeder --no-interaction && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && npm run check:fix && npm run types:check && vendor/bin/pint --dirty && composer test 2>&1 | tail -6` → passed (302 tests).

- [ ] **Step 7: Commit** — `feat(mail): add Gmail send-as-user, email templates and sent-mail log`
