#!/bin/sh
set -eu

PORT="${PORT:-10000}"
GIT_STORAGE_ROOT="${GIT_STORAGE_ROOT:-/tmp/erni-pms/git}"
GIT_WORKSPACE_ROOT="${GIT_WORKSPACE_ROOT:-/tmp/erni-pms/git-workspaces}"
GIT_PROJECT_ROOT="${GIT_PROJECT_ROOT:-$GIT_STORAGE_ROOT}"
UPLOAD_STORAGE_ROOT="${UPLOAD_STORAGE_ROOT:-/tmp/erni-pms/uploads}"
UPLOAD_STORAGE_BACKEND="${UPLOAD_STORAGE_BACKEND:-filesystem}"
export GIT_STORAGE_ROOT GIT_WORKSPACE_ROOT GIT_PROJECT_ROOT UPLOAD_STORAGE_ROOT UPLOAD_STORAGE_BACKEND

mkdir -p "$GIT_STORAGE_ROOT" "$GIT_WORKSPACE_ROOT" "$UPLOAD_STORAGE_ROOT"
chown -R www-data:www-data "$GIT_STORAGE_ROOT" "$GIT_WORKSPACE_ROOT" "$UPLOAD_STORAGE_ROOT"

cd /var/www/html

if [ "${AUTO_INIT_DB:-1}" = "1" ] && [ -x "./bin/migrate-db.sh" ]; then
    ./bin/migrate-db.sh
fi

sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
