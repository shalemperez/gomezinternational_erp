# Phase 1b — Final-review fixes: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Executors are Claude Haiku agents that **decide nothing**: read ARCH §11 (`docs/superpowers/plans/2026-10-29-00-architecture.md`) first, then the Phase 1 plan's Global Constraints (`docs/superpowers/plans/2026-10-29-01-fase1-base.md`, GC-1 … GC-9 — they apply unchanged, including the PHPStan level 7 conventions and the commit rule).

**Goal:** Close the items the final whole-branch review marked "fix before merge / before go-live" on `phase-1-base` (HEAD `9486339`, 312 tests green): production runbook + trusted proxies, queued invitation, export formula-injection escaping, numeric bounds, contact diffing by id with a single primary, price-list items page visibility and performance, importer rule parity and race handling, leading-zero tax ids, transactional seeder, export permissions, degree-liter rounding order, stale fields, array-input guard, DB-level single default, dead rule removal.

**Architecture:** No new subsystems. Each task edits the current code (read it before editing — several files changed during fix rounds; the snippets below were written against HEAD `9486339`).

**Tech Stack:** unchanged (Phase 1).

**Spec:** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/specs/2026-10-29-gomez-erp-design.md`. **Review:** `.superpowers/sdd/2026-10-29-01-fase1-base/final-review-out.log`. **Ledger:** `.superpowers/sdd/2026-10-29-01-fase1-base/progress.md`.

**Branch:** `phase-1-base` (continue on it). **Gate (every task):** `vendor/bin/pint --dirty && npm run check && composer test 2>&1 | tail -8` → pint `"result":"passed"`, `npm run check` exit 0, phpstan `"errors":0`, phpunit `"result":"passed"` with the task's N (indicative). **Commit rule:** Phase 1 GC-1. Test totals start at **312**.

Out of scope (deferred per the reviewer's triage; do not touch): everything listed under "Can stay deferred" in the review, large-export queueing (minor 3), the duplicated code generator (minor 7), Wayfinder nav hrefs (minor 8), float-free JSON rate parsing (minor 9), audit noise on create-with-override (minor 11).

---

### Task 1: Production runbook, trusted proxies, queued invitation, transactional seeder, README formatting

**Files:**
- Modify: `README.md`, `bootstrap/app.php`, `.env.example`, `phpunit.xml`, `app/Notifications/UserInvited.php`, `database/seeders/PermissionSeeder.php`, `vite.config.ts`, `tests/Feature/Admin/UserManagementTest.php`, `tests/Feature/Admin/PermissionCatalogTest.php`
- Create: `tests/Feature/Auth/TrustedProxiesTest.php`

**Interfaces:**
- Produces: env `TRUSTED_PROXIES` (comma-separated IPs/CIDRs, or `*`; empty = none) consumed by `bootstrap/app.php`; `UserInvited implements ShouldQueue` with `afterCommit`; `PermissionSeeder::run()` atomic.

- [ ] **Step 1: README — add a "Producción" section** (append after "## Pruebas y calidad", before "## Documentación"):
```markdown
## Producción (Forge / VPS)
Requisitos: PHP 8.4, PostgreSQL 17, Node 22 solo para compilar (`npm ci && npm run build`).

