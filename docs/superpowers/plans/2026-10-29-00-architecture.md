# Gomez ERP — Architecture and Cross-Phase Conventions

> **For agentic workers:** This document is not a task list. Every phase plan (`2026-10-29-0N-faseN-*.md`) inherits this file. An executor reads this file's §1–§11 before any task of any phase. Where a phase plan and this file disagree, the phase plan wins for that phase and must say so explicitly.

**Spec:** `/Users/shalemperez/www/gomezintsystem/docs/superpowers/specs/2026-10-29-gomez-erp-design.md` (Spanish, approved, source of truth).

**Versions checked:** 2026-10-29 against Packagist (`composer show -a`), npm (`npm view`), laravel.com/docs/13.x, GitHub sources of `laravel/react-starter-kit@main`, `laravel/installer@master`, `laravel/boost@main`, `laravel/socialite@5.x`, `spatie/laravel-activitylog@main`, `SpartnerNL/Laravel-Excel@4.x`.

---

## 1. Stack and pinned packages

### 1.1 Runtime (verified on this machine)

| Tool | Version | Notes |
|---|---|---|
| macOS | Darwin 27 | dev machine |
| PHP | 8.4.27 (Laravel Herd) | `php` on PATH is Herd's `php84` |
| Composer | 2.10.2 | |
| Node / npm | 22.23.3 / 10.9.9 | |
| Laravel installer | 5.31.1 (Herd bundled) | NOT used for scaffolding (see Phase 1 Task 1); `composer create-project` is used instead |
| Herd | parks `/Users/shalemperez/www/` with TLD `test` → app URL is `http://gomezintsystem.test` | Herd **Pro is not installed** → Herd services (Postgres) unavailable |
| Docker / Compose | docker available, Compose v5.5.1 | PostgreSQL runs in Docker Compose |
| `psql` | not on PATH | use `docker compose exec db psql ...` |

### 1.2 Composer packages (exact constraints to write in `composer.json`)

Laravel 13 requires PHP ≥ 8.3; `spatie/laravel-activitylog` 5 and Pest 5 require PHP ≥ 8.4. Production server must run **PHP 8.4**.

| Package | Constraint | Latest at check | Phase installed | Why |
|---|---|---|---|---|
| `laravel/framework` | `^13.17` (kit) → verified resolves `v13.39.0` | 13.39.0 | 1 | framework |
| `laravel/react-starter-kit` | `dev-main` via `composer create-project ... --stability=dev` (tagged `v1.0.1` targets Laravel 12 — do not use it) | — | 1 | Inertia 3 + React 19 + TS + Tailwind 4 + shadcn (new-york) + Wayfinder + vite-plus |
| `inertiajs/inertia-laravel` | `^3.0` (kit) | 3.5.2 | 1 | Inertia |
| `laravel/wayfinder` | `^0.1.14` (kit) | — | 1 | typed TS route helpers (`@/routes/...`) |
| `laravel/fortify` | removed in Phase 1 Task 4 | — | — | Google-only login; Fortify's password routes must not exist |
| `laravel/chisel` | kit dependency; its script self-deletes after `install:features` | — | 1 | — |
| `laravel/boost` | `^2.10` (dev) | 2.10.3 | 1 | MCP + guidelines + skills |
| `laravel/socialite` | `^5.31` | 5.31.0 | 1 | Google OAuth |
| `spatie/laravel-permission` | `^8.3` | 8.3.2 | 1 | roles/permissions |
| `spatie/laravel-activitylog` | `^5.1` | 5.1.3 | 1 | audit log (`activity_log` table, `attribute_changes` column) |
| `maatwebsite/excel` | `^4.0` | 4.0.6 (PhpSpreadsheet ^5.9) | 1 | xlsx/csv import+export. v4 API: exports implement `Maatwebsite\Excel\Concerns\Export` (via `FromQuery`/`FromCollection`/`FromArray`), imports implement `Import` (via `ToCollection`/`ToModel`); `Excel::download(Export $e, string $file, ?string $writerType)`; `Excel::toCollection(?Import $i, UploadedFile|string $path)`; constants `\Maatwebsite\Excel\Excel::XLSX`, `::CSV` |
| `brick/money` | `^0.15` | 0.15.2 | 1 | exact decimal money (brick/math 0.20.0 comes with the framework) |
| `barryvdh/laravel-dompdf` | `^3.1` | 3.1.2 | 2 | PDFs (proformas, facturas, conduces). Chosen over `spatie/laravel-pdf` to avoid a headless Chrome on the server |
| `robrichards/xmlseclibs` | `^4.0` | 4.0.1 | 3 | XMLDSig signing for e-CF |
| `google/apiclient` | `^2.20` | 2.20.1 | 7 | Gmail API (send-as-user, domain-wide delegation reads) |
| `pestphp/pest` | `^5.3` (dev) | 5.3.4 | 1 | tests |
| `pestphp/pest-plugin-laravel` | `^5.0` (dev) | 5.0.1 | 1 | `actingAs`, etc. |
| `larastan/larastan` | `^3.9` (kit, dev) | 3.13.2 | 1 | static analysis (`phpstan.neon` from the kit) |
| `laravel/pint` | `^1.27` (kit, dev) | 1.32.1 | 1 | formatting (`pint.json` from the kit) |

