---
paths:
  - 'app/**'
---

# App

## Money is decimal
All amounts, quantities, rates are decimal strings handled by App\Support\Money\Decimal (brick/math, HALF_UP). Never cast money to float. Amounts 2dp, qty/unit prices 4dp, FX 6dp, percents 4dp.

## Audit everything
Every model uses App\Models\Concerns\LogsModelActivity; every action/transition/export/import/auth event writes an activity() row with a Spanish description; activity_log is append-only (model guard + PostgreSQL trigger). Every new model or action needs a test asserting its audit rows.

## Sales documents
Issued documents are immutable (`ImmutableWhenIssued`); corrections are credit/debit notes. Every state transition writes `activity()` plus `AccountingEventRecorder` when it has accounting meaning. Money goes through `DocumentTotalsCalculator`/`Decimal`. State errors throw `DocumentStateException`, which the exception handler flashes.

## e-CF
Only App\Services\Ecf\* talks to the DGII/PSFE (DgiiClient, AlanubeClient); actions and jobs go through EcfService and the EcfProvider contract. Signed XML and responses are immutable files written with EcfStorage::putNew(); secrets (p12, passwords, API keys) are encrypted and never logged or sent to Inertia. e-NCF numbers are never reused: rejected or cancelled numbers go to the ANECF annulment queue.
