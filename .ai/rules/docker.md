---
paths:
  - 'docker/**'
---

# Docker

## Production image contract: entrypoint roles and migration safety
One image (php:8.5-apache) serves everything. entrypoint.sh roles: `app` (default) waits for Postgres, and only when `migrate:status --pending=2` exits non-zero runs the protocol — pg_dump to $BACKUP_DIR (bind-mounted), `migrate --force` + `app:smoke`, delete dump on success; on failure drop schema public, pg_restore, keep the dump, exit non-zero so Apache never serves. `scheduler` runs schedule:work and never migrates (compose orders it after app is healthy). Any other argv is exec'd as-is (tinker escape hatch). Laravel config/route/view/event caches run at container start, never at image build — they bake env.