Not used (decided): `lab404/laravel-impersonate` (impersonation is ~60 lines, written in-house with audit), `spatie/laravel-settings` (own `settings` table), `laravel-lang/*` (publisher does not declare Laravel 13 support; the Spanish validation file is written by hand in Phase 1), `@tanstack/react-table` (v9 API churn; the ERP `DataTable` is server-driven and written in-house).

### 1.3 npm packages (from the kit, versions resolved by the kit's `package.json`)

React `^19.2`, `@inertiajs/react ^3.0` (3.9.1 latest), `@inertiajs/vite ^3.0`, Tailwind `^4.0`, `vite` = `vite-plus` 1.4.0 (`vp` binary: `npm run dev|build|check|check:fix|types:check`), `lucide-react`, `sonner` (toasts), `class-variance-authority`, `clsx`, `tailwind-merge`, `@laravel/vite-plugin-wayfinder`, React Compiler babel preset. Additional ERP components come from the shadcn CLI pinned at **`shadcn@4.23.0`** (`npx --yes shadcn@4.23.0 add ...`). No other npm dependencies are added unless a phase plan names them.

### 1.4 Database

PostgreSQL **17** (`postgres:17` image, verified pullable) via `docker-compose.yml` in the repo root. Two databases on the same server: `gomez` (dev) and `gomez_test` (tests). Credentials in `.env` only; `.env.example` carries placeholders.

---

## 2. Repository and module layout

Code, identifiers, table/column names, routes and commit messages are **English**. All user-visible text (UI labels, buttons, flash/validation messages, emails, PDFs) is **Spanish (es-DO)**.

```
/Users/shalemperez/www/gomezintsystem
├── app/
│   ├── Actions/<Area>/<Verb><Noun>.php      # single-purpose classes, public function handle(...): T
│   ├── Contracts/                            # interfaces (ExchangeRateSource, EcfProvider in phase 3, ...)
│   ├── Enums/                                # string-backed PHP enums, each with label(): string in Spanish
│   ├── Exports/                              # maatwebsite/excel exports
│   ├── Http/Controllers/<Area>/              # Area ∈ Auth, Admin, Catalog, Finance, Inventory, Sales, Purchases, Accounting, Commissions, Crm, Reports, DataImport
│   ├── Http/Middleware/
│   ├── Http/Requests/<Area>/                 # FormRequests: Store<Model>Request, Update<Model>Request
│   ├── Imports/                              # importer classes (one per resource) + templates
│   ├── Jobs/
│   ├── Models/                               # flat, one class per table (Boost/Wayfinder expect this)
│   ├── Models/Concerns/                      # model traits (LogsModelActivity, ...)
│   ├── Notifications/
│   ├── Policies/                             # one policy per model exposed in UI
│   ├── Rules/                                # validation rules
│   ├── Services/<Area>/                      # stateful services (ExchangeRateService, FiscalNumberingService phase 2, ...)
│   └── Support/                              # pure helpers (Money/Decimal, FiscalId, PermissionCatalog, ...)
├── config/erp.php                            # all app-specific config (env-backed)
├── database/migrations/                      # one migration per table, timestamped; never edit a migration after it is committed on main — add a new one
├── database/seeders/                         # idempotent (firstOrCreate/updateOrCreate) — safe to re-run in production
├── docker-compose.yml
├── docs/superpowers/{specs,plans}/
├── lang/es/{validation,auth,pagination,passwords}.php  lang/es.json
├── resources/js/
│   ├── actions/, routes/, wayfinder/         # generated by Wayfinder (gitignored)
│   ├── components/ui/                        # shadcn (never edited by hand)
│   ├── components/erp/                       # shared ERP components (data-table, page-header, form-field, ...)
│   ├── components/                           # kit components (app-sidebar, nav-main, ...)
│   ├── hooks/
│   ├── layouts/
│   ├── lib/                                  # utils.ts, format.ts (number/date formatting es-DO), can.ts
│   ├── pages/<area>/<resource>/{index,create,edit,show}.tsx   # kebab-case folders and files
│   └── types/                                # TS types: one file per area (catalog.ts, admin.ts, ...)
├── routes/web.php                            # requires routes/<area>.php files
├── routes/<area>.php
└── tests/{Feature,Unit}/<Area>/...           # Pest
```

Naming rules:
- Models singular PascalCase (`PriceListItem`), tables plural snake_case (`price_list_items`), pivot tables singular_singular alphabetical (`additional_tax_product`).
- FKs `<singular>_id`; booleans `is_*`/`has_*`/`tracks_*`; timestamps `*_at`; dates `*_date` or `*_on`.
- Route names `<area>.<resource>.<action>` (`catalog.clients.index`); URL paths `/<area>/<resource>` in English (`/catalog/clients`). Wayfinder exports named routes under `@/routes/<area>/<resource>` (e.g. `import { index, store } from '@/routes/catalog/clients'`).
- Controllers: resource controllers with the standard seven methods plus `export` where the plan says so. No business logic in controllers: validate in a FormRequest, call an Action, return an Inertia response or redirect with flash.
- Enums: `App\Enums\<Name>` string-backed, `cases()` fixed by the plan, `public function label(): string` returns Spanish. Stored in `string` columns (max 32) unless the plan says otherwise.
- React pages: default export `function <Resource><Page>()`; page props typed (`type Props = {...}`) from `@/types/<area>`.
- Spanish copy: sentence case, no trailing period on labels/buttons, accents mandatory ("Añadir", "Configuración"). Use the exact strings the plan gives.

