# Abode

A household management app for the Richhart family. Currently focused on personal finance: recording transactions in a double-entry journal (with commodities, lots, and cost basis), reconciling accounts against statements with balance assertions, tracking investments at market prices, and reading the results on account and commodity dashboards and reports. It is a personal project — configuration lives in code, and there is no admin UI.

## Stack

- Laravel (PHP 8.5) serving a versioned JSON API under `/api/v1`, documented with Scramble at `/docs` (local only)
- Vue 3 SPA (Vite, TypeScript, Tailwind) consuming the API via Sanctum cookie auth
- PostgreSQL — required; the schema uses `jsonb`, wide decimals, and check constraints

## Development

```bash
composer run setup        # install, .env, key, migrate, npm install, build
composer run dev          # serve app + vite + logs
```

- `php artisan migrate:fresh` is the reset button: it recreates the dev user and permissions. Finance data is never seeded automatically — run `php artisan db:seed --class=DevelopmentFinancialSeeder` when sample data is wanted.
- `POST /dev/login` (and the login-page button) bypasses credentials in local only.
- Commodity prices come from `php artisan financial:fetch-prices` (Yahoo and Indiana 529 sources), scheduled daily.

### Quality gates

```bash
php artisan test --parallel --compact                             # Pest, against Postgres
npm test                                                          # JS money tests (PHP/JS allocation parity)
vendor/bin/pint --dirty
vendor/bin/phpstan analyse app database tests
npm run build                                                     # vue-tsc type check + vite build
```

CI runs all of these on pull requests and pushes to `main`.

## How it runs

Production is a Docker Compose stack on the family home server, behind a TLS-terminating reverse proxy. Merging to `main` publishes the image (`ghcr.io/doubleoh13/abode`); updating the server is a deliberate, manual `docker compose pull && up -d`. The container migrates its own database on startup and refuses to serve if that fails, restoring from an automatic pre-migration backup.

`compose.production.yml` defines the stack and `docker/env.production.example` documents its environment; the startup protocol lives in `docker/entrypoint.sh`. Users are created by hand — there is no registration.