1. **Variables de entorno obligatorias** (`.env`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_TIMEZONE=America/Santo_Domingo` (si falta, Laravel usa UTC y todas las fechas se desplazan), `SESSION_SECURE_COOKIE=true`, `DB_*`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://…/auth/google/callback`, `GOOGLE_WORKSPACE_DOMAIN`, `SUPER_ADMIN_EMAILS`, `MAIL_MAILER=smtp` con `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
2. **Proxies**: si la app está detrás de Cloudflare o un balanceador, define `TRUSTED_PROXIES` con las IP/CIDR del proxy separadas por coma (o `*` si el proxy es el único acceso); vacío = ninguno. Sin esto la bitácora registra la IP del proxy en vez de la del usuario.
3. **Despliegue**: `composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan db:seed --force && php artisan storage:link && php artisan optimize && npm ci && npm run build`.
4. **Cola** (obligatoria: tasas de cambio, correos de invitación y alertas corren en cola): un daemon `php artisan queue:work --queue=default --tries=3 --timeout=120` supervisado (Forge → Daemons / Supervisor). Tras cada despliegue: `php artisan queue:restart`.
5. **Programador** (obligatorio: obtención diaria de tasas): cron `* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1`.
6. **Respaldos**: base de datos y `storage/app` diarios, fuera del servidor.
```

- [ ] **Step 2: Trusted proxies** — in `bootstrap/app.php` inside `withMiddleware`, add as the first statement:
```php
$trusted = trim((string) env('TRUSTED_PROXIES', ''));
if ($trusted !== '') {
    $middleware->trustProxies(at: $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
}
```
Append to `.env.example` after `APP_TIMEZONE=…`: `TRUSTED_PROXIES=` and after `SESSION_DOMAIN=null`: `SESSION_SECURE_COOKIE=false`. Add to `phpunit.xml` `<php>`: `<env name="TRUSTED_PROXIES" value="*"/>` (tests only).

- [ ] **Step 3: Queue the invitation after commit** — `app/Notifications/UserInvited.php`: add `use Illuminate\Contracts\Queue\ShouldQueue;` and change the class line to `class UserInvited extends Notification implements ShouldQueue`; add the property `public bool $afterCommit = true;` right after `use Queueable;`. (`SaveUser` is unchanged: `notify()` now enqueues after the transaction commits.)

- [ ] **Step 4: Transactional seeder** — in `PermissionSeeder::run()` wrap everything between the two `forgetCachedPermissions()` calls in `DB::transaction(function (): void { … });` (import `Illuminate\Support\Facades\DB`; the `$roles`/`$newRoles` arrays live inside the closure).

- [ ] **Step 5: README out of vp fmt** — in `vite.config.ts` → `fmt.ignorePatterns` append `'README.md'`.

- [ ] **Step 6: Tests**

Append to `tests/Feature/Admin/UserManagementTest.php`:
```php
test('the invitation is queued after commit', function () {
    $notification = new UserInvited;
    expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class)->and($notification->afterCommit)->toBeTrue();
});
```
Append to `tests/Feature/Admin/PermissionCatalogTest.php`:
```php
test('seeder runs inside a database transaction', function () {
    \Illuminate\Support\Facades\Event::fake([\Illuminate\Database\Events\TransactionBeginning::class]);
    (new PermissionSeeder)->run();
    \Illuminate\Support\Facades\Event::assertDispatched(\Illuminate\Database\Events\TransactionBeginning::class);
});
```
`tests/Feature/Auth/TrustedProxiesTest.php`:
```php
<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('audit rows record the client ip behind a trusted proxy', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->post(route('logout'))->assertRedirect(route('login'));

    $row = Activity::inLog('auth')->where('event', 'logout')->where('causer_id', $user->id)->latest('id')->firstOrFail();
    expect($row->properties['ip'])->toBe('203.0.113.9')->and($row->ip)->toBe('203.0.113.9');
});
```

- [ ] **Step 7: Gate** → passed (315 tests).
- [ ] **Step 8: Commit** — `fix(ops): production runbook, trusted proxies, queued invitation, transactional seeder`

---

### Task 2: Formula-injection-safe exports (numbers stay numeric)

**Files:**
- Modify: `app/Exports/TableExport.php`, `tests/Feature/Support/TableExportTest.php`

**Interfaces:**
- Produces: `TableExport` escapes CSV cells that start with `=`, `+`, `-`, `@`, tab or CR by prefixing `'` unless the value is numeric; for xlsx it binds every non-numeric string explicitly as `DataType::TYPE_STRING` (never a formula) through `WithCustomValueBinder`; numeric strings/ints/floats stay numeric in both formats.

- [ ] **Step 1: Failing test** — append to `tests/Feature/Support/TableExportTest.php`:
```php
test('exports neutralise spreadsheet formulas but keep numbers numeric', function () {
    actingAsRole('owner');
    $u = User::factory()->create(['name' => '=HYPERLINK("http://x/?"&B2,"ver")', 'email' => 'f@x.test']);
    $columns = [
        'Nombre' => 'name',
        'Mas' => fn (User $x) => '+1',
        'Menos' => fn (User $x) => '-cmd',
        'Arroba' => fn (User $x) => '@SUM(1)',
        'Tab' => fn (User $x) => "\t=1",
        'Negativo' => fn (User $x) => '-5',
        'Decimal' => fn (User $x) => '10.5000',
        'Entero' => fn (User $x) => 7,
    ];
    $query = fn () => User::query()->whereKey($u->id);

    $csv = file_get_contents(TableExport::fromQuery($query(), 'usuarios', $columns, 'csv')->getFile()->getPathname());
    expect($csv)->toContain('"\'=HYPERLINK(""http://x/?""&B2,""ver"")"')->toContain('"\'+1"')->toContain('"\'-cmd"')->toContain('"\'@SUM(1)"')->toContain("\"'\t=1\"")
        ->toContain('"-5"')->toContain('"10.5000"')->toContain('"7"');

    $xlsxPath = TableExport::fromQuery($query(), 'usuarios', $columns, 'xlsx')->getFile()->getPathname();
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath)->getActiveSheet();
    expect($sheet->getCell('A2')->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
        ->and($sheet->getCell('A2')->getValue())->toBe('=HYPERLINK("http://x/?"&B2,"ver")')
        ->and($sheet->getCell('F2')->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC)
        ->and((string) $sheet->getCell('G2')->getValue())->toBe('10.5')
        ->and($sheet->getCell('H2')->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
});
```
Run: `php artisan test --filter=TableExportTest 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Implement** — before editing run Boost `search-docs ["laravel excel WithCustomValueBinder"]` to confirm that in Laravel Excel 4 the export class itself extends `PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder` and implements `Maatwebsite\Excel\Concerns\WithCustomValueBinder` (interface method `bindValue(Cell $cell, mixed $value): bool`). Then edit `app/Exports/TableExport.php`:
  - class declaration: `final class TableExport extends DefaultValueBinder implements FromQuery, WithCustomCsvSettings, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison` (imports `PhpOffice\PhpSpreadsheet\Cell\Cell`, `PhpOffice\PhpSpreadsheet\Cell\DataType`, `PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder`, `Maatwebsite\Excel\Concerns\WithCustomValueBinder`);
  - constructor gains a third parameter `private readonly string $format = 'xlsx'` and `fromQuery` passes `$format` (`new self($query, $columns, $format)`);
  - replace `map()` with:
```php
/** @param Model $row */
public function map(mixed $row): array
{
    return array_map(function ($accessor) use ($row): mixed {
        $value = $accessor instanceof Closure ? $accessor($row) : data_get($row, $accessor);

        return $this->format === 'csv' ? self::csvSafe($value) : $value;
    }, array_values($this->columns));
}

/** CSV cells are typed by the consumer, so active content must be neutralised with a leading apostrophe. */
public static function csvSafe(mixed $value): mixed
{
    if (! is_string($value) || $value === '' || is_numeric($value)) {
        return $value;
    }

    return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
}

/** xlsx: every non-numeric string is stored as a literal string, never evaluated as a formula. */
public function bindValue(Cell $cell, mixed $value): bool
{
    if (is_string($value) && ! is_numeric($value)) {
        $cell->setValueExplicit($value, DataType::TYPE_STRING);

        return true;
    }

    return parent::bindValue($cell, $value);
}
```
- [ ] **Step 3: Gate** → passed (317 tests).
- [ ] **Step 4: Commit** — `fix(export): neutralise spreadsheet formula injection while keeping numbers numeric`

---

### Task 3: Numeric bounds, stale fields, array-input guard

**Files:**
- Modify: `app/Http/Requests/Catalog/StoreProductRequest.php` (and `UpdateProductRequest.php` if it repeats the numeric rules — check with `grep -n "weight_kg" app/Http/Requests/Catalog/UpdateProductRequest.php`), `app/Http/Requests/Catalog/UpdatePriceListItemsRequest.php`, `app/Http/Requests/Catalog/StoreClientRequest.php`, `app/Http/Requests/Catalog/StoreAdditionalTaxRequest.php`, `app/Http/Requests/Finance/StoreFinancialAccountRequest.php`, `app/Rules/DominicanTaxId.php`, `tests/Feature/Catalog/ProductTest.php`, `tests/Feature/Catalog/AdditionalTaxTest.php`, `tests/Feature/Finance/FinancialAccountTest.php`, `tests/Feature/Support/DominicanTaxIdRuleTest.php`

- [ ] **Step 1: Failing tests** — append:

`tests/Feature/Catalog/ProductTest.php`:
```php
test('numeric fields are bounded with spanish errors instead of database errors', function () {
    actingAsRole('owner');
    $this->post(route('catalog.products.store'), validProductPayload(['weight_kg' => '99999999', 'volume_liters' => '1000000', 'minimum_stock' => '99999999999', 'alcohol_degree' => '1.234']))
        ->assertSessionHasErrors(['weight_kg', 'volume_liters', 'minimum_stock', 'alcohol_degree']);
    $this->post(route('catalog.products.store'), validProductPayload(['weight_kg' => '999999.9999', 'volume_liters' => '0.75', 'minimum_stock' => '9999999999.9999', 'alcohol_degree' => '37.5']))
        ->assertRedirect(route('catalog.products.index'));
});
```
`tests/Feature/Catalog/AdditionalTaxTest.php`:
```php
test('changing the calculation type nulls the unused rate or amount', function () {
    actingAsRole('owner');
    $tax = AdditionalTax::factory()->create(['calculation_type' => 'percent_of_base', 'rate' => '10', 'amount' => null]);
    $this->put(route('catalog.additional-taxes.update', $tax), validAdditionalTaxPayload(['code' => $tax->code, 'calculation_type' => 'fixed_per_unit', 'rate' => '10', 'amount' => '25']))->assertRedirect(route('catalog.additional-taxes.index'));
    expect($tax->fresh()->rate)->toBeNull()->and($tax->fresh()->amount)->toBe('25.0000');
    $this->put(route('catalog.additional-taxes.update', $tax), validAdditionalTaxPayload(['code' => $tax->code, 'calculation_type' => 'percent_of_base', 'rate' => '12', 'amount' => '25']))->assertRedirect(route('catalog.additional-taxes.index'));
    expect($tax->fresh()->rate)->toBe('12.0000')->and($tax->fresh()->amount)->toBeNull();
});
```
`tests/Feature/Finance/FinancialAccountTest.php`:
```php
test('switching an account to cash clears the bank fields', function () {
    actingAsRole('accounting');
    $account = FinancialAccount::factory()->create(['type' => 'bank', 'bank_name' => 'Banco Popular', 'account_number' => '1']);
    $this->put(route('finance.accounts.update', $account), validFinancialAccountPayload(['type' => 'cash', 'name' => 'Caja', 'bank_name' => 'Banco Popular', 'account_number' => '1']))->assertRedirect(route('finance.accounts.index'));
    expect($account->fresh()->bank_name)->toBeNull()->and($account->fresh()->account_number)->toBeNull();
});
```
`tests/Feature/Support/DominicanTaxIdRuleTest.php`:
```php
test('array input fails validation instead of crashing', function () {
    expect(Validator::make(['v' => ['101850043']], ['v' => [new DominicanTaxId('rnc')]])->errors()->first('v'))->toBe('El RNC debe tener 9 dígitos y un dígito verificador válido.')
        ->and(Validator::make(['v' => ['x']], ['v' => [new DominicanTaxId('cedula')]])->errors()->first('v'))->toBe('La cédula debe tener 11 dígitos y un dígito verificador válido.');
});
```
Run: `php artisan test --filter='ProductTest|AdditionalTaxTest|FinancialAccountTest|DominicanTaxIdRuleTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Bounds** (column precisions: `weight_kg`/`volume_liters` `decimal(10,4)`, `minimum_stock` `decimal(14,4)`, `alcohol_degree` `decimal(5,2)`, `price` `decimal(14,4)`, `credit_limit` `decimal(14,2)`):
  - `StoreProductRequest::rules()`: `'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999.9999', 'regex:/^\d{1,6}(\.\d{1,4})?$/']`, `'volume_liters' => ['nullable', 'numeric', 'min:0', 'max:999999.9999', 'regex:/^\d{1,6}(\.\d{1,4})?$/']`, `'alcohol_degree' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/']`, `'minimum_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/']`. Apply the same arrays in `UpdateProductRequest` wherever it repeats them.
  - `UpdatePriceListItemsRequest`: `'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999', 'regex:/^\d{1,10}(\.\d{1,4})?$/']`.
  - `StoreClientRequest`: `'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/']`.

- [ ] **Step 3: Stale fields** — `StoreAdditionalTaxRequest::prepareForValidation()`: after the existing merge add
```php
if ($this->input('calculation_type') === 'percent_of_base') {
    $this->merge(['amount' => null]);
} elseif ($this->filled('calculation_type')) {
    $this->merge(['rate' => null]);
}
```
`StoreFinancialAccountRequest::prepareForValidation()`: after the existing loop add `if ($this->input('type') === 'cash') { $this->merge(['bank_name' => null, 'account_number' => null]); }`. (Both Update requests inherit `prepareForValidation`.)

- [ ] **Step 4: Array guard** — `DominicanTaxId::validate()`: replace `$value = (string) $value;` with
```php
if (! is_string($value)) {
    $fail($this->type === 'cedula' ? 'La cédula debe tener 11 dígitos y un dígito verificador válido.' : 'El RNC debe tener 9 dígitos y un dígito verificador válido.');

    return;
}
```

- [ ] **Step 5: Gate** → passed (321 tests).
- [ ] **Step 6: Commit** — `fix(validation): numeric bounds, stale rate/amount and bank fields, array-safe tax id rule`

---

### Task 4: Contacts are diffed by id and only one can be primary

**Files:**
- Modify: `app/Actions/Catalog/ReplaceContacts.php`, `app/Http/Requests/Catalog/StoreClientRequest.php`, `app/Http/Requests/Catalog/UpdateClientRequest.php`, `app/Http/Requests/Catalog/StoreSupplierRequest.php`, `app/Http/Requests/Catalog/UpdateSupplierRequest.php`, `resources/js/pages/catalog/clients/form.tsx`, `resources/js/pages/catalog/suppliers/form.tsx`, `resources/js/types/catalog.ts` (if `ClientContact`/`SupplierContact` lack `id?: number | null`), `tests/Feature/Catalog/ClientTest.php`, `tests/Feature/Catalog/SupplierTest.php`

**Interfaces:**
- Produces: `ReplaceContacts::handle(HasMany $relation, array $contacts): void` now **syncs**: rows with an `id` that belongs to the parent are updated (only when something changed), rows without `id` are created, existing rows absent from the payload are deleted (through the models, audited); validation `contacts.*.id` (nullable integer; on update must belong to the record being edited), and at most one `is_primary` per payload (`contacts`: `Solo un contacto puede ser el principal.`).

- [ ] **Step 1: Replace the two contact tests**

In `tests/Feature/Catalog/ClientTest.php` replace the whole test `contacts are saved and replaced on update with audit rows` by:
```php
test('contacts are synced by id: updated, created and deleted with audit rows', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'contacts' => [
        ['name' => 'Ana', 'position' => 'Compras', 'email' => 'ana@ejemplo.test', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'Beto', 'position' => '', 'email' => '', 'phone' => '809-1', 'is_primary' => '0'],
    ]]))->assertRedirect(route('catalog.clients.index'));
    $client = Client::latest('id')->firstOrFail();
    [$ana, $beto] = $client->contacts()->orderBy('id')->get()->all();

    $this->put(route('catalog.clients.update', $client), validClientPayload(['tax_id' => $client->tax_id, 'contacts' => [
        ['id' => $ana->id, 'name' => 'Ana María', 'position' => 'Compras', 'email' => 'ana@ejemplo.test', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'Carla', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0'],
    ]]))->assertRedirect(route('catalog.clients.index'));

    expect($client->fresh()->contacts()->orderBy('id')->pluck('name')->all())->toBe(['Ana María', 'Carla'])
        ->and(ClientContact::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'updated')->where('subject_id', $ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'deleted')->where('subject_id', $beto->id)->exists())->toBeTrue()
        ->and(Activity::inLog('client_contacts')->where('event', 'created')->count())->toBe(3)
        ->and(Activity::inLog('client_contacts')->where('event', 'deleted')->count())->toBe(1);
});

test('at most one contact can be primary and foreign contact ids are rejected', function () {
    actingAsRole('owner');
    $this->post(route('catalog.clients.store'), validClientPayload(['tax_id' => fakeRnc(), 'contacts' => [
        ['name' => 'A', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'],
        ['name' => 'B', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'],
    ]]))->assertSessionHasErrors(['contacts' => 'Solo un contacto puede ser el principal.']);

    $other = Client::factory()->create();
    $foreign = ClientContact::factory()->create(['client_id' => $other->id]);
    $mine = Client::factory()->create();
    $this->put(route('catalog.clients.update', $mine), validClientPayload(['tax_id' => $mine->tax_id, 'contacts' => [['id' => $foreign->id, 'name' => 'X', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0']]]))
        ->assertSessionHasErrors(['contacts.0.id']);
    expect($foreign->fresh()->client_id)->toBe($other->id);
});
```
(add `use App\Models\ClientContact;` at the top). In `tests/Feature/Catalog/SupplierTest.php` replace `contacts are replaced on update with audit rows` by the same test adapted to suppliers (`validSupplierPayload`, `Supplier`, `SupplierContact`, routes `catalog.suppliers.*`, log `supplier_contacts`) and add the single-primary assertion:
```php
test('supplier contacts are synced by id and only one can be primary', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['contacts' => [['name' => 'Ana', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'], ['name' => 'Beto', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0']]]));
    $s = Supplier::latest('id')->firstOrFail();
    [$ana, $beto] = $s->contacts()->orderBy('id')->get()->all();
    $this->put(route('catalog.suppliers.update', $s), validSupplierPayload(['contacts' => [['id' => $ana->id, 'name' => 'Ana M', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1']]]))->assertRedirect(route('catalog.suppliers.index'));
    expect($s->fresh()->contacts->pluck('name')->all())->toBe(['Ana M'])
        ->and(Activity::inLog('supplier_contacts')->where('event', 'updated')->where('subject_id', $ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('supplier_contacts')->where('event', 'deleted')->where('subject_id', $beto->id)->exists())->toBeTrue();
    $this->put(route('catalog.suppliers.update', $s), validSupplierPayload(['contacts' => [['name' => 'A', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'], ['name' => 'B', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1']]]))
        ->assertSessionHasErrors(['contacts' => 'Solo un contacto puede ser el principal.']);
});
```
Run: `php artisan test --filter='ClientTest|SupplierTest' 2>&1 | tail -3` → `"result":"failed"`.

- [ ] **Step 2: Action** — replace the body of `ReplaceContacts::handle` (keep the PHPDoc generics) with:
```php
$existing = $relation->get()->keyBy(fn ($contact) => (int) $contact->getKey());
$keep = [];
foreach ($contacts as $contact) {
    $attributes = [
        'name' => $contact['name'],
        'position' => $contact['position'] ?? null,
        'email' => $contact['email'] ?? null,
        'phone' => $contact['phone'] ?? null,
        'is_primary' => filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOL),
    ];
    $id = isset($contact['id']) && $contact['id'] !== '' ? (int) $contact['id'] : null;
    if ($id !== null && isset($existing[$id])) {
        $existing[$id]->fill($attributes)->save(); // no-op (and no audit row) when nothing changed
        $keep[] = $id;

        continue;
    }
    $keep[] = (int) $relation->create($attributes)->getKey();
}
foreach ($existing as $id => $contact) {
    if (! in_array($id, $keep, true)) {
        $contact->delete();
    }
}
```
Update the method PHPDoc first line to "Syncs the contacts of a parent: updates rows by id, creates rows without id, deletes rows that are missing (all through the models, so they are audited)."

- [ ] **Step 3: Requests** — in both `StoreClientRequest::rules()` and `StoreSupplierRequest::rules()` add `'contacts.*.id' => ['nullable', 'integer'],`. In `UpdateClientRequest::rules()` merge `'contacts.*.id' => ['nullable', 'integer', Rule::exists('client_contacts', 'id')->where('client_id', $this->route('client')?->getKey() ?? 0)]` and in `UpdateSupplierRequest::rules()` the same with `supplier_contacts`/`supplier_id`/`route('supplier')`. In both Store requests' `withValidator` add (inside the existing `after` closure, or a new one):
```php
$primary = collect($this->input('contacts', []))->filter(fn ($c) => filter_var($c['is_primary'] ?? false, FILTER_VALIDATE_BOOL))->count();
if ($primary > 1) {
    $v->errors()->add('contacts', 'Solo un contacto puede ser el principal.');
}
```
Add to `messages()` of both: `'contacts.*.id.exists' => 'El contacto no pertenece a este registro.'`.

- [ ] **Step 4: Forms** — in `clients/form.tsx` and `suppliers/form.tsx` the contact mapping that builds the initial `contacts` state must include `id: contact.id ?? null` and new rows (`addContact`) must include `id: null`; add `id?: number | null` to the `ClientContact`/`SupplierContact` TS types if missing. Nothing else in the UI changes.

- [ ] **Step 5: Gate** → `npm run types:check` clean; passed (325 tests).
- [ ] **Step 6: Commit** — `fix(catalog): sync contacts by id and enforce a single primary contact`

---

### Task 5: Price-list items visibility and performance, DB-level single default, inactive options kept on edit

**Files:**
- Create: `database/migrations/2026_10_29_000062_add_single_default_indexes.php`
- Modify: `app/Http/Controllers/Catalog/PriceListController.php`, `app/Http/Requests/Catalog/UpdatePriceListItemsRequest.php`, `app/Actions/Catalog/SavePriceListItems.php`, `app/Actions/Catalog/SavePriceList.php`, `app/Actions/Inventory/SaveWarehouse.php`, `app/Http/Controllers/Catalog/ClientController.php`, `app/Http/Controllers/Catalog/SupplierController.php`, `resources/js/pages/catalog/price-lists/items.tsx`, `resources/js/pages/catalog/price-lists/index.tsx`, `tests/Feature/Catalog/PriceListTest.php`, `tests/Feature/Inventory/WarehouseTest.php`, `tests/Feature/Catalog/ClientTest.php`

- [ ] **Step 1: Failing tests** — append:

`tests/Feature/Catalog/PriceListTest.php`:
```php
test('sales can view the items page read-only but cannot save prices', function () {
    actingAsRole('sales');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p->id, 'price' => '5.0000']);
    $this->get(route('catalog.price-lists.items', $list))->assertOk()->assertInertia(fn ($pg) => $pg->where('canEdit', false)->where('products.0.price', '5.0000'));
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '1']]])->assertForbidden();
});

test('items validation uses one query for product ids and reports unknown and duplicated products', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '1'], ['product_id' => 999999, 'price' => '2'], ['product_id' => $p->id, 'price' => '3']]])
        ->assertSessionHasErrors(['items.1.product_id' => 'El producto no existe.', 'items.2.product_id' => 'Producto repetido en la lista.']);
    $productQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "products"'))->count();
    \Illuminate\Support\Facades\DB::disableQueryLog();
    expect($productQueries)->toBe(1);
});

test('the database refuses a second default price list', function () {
    PriceList::factory()->create(['is_default' => true]);
    expect(fn () => PriceList::factory()->create(['is_default' => true]))->toThrow(\Illuminate\Database\QueryException::class);
    actingAsRole('owner');
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['name' => 'Nueva predeterminada', 'is_default' => 'on']))->assertRedirect(route('catalog.price-lists.index'));
    expect(PriceList::where('is_default', true)->count())->toBe(1)->and(PriceList::default()->name)->toBe('Nueva predeterminada');
});
```
`tests/Feature/Inventory/WarehouseTest.php`:
```php
test('the database refuses a second default warehouse', function () {
    Warehouse::factory()->create(['is_default' => true]);
    expect(fn () => Warehouse::factory()->create(['is_default' => true]))->toThrow(\Illuminate\Database\QueryException::class);
    actingAsRole('owner');
    $this->post(route('inventory.warehouses.store'), validWarehousePayload(['code' => 'NUEVO', 'is_default' => 'on']))->assertRedirect(route('inventory.warehouses.index'));
    expect(Warehouse::where('is_default', true)->count())->toBe(1)->and(Warehouse::default()->code)->toBe('NUEVO');
});
```
`tests/Feature/Catalog/ClientTest.php`:
```php
test('edit form keeps the current inactive salesperson and price list as options', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create(['is_active' => false]);
    $pl = PriceList::factory()->create(['is_active' => false]);
    $client = Client::factory()->create(['salesperson_id' => $sp->id, 'price_list_id' => $pl->id]);
    $this->get(route('catalog.clients.edit', $client))->assertInertia(fn ($p) => $p->where('salespeople.0.id', $sp->id)->where('priceLists.0.id', $pl->id));
    $this->get(route('catalog.clients.create'))->assertInertia(fn ($p) => $p->where('salespeople', [])->where('priceLists', []));
});
```
(add `use App\Models\PriceList; use App\Models\Salesperson;` where missing). Run the three files → `"result":"failed"`.

- [ ] **Step 2: Migration `2026_10_29_000062_add_single_default_indexes.php`**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX price_lists_single_default ON price_lists (is_default) WHERE is_default');
        DB::statement('CREATE UNIQUE INDEX warehouses_single_default ON warehouses (is_default) WHERE is_default');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS price_lists_single_default');
        DB::statement('DROP INDEX IF EXISTS warehouses_single_default');
    }
};
```
`SavePriceList::handle` and `SaveWarehouse::handle`: move the "clear the other defaults" block **before** `->save()` and condition it on the incoming data: `if (($data['is_default'] ?? false) === true) { PriceList::query()->whereKeyNot($list->getKey() ?? 0)->where('is_default', true)->get()->each->update(['is_default' => false]); }` then `$list->fill($data)->save();` (same for `Warehouse`). The seeder's `updateOrCreate` of the single PRINCIPAL warehouse is unaffected.

- [ ] **Step 3: Items page** — `PriceListController::items` attribute becomes `#[Middleware('permission:catalog.price-lists.view')]` and the render adds `'canEdit' => $request->user()->can('catalog.price-lists.update')` (add `Request $request` as first parameter). `updateItems` keeps `.update`. In `items.tsx`: read `canEdit` from props; when `!canEdit` render the price as plain text (`formatNumber(price, 4)` or '—'), hide the submit button; when editing, submit **only changed rows**: keep `initial = useRef(products.map(p => p.price ?? ''))` and on submit `put(updateItems.url(list.id), { data: { items: data.items.filter((item, i) => item.price !== initial.current[i]) } })` — if the `put` helper does not accept `data`, call `router.put(updateItems.url(list.id), { items: changed })` instead. In `index.tsx` show the "Precios" link when `can('catalog.price-lists.view')`.