---

## 3. Database conventions (PostgreSQL)

- All money/quantity columns are `decimal`, never `float`/`double`/`real`:
  - Amounts (totals, prices of lines, balances, credit limits): `decimal(14, 2)`.
  - Quantities and unit prices/unit costs: `decimal(14, 4)`.
  - Exchange rates: `decimal(12, 6)`.
  - Percentages/rates (ITBIS %, discount %, commission %, ISC ad valorem %): `decimal(7, 4)` storing the percent value (18.0000 = 18 %).
  - Physical measures (weight kg, volume liters, alcohol degrees): `decimal(10, 4)`.
- Eloquent casts for decimals: `'price' => 'decimal:4'`, `'total' => 'decimal:2'`, `'rate' => 'decimal:6'`. These yield **strings**; PHP code must never `(float)` them — pass them to `App\Support\Money\Decimal` (§4).
- Datetimes: `$table->timestamps()` / `timestamp()` with `APP_TIMEZONE=America/Santo_Domingo` (DR has no DST). Dates: `date`.
- Primary keys: `$table->id()` (bigint). No UUIDs.
- Every FK: `$table->foreignId('x_id')->constrained()` plus explicit `->restrictOnDelete()` (default for master data referenced by documents) or `->cascadeOnDelete()` (only for child rows owned by a parent: contacts, lines, items) or `->nullOnDelete()` (optional references). The plan states it per column.
- `is_active boolean default true` on every master-data table instead of soft deletes. Deletion of master data is allowed only while nothing references it (FK restrict → controller catches `QueryException` SQLSTATE 23503 and flashes "No se puede eliminar: el registro está en uso").
- Unique business keys get a unique index (`sku`, `code`, `email`, `tax_id`, `(currency_id, rate_date)`, ...).
- JSON columns use `jsonb` (`$table->jsonb('...')`).
- Sequence/numbering tables (Phase 2+) are updated inside `DB::transaction` with `lockForUpdate()`.

---

## 4. Money, tax and rounding helpers

`App\Support\Money\Decimal` wraps `Brick\Math\BigDecimal`. Rules every phase obeys:

