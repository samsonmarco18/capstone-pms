#!/bin/bash
set -e

if [ -z "${DATABASE_URL:-}" ]; then
    echo "DATABASE_URL is required"
    exit 1
fi

psql "$DATABASE_URL" -f database.postgres.sql