- [ ] **Step 4: Bulk validation and preload**
`UpdatePriceListItemsRequest::rules()` → `'items' => ['present', 'array', 'max:5000'], 'items.*.product_id' => ['required', 'integer'], 'items.*.price' => [...as Task 3...]` and add:
```php
public function withValidator(\Illuminate\Validation\Validator $validator): void
{
    $validator->after(function (\Illuminate\Validation\Validator $v): void {
        $items = $this->input('items', []);
        if (! is_array($items)) {
            return;
        }
        $ids = array_values(array_filter(array_map(fn ($i) => isset($i['product_id']) ? (int) $i['product_id'] : null, $items)));
        $known = \App\Models\Product::query()->whereIn('id', array_unique($ids))->pluck('id')->flip();
        $seen = [];
        foreach ($items as $index => $item) {
            $id = (int) ($item['product_id'] ?? 0);
            if (! isset($known[$id])) {
                $v->errors()->add("items.$index.product_id", 'El producto no existe.');
            } elseif (isset($seen[$id])) {
                $v->errors()->add("items.$index.product_id", 'Producto repetido en la lista.');
            }
            $seen[$id] = true;
        }
    });
}
```
`SavePriceListItems::handle`: before the loop `$existingItems = $list->items()->get()->keyBy('product_id');` and inside use `$existing = $existingItems->get($item['product_id']);` instead of the per-row query.