- Rounding mode everywhere: **HALF_UP** (`Brick\Math\RoundingMode::HALF_UP`).
- Line computation order (invoices, proformas, notes, purchase orders):
  1. `line_subtotal = round(qty × unit_price, 2)`
  2. `discount_amount = round(line_subtotal × discount_percent / 100, 2)`
  3. `taxable_base = line_subtotal − discount_amount`
  4. Additional taxes (ISC etc.) per line: `percent_of_base` → `round(taxable_base × rate / 100, 2)`; `fixed_per_unit` → `round(qty × amount, 2)`; `fixed_per_liter` → `round(qty × volume_liters × amount, 2)`; `fixed_per_degree_liter` → `round(qty × volume_liters × alcohol_degree / 100 × amount, 2)`.
  5. `itbis_base = taxable_base + Σ additional taxes flagged `included_in_itbis_base` (ISC ad valorem is part of the ITBIS base in DR practice; the flag lives on the tax row so Contabilidad can set it).
  6. `itbis_amount = round(itbis_base × itbis_rate / 100, 2)`
  7. `line_total = taxable_base + Σ additional taxes + itbis_amount`
  Document totals are **sums of the rounded line values** (never re-rounded from unrounded sums).
- Currency conversion: `amount_dop = round(amount_foreign × rate, 2)` with the rate of the document date (fallback: most recent earlier rate; if none → validation error "No hay tasa de cambio registrada para la fecha").
- API of the helper (Phase 1 Task 11 implements and tests it):
  ```php
  final class Decimal {
      public static function of(string|int|BigDecimal $v): BigDecimal;           // never accepts float
      public static function money(string|int|BigDecimal $v): string;            // scale 2, HALF_UP, returns "1234.50"
      public static function qty(string|int|BigDecimal $v): string;              // scale 4
      public static function rate(string|int|BigDecimal $v): string;             // scale 6
      public static function mul(string|BigDecimal $a, string|BigDecimal $b, int $scale): string; // HALF_UP
      public static function div(string|BigDecimal $a, string|BigDecimal $b, int $scale): string; // HALF_UP
      public static function percent(string|BigDecimal $base, string|BigDecimal $pct, int $scale = 2): string; // base*pct/100
      public static function sum(iterable $values, int $scale = 2): string;
      public static function isZero(string|BigDecimal $v): bool;
      public static function cmp(string|BigDecimal $a, string|BigDecimal $b): int;
  }
  ```
- Frontend never computes money; it displays what the server sends. Display formatting: `resources/js/lib/format.ts` → `formatMoney(value: string, currency: 'DOP'|'USD')` using `Intl.NumberFormat('es-DO', {style:'currency', currency})` and `formatNumber(value, decimals)`; dates `formatDate(iso)` → `dd/MM/yyyy`.

---

## 5. Roles and permissions (all phases)

Package: spatie/laravel-permission, guard `web`, no teams. Roles are fixed; permissions are seeded from `App\Support\PermissionCatalog` (idempotent) and assigned to roles by the catalog's defaults; admins can later edit the role→permission matrix (except `super-admin`, which is locked and bypasses checks via `Gate::before`).

### 5.1 Roles (stored name → Spanish label)

| name | label | summary (spec §2) |
|---|---|---|
| `super-admin` | Super Admin | developer/support. All permissions implicitly (`Gate::before`). Only a super-admin can create/edit/deactivate another super-admin or assign/remove the `super-admin` role. Can impersonate. |
| `owner` | Dueña | everything business + configuration; no `admin.system.*`, no `admin.users.impersonate`, cannot manage super-admins |
| `management` | Gerencia | everything except `admin.*` |
| `sales` | Ventas | own clients/proformas/invoices/commission; no costs, margins, accounting |
| `accounting` | Contabilidad | accounting, invoicing, collections, payments, purchases, fiscal, reports; no `admin.users.*` |
| `warehouse` | Almacén | receipts, dispatches, inventory, transfers; no costs, no accounting |

A user may hold several roles; effective permissions are the union.

### 5.2 Permission naming scheme

`<module>.<resource>.<action>` — all lowercase, kebab-case segments. Actions vocabulary: `view`, `view-all` (see rows of other salespeople), `view-costs`, `create`, `update`, `delete`, `export`, `import`, `manage` (full control on configuration-type resources), plus document verbs (`approve`, `issue`, `cancel`, `send`, `post`, `pay`, `reconcile`, `liquidate`, `confirm`).

### 5.3 Full catalog (phase that seeds it in parentheses)

**admin (1):** `admin.users.view`, `admin.users.create`, `admin.users.update`, `admin.users.deactivate`, `admin.users.impersonate`, `admin.roles.view`, `admin.roles.update`, `admin.settings.view`, `admin.settings.manage`, `admin.activity-log.view`, `admin.system.manage` (integrations, certificates, queues, error logs, fiscal mode switch; super-admin only — never granted to another role by default).

**catalog (1):** for each resource `clients`, `suppliers`, `brands`, `categories`, `units`, `products`, `price-lists`, `salespeople`, `additional-taxes`: `catalog.<resource>.view`, `.create`, `.update`, `.delete`, `.export`; `.import` for `clients`, `suppliers`, `products`, `price-lists`; extra: `catalog.clients.view-all`, `catalog.products.view-costs`, `catalog.tax-ids.override-validation` (save a client/supplier whose RNC/cédula fails the checksum, with a mandatory reason; owner only by default).

**finance (1):** `finance.currencies.manage`, `finance.exchange-rates.view`, `finance.exchange-rates.manage`, `finance.accounts.view`, `finance.accounts.manage` (bank accounts & cash boxes).

**inventory (1 for warehouses; rest 2/4):** `inventory.warehouses.view`, `inventory.warehouses.manage`, `inventory.stock.view`, `inventory.stock.view-costs`, `inventory.movements.view`, `inventory.receipts.create`, `inventory.dispatches.create`, `inventory.dispatches.confirm`, `inventory.adjustments.create`, `inventory.transfers.create`, `inventory.counts.manage`, `inventory.lots.view`, `inventory.initial-stock.import`.

**sales (2):** `sales.proformas.view`, `.view-all`, `.create`, `.update`, `.approve`, `.delete`, `.send`; `sales.invoices.view`, `.view-all`, `.create`, `.issue`, `.cancel`, `.send`, `.credit-override`; `sales.credit-notes.create`, `sales.debit-notes.create`; `sales.receipts.view`, `.create`, `.cancel`; `sales.statements.view`, `.send`; `sales.historical-invoices.import`.

**fiscal (2/3):** `fiscal.sequences.view`, `fiscal.sequences.manage`, `fiscal.mode.manage`, `fiscal.ecf.view`, `fiscal.ecf.resend`, `fiscal.ecf.certify`, `fiscal.received-ecf.view`, `fiscal.received-ecf.manage`.

**purchases (4):** `purchases.orders.view`, `.create`, `.update`, `.approve`, `.send`; `purchases.shipments.view`, `.manage`, `.liquidate`; `purchases.supplier-invoices.view`, `.create`, `.pay`; `purchases.expenses.view`, `.create`; `purchases.payables.view`.

**accounting (5):** `accounting.chart.view`, `.manage`; `accounting.entries.view`, `.create`, `.post`; `accounting.periods.manage`; `accounting.banks.reconcile`; `accounting.reports.view`; `accounting.fiscal-reports.generate`; `accounting.opening-balances.manage`; `accounting.mappings.manage`.

**commissions (6):** `commissions.rules.manage`, `commissions.settlements.view`, `.approve`, `.pay`, `commissions.own.view`.

**crm (7):** `crm.client-360.view`, `crm.emails.view`, `crm.emails.send`, `crm.gmail.manage`, `crm.notes.manage`.

**reports (8):** `reports.dashboard.view`, `reports.sales.view`, `reports.margins.view`, `reports.receivables.view`, `reports.payables.view`, `reports.inventory.view`, `reports.commissions.view`.

### 5.4 Default role grants

The catalog stores, per permission, the list of roles that receive it by default. Rules used to derive the Phase 1 defaults (later phases follow the same logic):
- `owner`: all permissions except `admin.system.manage`, `admin.users.impersonate` (includes `catalog.tax-ids.override-validation`, which no other role gets by default).
- `management`: all except `admin.*`.
- `accounting`: all `catalog.*` (incl. `view-costs`, `view-all`), all `finance.*`, `inventory.warehouses.view`, `inventory.stock.view`, `inventory.stock.view-costs`, `admin.activity-log.view`, `admin.settings.view`.
- `sales`: `catalog.clients.view/create/update` (own clients only — no `view-all`), `catalog.products.view`, `catalog.brands.view`, `catalog.categories.view`, `catalog.price-lists.view`, `catalog.salespeople.view`, `finance.exchange-rates.view`, `inventory.stock.view`.
- `warehouse`: `catalog.products.view`, `catalog.brands.view`, `catalog.categories.view`, `catalog.units.view`, `inventory.warehouses.view`, `inventory.stock.view`, `inventory.movements.view`, `inventory.receipts.create`, `inventory.dispatches.create`, `inventory.dispatches.confirm`, `inventory.transfers.create`, `inventory.lots.view`.
- `super-admin`: no explicit grants needed (`Gate::before`), but the UI shows all.

### 5.5 Enforcement

- `Gate::before(fn (User $u) => $u->hasRole('super-admin') ? true : null)` in `AppServiceProvider::boot`.
- Routes use `->middleware('permission:<name>')` (spatie middleware aliases `role`, `permission`, `role_or_permission` registered in `bootstrap/app.php`) **and** policies for row-level rules (e.g. `ClientPolicy::view` checks `view-all` or `salesperson_id === auth user's salesperson`).
- Inertia shares `auth.user`, `auth.roles: string[]`, `auth.permissions: string[]`, `auth.impersonator: {id,name}|null`. Frontend uses `can('catalog.clients.create')` from `@/lib/can` only to hide UI; the server is the authority.
- Cost/margin fields are stripped from Inertia props server-side unless the user has the relevant `view-costs` permission (never relying on the frontend).

