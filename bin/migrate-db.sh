#!/bin/bash
set -e

echo "🚀 Running database migrations..."

# Wait for database to be ready
echo "Waiting for database connection..."
max_attempts=30
attempt=0
while [ $attempt -lt $max_attempts ]; do
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" >/dev/null 2>&1; then
        echo "✅ Database connection successful"
        break
    fi
    attempt=$((attempt + 1))
    echo "Attempt $attempt of $max_attempts... Retrying in 2 seconds"
    sleep 2
done

if [ $attempt -ge $max_attempts ]; then
    echo "❌ Database connection failed after $max_attempts attempts"
    exit 1
fi

# Check if database is empty
echo "Checking if database needs initialization..."
TABLES=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" -e "SHOW TABLES" 2>/dev/null | wc -l)

if [ "$TABLES" -le 1 ]; then
    echo "📊 Database is empty, importing schema..."
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < database.sql
    echo "✅ Database schema imported"
else
    echo "✅ Database already initialized ($((TABLES - 1)) tables found)"
fi

echo "🎉 Database migration completed"