- [ ] **Step 5: Inactive current values on edit** — `ClientController::formOptions(Request $request, ?Client $client = null)`: `'salespeople' => Salesperson::query()->where(fn (Builder $q) => $q->where('is_active', true)->orWhereKey($client?->salesperson_id))->orderBy('name')->get(['id', 'name'])` and the same for `priceLists` (`orWhereKey($client?->price_list_id)`) and `currencies` (`orWhereKey($client?->currency_id)`); `edit()` passes `$client`. `SupplierController::formOptions(Request $request, ?Supplier $supplier = null)`: `currencies` with `orWhereKey($supplier?->currency_id)`; `edit()` passes `$supplier`. (`orWhereKey(null)` matches nothing, as in `ProductController`.)

- [ ] **Step 6: Migrate, gate** — `php artisan migrate --no-interaction 2>&1 | tail -1 && php artisan wayfinder:generate --with-form --no-interaction >/dev/null && vendor/bin/pint --dirty && npm run check && composer test 2>&1 | tail -8` → passed (330 tests).
- [ ] **Step 7: Commit** — `fix(catalog): read-only price list items for viewers, bulk validation, single-default indexes, inactive options kept`

---

### Task 6: Importers — rule parity, leading zeros, confirm-time uniqueness race, dead rule

**Files:**
- Modify: `app/Imports/ClientsImporter.php`, `app/Imports/SuppliersImporter.php`, `app/Services/DataImport/ImportService.php`, `tests/Feature/DataImport/ClientsImporterTest.php`, `tests/Feature/DataImport/SuppliersImporterTest.php`