---

## 6. Audit — everything leaves a trace (cross-phase Global Constraint)

User requirement (approved amendment): *"debe quedar registro de todo, y debe haber auditoría"*. Every phase plan inherits §6 and must add, per task, tests asserting the audit rows for every model and action it introduces (§6.6).

### 6.1 What is logged (spatie/laravel-activitylog v5, table `activity_log`)
- **Every Eloquent model** — master data, documents, settings, pivot-backed models (`PriceListItem`, `ClientContact`, `SupplierContact`, …) — uses `App\Models\Concerns\LogsModelActivity`: `created`/`updated`/`deleted` with `attribute_changes.old` / `.attributes` (`logOnly(['*'])`, `logOnlyDirty()`, `dontLogEmptyChanges()`, log name = table name). An `is_active` change gets the explicit description `Registro activado` / `Registro desactivado`; any other state transition (document status, approval, issuance, cancellation, posting) is logged explicitly with `event('<snake_case>')` and a Spanish description naming the transition.
- **Pivot assignments without a model**: spatie role/permission changes are logged from spatie's events (`RoleAttachedEvent`, `RoleDetachedEvent`, `PermissionAttachedEvent`, `PermissionDetachedEvent`; `config('permission.events_enabled') = true`) as `role_assigned`, `role_removed`, `permission_granted`, `permission_revoked` in log `access`; product ↔ additional-tax sync is logged by `SaveProduct` as `additional_taxes_synced` (before/after codes).
- **Auth** (log `auth`): `login`, `login_rejected` (`properties.reason` ∈ `unverified`, `hd_missing`, `domain`, `not_invited`, `inactive`, `oauth_error`; `properties.email`), `logout`, `impersonation_started`, `impersonation_stopped`.
- **Exports** (log `exports`, event `exported`): `table` (base name), `format`, `filters` (query string array), `row_count` — written inside `TableExport::fromQuery`, so every export in the system (index tables, price-list items, activity log itself) is logged. **Templates**: log `imports`, event `template_downloaded` (`type`). **Imports**: `previewed` (file name, totals), `confirmed` (created/updated counts), `cancelled`.
- **Settings** (log `settings`, `updated`, `keys`; each `Setting` row also logs old/new), **exchange rates** (model events + `fetched` {count, source} + `fetch_failed` {message}), **role matrix** (`roles`, `permissions_updated` {added, removed}), **users** (`activated` / `deactivated` with description "Usuario X activado/desactivado"), **fiscal-id override** (`tax_id_validation_overridden` with reason).
- **Phase 2+**: fiscal documents log every status transition, fiscal numbering assignment, DGII submission and response, PDF generation and email sending; inventory movements log their source document; accounting entries log posting/unposting and period locks; commissions log accrual, approval and payment; Gmail sync logs each synced mailbox run.

### 6.2 Row context
`activity_log` carries extra columns (Phase 1 Task 7 migration): `impersonator_id` (FK `users`, nullable), `ip` (string 45), `user_agent` (string 500), `request_id` (string 36, UUID). They are filled automatically by the `Activity::creating` hook in `AppServiceProvider`: causer = authenticated user (the impersonated user while impersonating; the real person goes to `impersonator_id`), `ip`/`user_agent` from the request, `request_id` from `App\Http\Middleware\AssignRequestId` (UUID per request, also returned as response header `X-Request-Id`). Console/queue work stores `user_agent = 'console'`, `ip = null`, `request_id = null`.

