---
paths:
  - '**/*'
  - ROADMAP.md
---

# General

## Ground rules live in .ai/guidelines/abode.md
The project ground rules (narrow scope, no filler, comments, naming, personal-only, conventions-first, dependency policy) are maintained in .ai/guidelines/abode.md, which Boost compiles into CLAUDE.md so they load in every session. Edit them there, not here.

## Development environment: everything flows from migrate:fresh
migrate:fresh is the dev reset button: the DatabaseRefreshed listener (local-only) recreates the dev user (email in config/development.php, password from DEVELOPMENT_USER_PASSWORD), grants all permissions. No API token is created; the SPA uses session auth and the local login bypass. The hook never seeds finance data — run DevelopmentFinancialSeeder explicitly (idempotent, local-only) when sample data is wanted.
POST /dev/login is a local-only login bypass; the button renders only when the Blade shell flags local. config/development.php is the home for dev-only config — new dev knobs go there, not scattered env checks.

## ROADMAP.md is the shared backlog
ROADMAP.md at the repo root holds Next / Backlog / Decide / Done. When Jake mentions a future idea, an open decision, or a deferred item mid-conversation, add it there in the same commit rather than only in session memory. Move items to Done with the commit hash when they ship. Keep entries to one line each; design detail belongs in .ai/rules once decided.