- [ ] **Step 1: Failing tests** — append to `tests/Feature/DataImport/ClientsImporterTest.php`:
```php
test('importer enforces juridica with rnc and only active price lists and salespeople', function () {
    PriceList::factory()->create(['name' => 'Inactiva', 'is_active' => false]);
    PriceList::factory()->create(['name' => 'Activa', 'is_active' => true]);
    Salesperson::factory()->create(['code' => 'VEN-OFF', 'is_active' => false]);
    actingAsRole('accounting');
    $file = makeSpreadsheet([
        ['Razón social', 'Tipo ID', 'RNC/Cédula', 'Tipo contribuyente', 'Moneda', 'Lista de precios', 'Vendedor (código)'],
        ['Jurídica con cédula', 'Cédula', '00113918205', 'Persona jurídica', 'DOP', '', ''],
        ['Lista inactiva', 'RNC', '1-31-24679-6', 'Persona jurídica', 'DOP', 'Inactiva', ''],
        ['Vendedor inactivo', 'RNC', '1-01-85004-3', 'Persona jurídica', 'DOP', 'Activa', 'VEN-OFF'],
    ]);
    $this->post(route('admin.imports.preview', 'clients'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->rows()->where('row_number', 2)->first()->errors['tax_id_type'])->toBe('Una persona jurídica debe identificarse con RNC.')
        ->and($batch->rows()->where('row_number', 3)->first()->errors)->toHaveKey('price_list_name')
        ->and($batch->rows()->where('row_number', 4)->first()->errors)->toHaveKey('salesperson_code');
});

test('numeric cells lose leading zeros and are padded back', function () {
    actingAsRole('accounting');
    $file = makeSpreadsheet([
        ['Razón social', 'Tipo ID', 'RNC/Cédula', 'Tipo contribuyente', 'Moneda'],
        ['Cédula numérica', 'Cédula', 113918205, 'Persona física', 'DOP'],
        ['RNC numérico', 'RNC', 1850043, 'Persona jurídica', 'DOP'],
    ]);
    $this->post(route('admin.imports.preview', 'clients'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->rows()->where('row_number', 2)->first()->is_valid)->toBeTrue()
        ->and($batch->rows()->where('row_number', 2)->first()->data['tax_id'])->toBe('00113918205')
        ->and($batch->rows()->where('row_number', 3)->first()->data['tax_id'])->toBe('001850043');
});

test('a unique key taken between preview and confirm yields a spanish 422 naming the row', function () {
    actingAsRole('accounting');
    $this->post(route('admin.imports.preview', 'clients'), ['file' => clientsFile()])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    Client::factory()->create(['tax_id' => '131246796']); // row 2's RNC, created after the preview
    $this->post(route('admin.imports.confirm', $batch))->assertStatus(422)->assertSee('La fila 2 no se pudo importar');
    expect($batch->fresh()->status->value)->toBe('previewed')->and(Client::where('business_name', 'Actualizada SRL')->exists())->toBeFalse();
});
```
Append to `tests/Feature/DataImport/SuppliersImporterTest.php`:
```php
test('numeric local tax ids are padded to 9 or 11 digits', function () {
    actingAsRole('accounting');
    $file = makeSpreadsheet([['Nombre', 'País', 'RNC', 'Moneda'], ['Con RNC', 'DO', 1850043, 'DOP'], ['Con cédula', 'DO', 113918205, 'DOP']]);
    $this->post(route('admin.imports.preview', 'suppliers'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->rows()->where('row_number', 2)->first()->data['tax_id'])->toBe('001850043')
        ->and($batch->rows()->where('row_number', 3)->first()->data['tax_id'])->toBe('00113918205')
        ->and($batch->valid_rows)->toBe(2);
});
```
Delete the test `a code match never rewrites the existing client tax id` from `ClientsImporterTest.php` (its assertion is the `code.unique` path already covered by `codes taken by another client…`; the mismatch rule it was written for is unreachable and is removed below). Add the missing imports (`App\Models\PriceList`, `App\Models\Salesperson`). Run the two files → `"result":"failed"`.

