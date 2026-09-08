---
paths:
  - 'database/migrations/**'
---

# Migrations

## All timestamp columns are timezone-aware
Every timestamp column holds tz data: use timestampsTz() / timestampTz(), never timestamps() / timestamp(). Existing migrations were converted 2026-09-06; any new migration (including ones merged from older branches) must follow. Plain date columns (e.g. opened_at/closed_at) stay date.

## FK columns carry the full prefixed table name
Foreign key columns are named after the full prefixed table they reference: financial_institution_id, financial_commodity_id, financial_payee_id — never institution_id. Applies end to end: migrations, fillable, API request/response fields, TS types. Because the column no longer matches Laravel's derived default, every belongsTo/hasMany against these keys passes the FK explicitly. Exception: role-named self-references stay role-named (parent_id on financial_accounts).

## DB CHECK constraints backstop financial sign/zero invariants
Financial value columns carry CHECK constraints mirroring validation, guarding non-API writers: prices >= 0, lot costs >= 0, posting amounts <> 0 (added via DB::statement — Blueprint has no check()). Scale/rounding rejection stays in BigDecimalCast only: a CHECK cannot catch excess fractional digits because Postgres rounds to the column scale before CHECKs evaluate. Text columns are sized to their validated max (code 16, kind 16, symbol/placement 8, status 16).
