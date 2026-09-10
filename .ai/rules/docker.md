---
paths:
  - 'docker/**'
---

# Docker

## Production image contract: entrypoint roles and migration safety
One image (php:8.5-apache) serves everything. entrypoint.sh roles: `app` (default) validates env, waits for Postgres, and only when `migrate:status --pending=2` exits non-zero runs the protocol — pg_dump to $BACKUP_DIR, `migrate --force` + `app:smoke`, delete dump on success; on failure drop schema public, pg_restore, keep the dump, exit non-zero so Apache never serves. `scheduler` runs schedule:work and never migrates (compose orders it after app is healthy). Any other argv is exec'd as-is (tinker escape hatch). Laravel config/route/view/event caches run at container start, never at image build — they bake env.

## The container is self-configuring: /data volume + opinionated config
Config files are opinionated (pgsql-only database.php, hardcoded names/locales/cookies); env() survives only where a real override exists (tests pin array/sync drivers via phpunit.xml — never hardcode CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION/MAIL_MAILER defaults' env calls away). The image bakes deploy-invariants as ENV (DB_HOST=db, LOG_CHANNEL=stderr, SESSION_SECURE_COOKIE=true). Required server env is only APP_URL + DB_DATABASE/DB_USERNAME/DB_PASSWORD. Everything persistent lives in one `abode-data:/data` volume: storage/app is a build-time symlink to /data/app (entrypoint chown -R www-data heals root-owned tinker leftovers), migration dumps land in /data/backups, and APP_KEY is generated on first boot into /data/app_key (env APP_KEY overrides). Losing that volume loses attachments and the key.