- [ ] **Step 2: `ClientsImporter`**
  - `normalize()`: replace the `tax_id` line with
```php
$rawTaxId = $row['tax_id'] ?? null;
$digits = $rawTaxId === null ? '' : FiscalId::normalize((string) $rawTaxId);
if ($digits !== '' && is_numeric($rawTaxId)) { // a numeric cell lost its leading zeros
    $digits = str_pad($digits, $row['tax_id_type'] === 'cedula' ? 11 : 9, '0', STR_PAD_LEFT);
}
$row['tax_id'] = $row['tax_id_type'] === 'none' || $digits === '' ? null : $digits;
```
  - `rules()`: delete the block `if ($existing instanceof Client && filled($existing->tax_id) && …) { $taxIdRules[] = … }` (dead: a tax-id match is looked up first, so a code match with a different tax id never reaches it); change `'salesperson_code' => ['nullable', 'string', Rule::exists('salespeople', 'code')->where('is_active', true)]` and `'price_list_name' => ['nullable', 'string', Rule::exists('price_lists', 'name')->where('is_active', true)]`; add the juridica rule as a closure on `tax_id_type`: `'tax_id_type' => ['required', Rule::enum(TaxIdType::class), function (string $attribute, mixed $value, \Closure $fail) use ($row): void { if (($row['taxpayer_type'] ?? null) === 'juridica' && $value !== 'rnc') { $fail('Una persona jurídica debe identificarse con RNC.'); } }]`.
  - `messages()`: add `'salesperson_code.exists' => 'El vendedor no existe o está inactivo.', 'price_list_name.exists' => 'La lista de precios no existe o está inactiva.'`.
