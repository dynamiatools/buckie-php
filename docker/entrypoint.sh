#!/bin/sh
set -eu

DATA_DIR="${BUCKIE_DATA_DIR:-/var/buckie-data}"
STORAGE_DIR="${BUCKIE_STORAGE_DIR:-/var/buckie-storage}"

mkdir -p "$DATA_DIR" "$STORAGE_DIR"
chown -R www-data:www-data "$DATA_DIR" "$STORAGE_DIR"
chmod 700 "$DATA_DIR"

# Allow `docker exec ... php bin/buckie ...` and `docker run ... buckie <cmd>` style usage
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec apache2-foreground
