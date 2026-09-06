---
paths:
  - 'tests/**'
---

# Tests

## Tests run on Postgres (abode_testing)
The suite runs against the local Postgres database abode_testing (phpunit.xml), matching dev. Switched from in-memory SQLite on 2026-09-06 when timestamptz columns landed — the agreed trigger for Postgres-specific behavior.
`php artisan test` will offer to create the database if it is missing.
Prefer `php artisan test --parallel --compact` — per-process databases are created automatically and the suite runs roughly twice as fast.