- [ ] **Step 3: `SuppliersImporter::normalize()`** — replace the `tax_id` line with
```php
$rawTaxId = $row['tax_id'] ?? null;
$digits = $rawTaxId === null ? '' : FiscalId::normalize((string) $rawTaxId);
if ($digits !== '' && is_numeric($rawTaxId) && strlen($digits) < 11 && strlen($digits) !== 9) {
    $digits = str_pad($digits, strlen($digits) < 9 ? 9 : 11, '0', STR_PAD_LEFT);
}
$row['tax_id'] = $digits === '' ? null : $digits;
```
- [ ] **Step 4: `ImportService::confirm()`** — inside the `chunkById` callback wrap the persist call:
```php
try {
    $importer->persist($row->data, $existing, $meta);
} catch (QueryException $e) {
    if ($e->getCode() === '23505') {
        throw new UnprocessableEntityHttpException("La fila {$row->row_number} no se pudo importar: el código, SKU o RNC/cédula ya existe (fue registrado después de la vista previa). Genera la vista previa de nuevo.");
    }
    throw $e;
}
```
(import `Illuminate\Database\QueryException`; the exception propagates out of `DB::transaction`, which rolls everything back, and Laravel renders it as a 422 with the message). Verify the batch stays `previewed`.

- [ ] **Step 5: Gate** → passed (334 tests).
- [ ] **Step 6: Commit** — `fix(import): rule parity with forms, leading-zero tax ids, confirm-time uniqueness race`

