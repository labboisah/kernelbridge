#!/usr/bin/env bash
set -Eeuo pipefail

APP=${1:?Usage: deploy-product.sh APP REPOSITORY [BRANCH]}
REPOSITORY=${2:?Usage: deploy-product.sh APP REPOSITORY [BRANCH]}
BRANCH=${3:-main}

case "$APP" in
    alignex|edunexa|mediflow|marketlens|staycontrol) ;;
    *) echo "Unsupported product: $APP" >&2; exit 64 ;;
esac

APP_ROOT="/var/www/$APP"
RELEASE="$APP_ROOT/releases/$(date -u +%Y%m%dT%H%M%SZ)"

sudo -u "$APP" git clone --depth 1 --branch "$BRANCH" "$REPOSITORY" "$RELEASE"
cd "$RELEASE"
sudo -u "$APP" ln -sfn "$APP_ROOT/shared/.env" .env
sudo -u "$APP" rm -rf storage
sudo -u "$APP" ln -sfn "$APP_ROOT/shared/storage" storage

sudo -u "$APP" composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
sudo -u "$APP" npm ci
sudo -u "$APP" npm run build
sudo -u "$APP" php artisan migrate --force
sudo -u "$APP" php artisan storage:link
sudo -u "$APP" php artisan optimize

ln -sfn "$RELEASE" "$APP_ROOT/current"
chown -h "$APP:$APP" "$APP_ROOT/current"
supervisorctl restart "$APP-worker:*"
systemctl reload php8.3-fpm

find "$APP_ROOT/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | tail -n +6 | cut -d' ' -f2- | xargs -r rm -rf --