### 6.3 Append-only
- No route updates or deletes audit rows; `config('activitylog.clean_after_days') = null`.
- Model guard: `config('activitylog.activity_model') = App\Models\ActivityLog` (extends spatie's `Activity`); its `updating`/`deleting` hooks throw `App\Exceptions\AppendOnlyAuditException` ("La bitácora de auditoría es de solo escritura").
- Database guard (chosen over `REVOKE` because the application connects with a single database role): PostgreSQL trigger `activity_log_append_only` — `BEFORE UPDATE OR DELETE ON activity_log FOR EACH ROW EXECUTE FUNCTION prevent_activity_log_mutation()` whose body is `RAISE EXCEPTION 'activity_log is append-only'`. Created by the Phase 1 Task 7 migration (and recreated by `migrate:fresh`); any attempt from any code path fails with SQLSTATE P0001.

### 6.4 Viewer and per-record history
Phase 1 Task 23 ships the viewer: filters by user, log name (model), subject type + id, event, date range and text; detail dialog with a field-by-field diff; xlsx/csv export (itself logged). Every master-data edit page has a **"Historial"** tab (`@/components/erp/record-history`, fed by `App\Support\AuditHistory::for(Model $m, int $limit = 100): array`), and later phases add it to every document page.

### 6.5 Immutability of fiscal documents (Phase 2+)
Invoices, credit/debit notes, receipts and purchase vouchers get `status` and `issued_at`. Once `issued_at` is set: `update`/`delete` on the model throw `App\Exceptions\ImmutableDocumentException` (enforced in the model's `updating`/`deleting` observers, allowing only the whitelisted columns `status`, `ecf_*`, `dgii_*`, `sent_at`, `paid_amount`, `balance`), and no UI route exists to edit them. Corrections are new documents (credit/debit notes). XML and DGII responses are stored as immutable files (`storage/app/private/ecf/<year>/<encf>/...`, never overwritten).

### 6.6 Tests
Every task that adds a model, pivot, action, export, import or auth path adds tests asserting the audit rows: log name, event, causer, old/new values, and (where the task says so) `ip`/`user_agent`/`request_id`/`impersonator_id`. Phase plans list them per task; a phase plan without audit tests for a new model is incomplete.

---

## 7. Queues, scheduler, mail, storage

- `QUEUE_CONNECTION=database` (kit default; `jobs`, `failed_jobs`, `job_batches` tables exist). Production: Forge runs `php artisan queue:work --queue=default --tries=3 --timeout=120` as a daemon. Dev: `php artisan queue:listen` (or `composer dev`, which runs `php artisan dev`).
- Scheduler in `routes/console.php` (`Schedule::job(...)`, `Schedule::command(...)`). Production cron: `* * * * * php artisan schedule:run`. All schedules use the app timezone.
- Jobs are classes in `app/Jobs`, `implements ShouldQueue`, with `#[Tries(3)]`, `#[Backoff([60, 300, 900])]` attributes (Laravel 13) unless the plan says otherwise; `failed(\Throwable $e)` sends a notification when the plan says so.
- Mail: dev `MAIL_MAILER=log`; prod SMTP (Forge). Transactional mail uses Notifications (`via ['mail','database']`). From Phase 7, document emails go out through the user's Gmail (Gmail API), not SMTP.
- Files: `FILESYSTEM_DISK=local` (`storage/app/private`); uploaded imports go to `imports/<batch id>/`, attachments to `attachments/<model>/<id>/`. Backups (Forge) cover `storage/app` and the DB.

---

## 8. Testing conventions

- Pest 5 with `pestphp/pest-plugin-laravel`. `tests/Pest.php` applies `Tests\TestCase` + `RefreshDatabase` to `tests/Feature`. Unit tests in `tests/Unit` have no DB.
- Tests run against **PostgreSQL** database `gomez_test` (same Docker server). `phpunit.xml` sets `DB_CONNECTION=pgsql`, `DB_DATABASE=gomez_test`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `APP_LOCALE=es`. Never sqlite (decimal and jsonb behaviour differ).
- Commands: `php artisan test` (all), `php artisan test --filter=<Name>` (one), `composer test` (= `config:clear` + Pint check + PHPStan + tests). A task's final verification is always `composer test` → expected last lines `Tests:    N passed (...)` and no `FAIL`.
- Each task's tests are named by the plan. Test files use `test('...', function () {...})` with Spanish-free, descriptive English names; helper `tests/Support/ActsAs.php` provides `actingAsRole(string $role): User` (seeds roles/permissions once per test via `PermissionSeeder`).
- Factories exist for every model (`database/factories`), with realistic Dominican data (`es_DO` is not a Faker locale; use `APP_FAKER_LOCALE=es_ES`).
- Frontend: `npm run types:check` (tsc) and `npm run check` (vite-plus lint/format) must pass; no component tests in Phase 1.

---

## 9. Laravel Boost — how executors must use it

Boost is installed in Phase 1 Task 3 (`composer require laravel/boost --dev`, `php artisan boost:install --no-interaction`), which writes `.mcp.json` (MCP server `laravel-boost` = `php artisan boost:mcp`), the `<laravel-boost-guidelines>` block in `CLAUDE.md`, and skills under `.claude/skills/`. `boost:update --no-discover` runs on `composer update` (post-update-cmd).

MCP tools available to executors (names as Boost registers them): `application-info`, `browser-logs`, `database-connections`, `database-query`, `database-schema`, `get-absolute-url`, `last-error`, `read-log-entries`, `record-rule`, `search-docs`. Executors use them as follows — these are mandatory steps, not suggestions:

1. **Before writing code that uses a framework/package API** the task does not spell out character by character → `search-docs` with the package name and method (e.g. `["socialite fake user", "inertia useForm"]`). Prefer the docs result over memory.
2. **After every `php artisan migrate`** → `database-schema` for the tables touched; compare column names/types to the task's table definition; stop and report any mismatch.
3. **After every failing HTTP test or 500** → `last-error` and `read-log-entries` before changing code.
4. **After frontend changes that affect a page** → `browser-logs` (when a browser session exists) to catch console errors.
5. **To verify data** (seeders, imports) → `database-query` with the exact SELECT the task gives.
6. Never call `record-rule` unless the task says so (rules are curated by the planner).
7. Follow the Boost guidelines block in `CLAUDE.md` and the `inertia-react-development`, `pest-testing`, `tailwindcss-development`, `wayfinder-development` skills when they do not conflict with the task; the task wins on conflict.

---

## 10. Git and commits

- Branch per phase: `phase-1-base`, `phase-2-sales-ncf`, ... created from `main`. Executors never commit to `main`; the coordinator merges after review (`git merge --no-ff`).
- One commit per task, at the end of the task, only when the task's verification passed. Message: Conventional Commits, English, imperative, ≤ 72 chars subject, scope = area (`feat(catalog): add clients CRUD`), body optional, trailer line exactly:
  `Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>`
- Commit everything the task touched, including generated `composer.lock`/`package-lock.json`. Never commit `.env`, `node_modules`, `vendor`, `public/build`, `resources/js/{actions,routes,wayfinder}`, `.mcp.json`, `boost.json`, `bootstrap/cache/*.php` (the kit's `.gitignore` covers these; Phase 1 Task 3 adds Boost's files).
- Pint (`vendor/bin/pint --dirty`) runs before every commit; PHPStan (`vendor/bin/phpstan analyse`) and `php artisan test` before the final commit of each task.

---

## 11. Haiku executor instructions

An executor is a Claude Haiku agent invoked headless (`claude -p`, permissions bypassed) in `/Users/shalemperez/www/gomezintsystem`. It receives: (a) this file §1–§11, (b) the phase plan's header + **Global Constraints** + **Review Focus**, (c) exactly one task, (d) the **Interfaces** blocks of the previous and next task.

Procedure:
1. Read everything you were given, in that order. Do not read other plans or the spec unless the task tells you to.
2. Check out the phase branch (`git switch phase-N-...`); run `git status` — it must be clean. If not, stop and report.
3. Execute the task's checkbox steps **in order, literally**. Use the exact file paths, names, signatures, column definitions, Spanish strings and commands the task gives. Where the task gives code, copy it verbatim. Where it gives a signature and a description, write the smallest body that satisfies the tests.
4. Run every command the task says to run, and compare the output to the task's **Expected** line. If the output differs in a way that changes the meaning (a test that should fail passes, an error you were not told to expect, a version that is not the one stated), **stop immediately** and report: the step number, the command, the full output, and what differed. Do not try alternative commands, packages, flags or designs. Do not "fix" the plan.
5. Never: add packages the task did not name; change a signature, column type or route name; rename a Spanish string; skip a test; mark a step done without running its command; use `float` for money; write to `main`; run `migrate:fresh` on anything but the dev/test databases named in the task; delete files the task did not list.
6. Use Boost's MCP tools as §9 requires. Use `search-docs` before guessing any API.
7. When all steps are done and the final verification commands print the expected output, make the task's commit (§10) and reply with: task id, commit hash, the final `composer test` summary line, and any step whose output differed but was explicitly allowed by the task.

Report format when stopping early:
```
TASK <id> BLOCKED at step <n>
Command: <cmd>
Expected: <text from plan>
Actual: <full output>
Diff: <one paragraph>
```

---

## 12. Phase roadmap (spec §9) and the interfaces each later plan exposes

**Rule for every later plan:** each task that introduces a model, pivot, state transition, export, import or integration call must list the audit rows it writes (ARCH §6.1) and the tests that assert them (ARCH §6.6), and every document page gets the "Historial" tab (ARCH §6.4).

**Phase 1 — Base** (`2026-10-29-01-fase1-base.md`): project scaffold on the React starter kit, PostgreSQL via Docker, Laravel Boost, Google Workspace login (Socialite; server-side `hd` claim check; invited/active users only; `SUPER_ADMIN_EMAILS` allowlist), users/roles/permissions with Super Admin rules and audited impersonation, append-only activity log with request context and full coverage (ARCH §6), Spanish app shell, master data (clients with RNC/cédula validation and an audited override for numbers that fail the checksum, contacts, suppliers, brands, categories, units, products with ITBIS rate, additional taxes table, lot flag, price lists, salespeople, warehouses, currencies and exchange rates with swappable source + scheduled fetch + failure alert, bank accounts/cash boxes), generic xlsx/csv export of every table, Excel importers with templates and per-row preview, settings screens. *Exposes:* models and factories for all master data; `Decimal` helper; `FiscalId`; `ExchangeRateService::rateFor()/convert()`; `PermissionCatalog`; `TableExport`; `ImportBatch` framework; `DataTable`/`FormField`/`PageHeader` React components; `can()`; settings service `Settings::get/set`.

**Phase 2 — Sales with traditional NCF + basic inventory** (`...-02-fase2-ventas-ncf.md`): proformas (states, PDF, duplicate, partial conversion), invoices (B01/B02/B14/B15 numbering via `FiscalNumberingService` + `ncf_sequences` with expiry and <10 % alerts), credit/debit notes (B04/B03), receipts (cobros) with partial application, retentions and advances, client statements and aging, dispatches/conduces with reservations, returns, initial stock import with lots (FEFO suggestion), kardex, minimum-stock alerts, document numbering, dompdf templates, email sending via Gmail API (send-as-user, OAuth scope `gmail.send`), historical open invoices import, and an `accounting_events` outbox table (every financial event is recorded as a row so Phase 5 can generate entries retroactively). *Exposes:* `Proforma`, `Invoice`, `InvoiceLine`, `CreditNote`, `DebitNote`, `Receipt`, `Dispatch`, `StockMovement`, `Lot`, `StockBalance`; `FiscalNumberingService::assign(Document): FiscalNumber`; `DocumentTotalsCalculator` (uses §4 rules); `AccountingEventRecorder::record(event, payload)`; `InventoryService::reserve/release/dispatch/return`; PDF `DocumentPdf` renderer; `GmailSender` interface with `LogGmailSender` fake.

**Phase 3 — e-CF** (`...-03-fase3-ecf.md`): `App\Contracts\EcfProvider` with `FakeEcfProvider`, `DgiiDirectEcfProvider` (certificate upload encrypted, seed/token auth, XML per official XSD, XSD validation, XMLDSig with xmlseclibs, send/track/poll jobs, RFCE summary for low-value consumer invoices, environments test/cert/prod, receiver web services + commercial approval, printed representation with QR, certification screen, contingency "pendiente de envío"), `PsfeEcfProvider` skeleton (Alanube mapping), `fiscal mode` switch `ncf → ecf` with cutover date, e-NCF sequences, immutable storage of signed XML and responses. The plan cites the DGII technical documentation versions/URLs it implements. *Exposes:* `EcfProvider::issue(EcfDocument): EcfResult`, `EcfProvider::status(trackId)`, `EcfDocumentBuilder` (provider-independent), `ReceivedEcf` model.

**Phase 4 — Purchases and imports** (`...-04-fase4-compras.md`): purchase orders (USD), shipments/expedientes with documents, import cost lines, liquidation with proration (FOB default / weight / quantity) producing landed unit cost in DOP, warehouse receipts from shipments, weighted-average cost updates, inventory adjustments/counts/transfers, supplier invoices and payables with aging and retentions, expenses with supplier NCF, purchase vouchers B11/B13/B17 and E41/E43/E47 (via `FiscalNumberingService`/`EcfProvider`), received e-CF registration as purchases. *Exposes:* `PurchaseOrder`, `Shipment`, `ShipmentCost`, `SupplierInvoice`, `SupplierPayment`, `Expense`; `LandedCostCalculator`; `CostingService::applyReceipt/applyLiquidation`.

**Phase 5 — Accounting and fiscal** (`...-05-fase5-contabilidad.md`): chart of accounts (Dominican template, hierarchical), account mappings per event, journal entries (auto from `accounting_events` incl. retroactive generation, manual with attachments), multi-currency with realized FX differences, monthly/annual periods with locks, bank movements and reconciliation with CSV/XLSX statement import, financial statements, DGII 606/607/608/609 TXT per official formats (cited), IT-1 and IR-17 summaries, opening balances tool. *Exposes:* `Account`, `JournalEntry`, `JournalLine`, `FiscalPeriod`; `EntryGenerator::fromEvent(AccountingEvent)`; `FiscalReportBuilder::f606/f607/f608/f609(period)`.

**Phase 6 — Commissions** (`...-06-fase6-comisiones.md`): rules (global, per salesperson, per salesperson+brand; moment on collection/on invoice; base without taxes/with ITBIS/with all taxes; discount subtraction; effective dates), accrual on invoice/receipt events, credit-note reversals, monthly settlement with approval, payment marking and accounting event, salesperson self-view. *Exposes:* `CommissionRule`, `CommissionAccrual`, `CommissionSettlement`; `CommissionCalculator::forEvent(...)`.

**Phase 7 — Client 360 and Gmail reading** (`...-07-fase7-crm-gmail.md`): client and supplier 360 pages (data, balances, documents, top products, email timeline, internal notes, attachments), Gmail sync via domain-wide delegation service account (configurable mailboxes, matching by contact emails and client/supplier domains, metadata + snippet + thread link, attachments on demand), visibility rules from spec §3.13. *Exposes:* `EmailMessage`, `EmailThread`, `ClientNote`; `GmailSyncJob`; `MailboxSyncSetting`.

**Phase 8 — Reports and dashboards** (`...-08-fase8-reportes.md`): role-based dashboard widgets, sales/margin/receivables/payables/inventory/rotation/import-cost/commission reports with filters, queued heavy reports with notification on completion, PDF/xlsx/csv outputs reusing `TableExport`. *Exposes:* `ReportDefinition` registry, `QueuedReport` model.
