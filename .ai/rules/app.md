---
paths:
  - 'app/**'
---

# App

## Money is decimal
All amounts, quantities, rates are decimal strings handled by App\Support\Money\Decimal (brick/math, HALF_UP). Never cast money to float. Amounts 2dp, qty/unit prices 4dp, FX 6dp, percents 4dp.

## Audit everything
Every model uses App\Models\Concerns\LogsModelActivity; every action/transition/export/import/auth event writes an activity() row with a Spanish description; activity_log is append-only (model guard + PostgreSQL trigger). Every new model or action needs a test asserting its audit rows.
