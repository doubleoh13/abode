---
paths:
  - 'tests/**'
---

# Tests

## Tests run on in-memory SQLite until Postgres-specific behavior exists
Dev runs Postgres; the test suite deliberately stays on in-memory SQLite (phpunit.xml) for speed.
Switch the suite outright to Postgres (not both) when the first jsonb column, raw Postgres expression, or case-sensitivity-dependent LIKE appears.
