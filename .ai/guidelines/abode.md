# Abode Project Guidelines

## Ground Rules

- This is Jake's personal side project — never SaaS, never marketed, users are Jake and immediate family only. Configuration belongs in code; no administrative UI, ever.
- Work in narrow slices: implement exactly the requested scope. Never anticipate features that were not asked for.
- No filler text anywhere, especially UI: no placeholder data, developer assurances, or reminders in finished work. Exception: placeholder data explicitly requested for visual review.
- Comments only for what code cannot express. No restating the code, no decision narration.
- Self-documenting code: full, intent-revealing names — never abbreviated at the cost of clarity.
- Stay true to Laravel conventions unless a deviation genuinely improves things. First-party Laravel packages are always a good reach. Other packages must earn their weight: adopt one only if it serves how this project already operates — never bend the project to fit a package.

## Architecture Constants

- API-first: every feature lands as a versioned API under /api/v1 (domain-prefixed, e.g. /api/v1/financial/*) with the Vue SPA and a future native app as consumers.
- Domains are namespaced end to end: tables (financial_*), models (App\Models\Financial), enums, HTTP classes, URLs, route names, docs groups. Cross-domain artifacts stay at namespace roots.
- Decisions live in .ai/rules — always read every rule file whose globs match the paths in scope before writing code, and record new durable decisions with the record-rule tool.

## Development Flow

- `php artisan migrate:fresh` is the dev reset: it recreates Jake's user and permissions (local only). Finance data is never seeded automatically — run DevelopmentFinancialSeeder explicitly when sample data is wanted.
- Run tests with `php artisan test --parallel --compact` against Postgres.
- Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse app database tests --no-progress` before finishing PHP changes; `npm run build` type-checks the frontend via vue-tsc.
- `npm test` runs the JS money tests (Node's test runner) — required whenever resources/js/money.ts or CostBasisBalancer changes: it guards PHP/JS allocation parity.
