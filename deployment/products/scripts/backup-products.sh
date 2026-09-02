#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BACKUP_ROOT=/var/backups/kernelbridge-products
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST="$BACKUP_ROOT/$STAMP"
install -d -m 0700 "$DEST"

for APP in alignex edunexa mediflow marketlens staycontrol; do
    DATABASE="${APP}_db"
    mysqldump --defaults-extra-file=/root/.my.cnf --single-transaction --quick --routines --triggers --events "$DATABASE" | gzip -9 > "$DEST/$DATABASE.sql.gz"
    tar --create --gzip --file "$DEST/$APP-storage.tar.gz" --directory "/var/www/$APP/shared" storage
done

sha256sum "$DEST"/* > "$DEST/SHA256SUMS"
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf -- {} +

# Replicate this backup set to encrypted off-site storage and monitor completion.
