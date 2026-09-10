#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
export PGPASSWORD="${DB_PASSWORD:-}"
PG=(-h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-abode}")

wait_for_database() {
    for _ in $(seq 1 60); do
        if pg_isready "${PG[@]}" -d "$DB_DATABASE" -q \
            && psql "${PG[@]}" -d "$DB_DATABASE" -c 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    echo "Database ${DB_HOST:-db}:${DB_PORT:-5432}/${DB_DATABASE} unreachable after 120s" >&2
    exit 1
}

cache_laravel() {
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
}

migrations_pending() {
    # Exit codes: 0 = up to date, 2 = pending, 1 = migrations table missing (first
    # boot) or error - anything non-zero means migrate must run.
    ! php artisan migrate:status --pending=2 >/dev/null 2>&1
}

run_migration_protocol() {
    local dump
    dump="$BACKUP_DIR/abode-pre-migrate-$(date -u +%Y%m%dT%H%M%SZ)-${APP_BUILD_SHA:-unknown}.dump"
    mkdir -p "$BACKUP_DIR"

    echo "Pending migrations detected - dumping $DB_DATABASE to $dump"
    pg_dump "${PG[@]}" -Fc -f "$dump" "$DB_DATABASE"

    if php artisan migrate --force && php artisan app:smoke; then
        rm -f "$dump"
        echo "Migrations applied and smoke check passed"
        return 0
    fi

    echo "MIGRATION FAILED - restoring $DB_DATABASE from $dump" >&2
    # Drop and recreate the schema rather than pg_restore --clean: a partially
    # applied migration can create objects absent from the dump, which --clean
    # would leave behind.
    psql "${PG[@]}" -d "$DB_DATABASE" -v ON_ERROR_STOP=1 \
        -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' \
        -c "GRANT ALL ON SCHEMA public TO \"${DB_USERNAME:-abode}\";"
    if ! pg_restore "${PG[@]}" -d "$DB_DATABASE" --no-owner --exit-on-error "$dump"; then
        echo "RESTORE FAILED - dump preserved at $dump" >&2
        exit 2
    fi
    echo "Restore succeeded - dump preserved at $dump for inspection" >&2
    exit 1
}

case "${1:-app}" in
    app)
        wait_for_database
        if migrations_pending; then
            run_migration_protocol
        fi
        cache_laravel
        exec apache2-foreground
        ;;
    scheduler)
        wait_for_database
        cache_laravel
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
