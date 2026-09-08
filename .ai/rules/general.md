---
paths:
  - '**/*'
---

# General

## Ground rules live in .ai/guidelines/abode.md
The project ground rules (narrow scope, no filler, comments, naming, personal-only, conventions-first, dependency policy) are maintained in .ai/guidelines/abode.md, which Boost compiles into CLAUDE.md so they load in every session. Edit them there, not here.

## Development environment: everything flows from migrate:fresh
migrate:fresh is the dev reset button: the DatabaseRefreshed listener (local-only) recreates the dev user (email in config/development.php, password from DEVELOPMENT_USER_PASSWORD), grants all permissions, recreates the deterministic API token (DEVELOPMENT_API_TOKEN, pipe-less so Sanctum resolves it by hash — survives every fresh). The hook never seeds finance data — run DevelopmentFinancialSeeder explicitly (idempotent, local-only) when sample data is wanted.
POST /dev/login is a local-only login bypass; the button renders only when the Blade shell flags local. config/development.php is the home for dev-only config — new dev knobs go there, not scattered env checks.
