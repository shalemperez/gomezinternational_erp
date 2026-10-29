---
paths:
  - 'tests/**'
---

# Tests

## Tests run on PostgreSQL
Pest 5 on PostgreSQL gomez_test (docker compose service db, port 5435). Use actingAsRole('<role>') and valid<Model>Payload() from tests/Support/helpers.php and crudResourceTests() from tests/Support/crud.php; never sqlite.
