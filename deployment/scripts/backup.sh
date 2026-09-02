#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_ROOT=/var/www/kernelbridge
BACKUP_ROOT=/var/backups/kernelbridge
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST="$BACKUP_ROOT/$STAMP"

install -d -m 0700 "$DEST"

# Credentials are read from /root/.my.cnf; never put the database password in this script.
mysqldump --defaults-extra-file=/root/.my.cnf --single-transaction --quick --routines --triggers --events kernelbridge_db | gzip -9 > "$DEST/database.sql.gz"
tar --create --gzip --file "$DEST/storage.tar.gz" --directory "$APP_ROOT/shared" storage
sha256sum "$DEST/database.sql.gz" "$DEST/storage.tar.gz" > "$DEST/SHA256SUMS"

find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf -- {} +

# Replicate $BACKUP_ROOT to encrypted off-site storage using restic/rclone or the provider's agent.