---

### Task 7: Export permissions for accounts and activity log; exact degree-liter rounding

**Files:**
- Modify: `app/Support/PermissionCatalog.php`, `app/Http/Controllers/Finance/FinancialAccountController.php`, `app/Http/Controllers/Admin/ActivityLogController.php`, `resources/js/pages/finance/accounts/index.tsx`, `resources/js/pages/admin/activity-log/index.tsx`, `app/Services/Catalog/AdditionalTaxCalculator.php`, `tests/Feature/Admin/PermissionCatalogTest.php`, `tests/Feature/Finance/FinancialAccountTest.php`, `tests/Feature/Admin/ActivityLogViewerTest.php`, `tests/Feature/Catalog/AdditionalTaxCalculatorTest.php`

- [ ] **Step 1: Failing tests** — append:

`tests/Feature/Admin/PermissionCatalogTest.php` (inside the first test's expectation chain): `->and(Role::findByName('accounting')->hasPermissionTo('finance.accounts.export'))->toBeTrue()->and(Role::findByName('accounting')->hasPermissionTo('admin.activity-log.export'))->toBeFalse()->and(Role::findByName('owner')->hasPermissionTo('admin.activity-log.export'))->toBeTrue()`.
`tests/Feature/Finance/FinancialAccountTest.php`: in the `crudResourceTests` config nothing changes (accounting has export); add:
```php
test('export requires the accounts export permission', function () {
    $user = actingAsRole('accounting');
    $user->revokePermissionTo('finance.accounts.export');
    $this->get(route('finance.accounts.export', ['format' => 'csv']))->assertForbidden();
});
```
`tests/Feature/Admin/ActivityLogViewerTest.php`: change `export downloads csv and is itself logged` to act as `owner` (it already does) and add:
```php
test('accounting can view the activity log but cannot export it', function () {
    actingAsRole('accounting');
    $this->get(route('admin.activity-log.index'))->assertOk();
    $this->get(route('admin.activity-log.export', ['format' => 'csv']))->assertForbidden();
});
```
`tests/Feature/Catalog/AdditionalTaxCalculatorTest.php`:
```php
test('degree liter tax multiplies before dividing and stays exact', function () {
    $tax = new AdditionalTax(['calculation_type' => 'fixed_per_degree_liter', 'amount' => '0.0003']);
    expect(app(AdditionalTaxCalculator::class)->forLine($tax, '3', '0', '0.3333', '33.33'))->toBe('0.00')   // 3 × 0.3333 × 33.33 / 100 × 0.0003 = 0.0000999… → 0.00
        ->and(app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'fixed_per_degree_liter', 'amount' => '600']), '2', '0', '0.75', '40'))->toBe('360.00');
});
```

- [ ] **Step 2: Permissions** — in `PermissionCatalog::permissions()` add after `finance.accounts.manage`: `$add('finance.accounts.export', 'Exportar cuentas bancarias y cajas', array_values(array_intersect($all, self::EXPORTERS)));` and after `admin.activity-log.view`: `$add('admin.activity-log.export', 'Exportar bitácora', ['owner']);`. `FinancialAccountController::export` → `#[Middleware('permission:finance.accounts.export')]`; `ActivityLogController::export` → `#[Middleware('permission:admin.activity-log.export')]`. In `finance/accounts/index.tsx` guard the `ExportMenu` with `can('finance.accounts.export')`; in `admin/activity-log/index.tsx` with `can('admin.activity-log.export')`. Permission total becomes 72 (`php artisan db:seed` on the dev DB adds the two rows).

- [ ] **Step 3: Calculator** — in `AdditionalTaxCalculator::forLine`, `FixedPerDegreeLiter` branch becomes:
```php
AdditionalTaxCalculationType::FixedPerDegreeLiter => Decimal::money(
    Decimal::of($qty)
        ->multipliedBy(Decimal::of($this->requireVolume($volumeLiters)))
        ->multipliedBy(Decimal::of($this->requireDegree($alcoholDegree)))
        ->multipliedBy(Decimal::of((string) $tax->amount))
        ->dividedBy(100, 10, RoundingMode::HalfUp)
),
```

- [ ] **Step 4: Seed, gate** — `php artisan db:seed --class=PermissionSeeder --no-interaction && docker compose exec -T db psql -U gomez -d gomez -tAc "SELECT count(*) FROM permissions"` → `72`; `vendor/bin/pint --dirty && npm run check && composer test 2>&1 | tail -8` → passed (338 tests).
- [ ] **Step 5: Commit** — `fix(admin): export permissions for accounts and activity log; exact degree-liter rounding`

---

## After the last task

Report to the coordinator: `git log --oneline 9486339..HEAD` (7 commits), the final phpunit line, and `SELECT count(*) FROM permissions` (72). The coordinator runs the scoped re-review and merges `phase-1-base` into `main` with `git merge --no-ff`.
