---
paths:
  - 'database/migrations/**'
---

# Migrations

## All timestamp columns are timezone-aware
Every timestamp column holds tz data: use timestampsTz() / timestampTz(), never timestamps() / timestamp(). Existing migrations were converted 2026-09-06; any new migration (including ones merged from older branches) must follow. Plain date columns (e.g. opened_at/closed_at) stay date.
