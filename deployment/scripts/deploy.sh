#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT=/var/www/kernelbridge
RELEASE="$APP_ROOT/releases/$(date -u +%Y%m%dT%H%M%SZ)"
REPOSITORY=REPLACE_WITH_YOUR_PRIVATE_GIT_REMOTE
BRANCH=${1:-main}

git clone --depth 1 --branch "$BRANCH" "$REPOSITORY" "$RELEASE"
cd "$RELEASE"

ln -sfn "$APP_ROOT/shared/.env" .env
rm -rf storage
ln -sfn "$APP_ROOT/shared/storage" storage

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm ci
npm run build

php artisan migrate --force
php artisan storage:link
php artisan optimize

ln -sfn "$RELEASE" "$APP_ROOT/current"
chown -h www-data:www-data "$APP_ROOT/current"

sudo supervisorctl restart kernelbridge-worker:*
sudo systemctl reload php8.3-fpm

find "$APP_ROOT/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | tail -n +6 | cut -d' ' -f2- | xargs -r rm -rf --
