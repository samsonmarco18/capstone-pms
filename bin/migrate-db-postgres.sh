#!/bin/sh
set -eu

if [ -z "${DATABASE_URL:-}" ]; then
    echo "DATABASE_URL is required for Postgres startup but is not set."
    exit 1
fi

if [ ! -f "database.postgres.sql" ]; then
    echo "database.postgres.sql not found."
    exit 1
fi

echo "Checking Render Postgres readiness..."
attempt=0
max_attempts="${DATABASE_READY_MAX_ATTEMPTS:-30}"
sleep_seconds="${DATABASE_READY_SLEEP_SECONDS:-2}"

while [ "$attempt" -lt "$max_attempts" ]; do
    if psql "$DATABASE_URL" -tAc "SELECT 1" >/dev/null 2>&1; then
        break
    fi

    attempt=$((attempt + 1))
    echo "Postgres not ready yet ($attempt/$max_attempts). Waiting ${sleep_seconds}s..."
    sleep "$sleep_seconds"
done

if [ "$attempt" -ge "$max_attempts" ]; then
    echo "Postgres did not become ready in time."
    exit 1
fi

table_exists="$(psql "$DATABASE_URL" -tAc "SELECT to_regclass('public.users') IS NOT NULL" | tr -d '[:space:]')"

if [ "$table_exists" = "t" ]; then
    echo "Postgres schema already present. Skipping import."
else
    echo "Importing database.postgres.sql..."
    psql "$DATABASE_URL" -1 -v ON_ERROR_STOP=1 -f database.postgres.sql
    echo "Postgres bootstrap completed."
fi

if [ -f "bin/migrations/postgres-folders.sql" ]; then
    echo "Applying folder-management compatibility migration..."
    psql "$DATABASE_URL" -1 -v ON_ERROR_STOP=1 -f bin/migrations/postgres-folders.sql
fi
