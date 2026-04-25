#!/bin/sh
set -eu

PORT="${PORT:-10000}"

cd /var/www/html

if [ "${AUTO_INIT_DB:-1}" = "1" ] && [ -x "./bin/migrate-db.sh" ]; then
    ./bin/migrate-db.sh
fi

sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
