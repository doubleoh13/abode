# Abode

A household management app for the Richhart family. Currently focused on personal finance: a double-entry journal with commodities, lots, and cost basis; account and commodity dashboards; balance assertions; market pricing; and reports. It is a personal project — configuration lives in code, and there is no admin UI.

## Stack

- Laravel (PHP 8.5) serving a versioned JSON API under `/api/v1`, documented with Scramble at `/docs` (local only)
- Vue 3 SPA (Vite, TypeScript, Tailwind) consuming the API via Sanctum cookie auth
- PostgreSQL — required; the schema uses `jsonb`, wide decimals, and check constraints

## Development

```bash
composer run setup        # install, .env, key, migrate, npm install, build
composer run dev          # serve app + vite + logs
```

- `php artisan migrate:fresh` is the reset button: it recreates the dev user, permissions, and the deterministic API token (`DEVELOPMENT_API_TOKEN`). Finance data is never seeded automatically — run `php artisan db:seed --class=DevelopmentFinancialSeeder` when sample data is wanted.
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

## Production

Pushes to `main` that pass CI publish `ghcr.io/doubleoh13/abode` (`latest` + commit sha). The image serves the app with Apache and owns migration safety at startup: pending migrations trigger a `pg_dump` to the bind-mounted `backups/` directory, then `migrate --force` and a smoke check — on any failure the schema is restored from the dump and the container exits without serving.

On the server:

```bash
# a directory containing compose.production.yml and a .env based on docker/env.production.example
docker compose -f compose.production.yml up -d
docker compose -f compose.production.yml exec app php artisan tinker   # create users
```

The app listens on `127.0.0.1:8080` for a TLS-terminating reverse proxy; the scheduler runs as a second container from the same image. Deploys are manual: `docker compose pull && docker compose up -d`.
