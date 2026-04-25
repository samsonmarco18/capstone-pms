#!/bin/sh
set -eu

driver="${DB_DRIVER:-}"

if [ -z "$driver" ] && [ -n "${DATABASE_URL:-}" ]; then
    case "$DATABASE_URL" in
        postgres://*|postgresql://*|pgsql://*)
            driver="pgsql"
            ;;
        *)
            driver="mysql"
            ;;
    esac
fi

if [ "$driver" = "pgsql" ]; then
    exec ./bin/migrate-db-postgres.sh
fi

if ! command -v mysql >/dev/null 2>&1; then
    echo "MySQL client is not installed. Skipping MySQL bootstrap."
    exit 0
fi

if [ ! -f "database.sql" ]; then
    echo "database.sql not found."
    exit 1
fi

echo "Checking MySQL readiness..."
attempt=0
max_attempts="${DATABASE_READY_MAX_ATTEMPTS:-30}"
sleep_seconds="${DATABASE_READY_SLEEP_SECONDS:-2}"
export MYSQL_PWD="${DB_PASS:-}"

while [ "$attempt" -lt "$max_attempts" ]; do
    if mysql -h"${DB_HOST:-localhost}" -u"${DB_USER:-root}" -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi

    attempt=$((attempt + 1))
    echo "MySQL not ready yet ($attempt/$max_attempts). Waiting ${sleep_seconds}s..."
    sleep "$sleep_seconds"
done

if [ "$attempt" -ge "$max_attempts" ]; then
    echo "MySQL did not become ready in time."
    exit 1
fi

table_count="$(mysql -N -s -h"${DB_HOST:-localhost}" -u"${DB_USER:-root}" -D"${DB_NAME:-erni_pms}" -e "SHOW TABLES" 2>/dev/null | wc -l | tr -d '[:space:]')"

if [ "${table_count:-0}" -gt 0 ]; then
    echo "MySQL schema already present. Skipping import."
    exit 0
fi

echo "Importing database.sql..."
mysql -h"${DB_HOST:-localhost}" -u"${DB_USER:-root}" "${DB_NAME:-erni_pms}" < database.sql
echo "MySQL bootstrap completed."
