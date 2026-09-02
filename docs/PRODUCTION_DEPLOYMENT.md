# KernelBridge Production Deployment

This runbook targets Ubuntu, Nginx, PHP 8.3 FPM, MySQL 8, Redis, Supervisor, Let's Encrypt and Git. Commands assume the application lives under `/var/www/kernelbridge`, with shared state in `shared/` and immutable releases in `releases/`.

## 1. DNS and server baseline

Point `kernelbridge.com` and `www.kernelbridge.com` A/AAAA records to the server. Restrict SSH, enable automatic security updates, and permit only SSH, HTTP and HTTPS through the firewall.

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server supervisor git unzip curl certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-curl php8.3-mbstring php8.3-xml \
  php8.3-bcmath php8.3-zip php8.3-gd php8.3-intl composer nodejs npm
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Use a supported Node LTS release. Confirm versions with `php -v`, `composer --version`, `node --version`, `mysql --version` and `redis-cli ping`.

## 2. Database and Redis

Run `sudo mysql_secure_installation`. Create a least-privilege application account; replace both generated passwords before executing:

```sql
CREATE DATABASE kernelbridge_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kernelbridge'@'localhost' IDENTIFIED BY 'LONG_RANDOM_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON kernelbridge_db.* TO 'kernelbridge'@'localhost';
FLUSH PRIVILEGES;
```

Bind MySQL and Redis to loopback unless private networking requires otherwise. Set a Redis password with `requirepass` in `/etc/redis/redis.conf`, restart Redis, and keep the password only in the production `.env`.

## 3. Shared directories and environment

```bash
sudo install -d -o www-data -g www-data /var/www/kernelbridge/{releases,shared/storage/app,shared/storage/framework/cache,shared/storage/framework/sessions,shared/storage/framework/views,shared/storage/logs}
sudo cp deployment/.env.production.example /var/www/kernelbridge/shared/.env
sudo chown www-data:www-data /var/www/kernelbridge/shared/.env
sudo chmod 600 /var/www/kernelbridge/shared/.env
```

Replace every placeholder. Generate `APP_KEY` once using `php artisan key:generate --show`; never regenerate it after encrypted production data exists. Ensure `APP_ENV=production`, `APP_DEBUG=false`, Redis session/cache/queue drivers, HTTPS URL and real SMTP credentials. Remove `SUPER_ADMIN_PASSWORD` after the one-time seed.

Writable directories must belong to `www-data`; application source should not be globally writable:

```bash
sudo chown -R www-data:www-data /var/www/kernelbridge/shared/storage
sudo find /var/www/kernelbridge/shared/storage -type d -exec chmod 775 {} \;
sudo find /var/www/kernelbridge/shared/storage -type f -exec chmod 664 {} \;
```

## 4. First release

Clone into a timestamped release, link shared state, then install and optimize:

```bash
sudo -u www-data git clone --branch main YOUR_PRIVATE_REPOSITORY /var/www/kernelbridge/releases/INITIAL_RELEASE
cd /var/www/kernelbridge/releases/INITIAL_RELEASE
sudo -u www-data ln -s /var/www/kernelbridge/shared/.env .env
sudo -u www-data rm -rf storage
sudo -u www-data ln -s /var/www/kernelbridge/shared/storage storage
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
sudo -u www-data npm ci
sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize
sudo -u www-data ln -sfn /var/www/kernelbridge/releases/INITIAL_RELEASE /var/www/kernelbridge/current
```

Run `php artisan db:seed --class=RoleAndPermissionSeeder --force` and the one-time super-admin seeder only when required. Do not run general seeders blindly on production.

## 5. Nginx and TLS

Install the bootstrap HTTP virtual host first:

```bash
sudo cp deployment/nginx/kernelbridge-bootstrap.conf /etc/nginx/sites-available/kernelbridge
sudo ln -s /etc/nginx/sites-available/kernelbridge /etc/nginx/sites-enabled/kernelbridge
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/kernelbridge/current/public -d kernelbridge.com -d www.kernelbridge.com
sudo cp deployment/nginx/kernelbridge.conf /etc/nginx/sites-available/kernelbridge
sudo nginx -t && sudo systemctl reload nginx
sudo certbot renew --dry-run
```

The final virtual host redirects HTTP and `www` to `https://kernelbridge.com`, serves only `public/`, denies dotfiles, and forwards PHP exclusively to PHP 8.3 FPM.

## 6. Queue workers and scheduler

```bash
sudo cp deployment/supervisor/kernelbridge-worker.conf /etc/supervisor/conf.d/kernelbridge-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status kernelbridge-worker:*

sudo cp deployment/cron/kernelbridge /etc/cron.d/kernelbridge
sudo chmod 644 /etc/cron.d/kernelbridge
```

Workers prioritize the `notifications` queue and then `default`. During each deployment run `php artisan queue:restart` or restart the Supervisor group after switching the `current` symlink.

## 7. Backups

Store MySQL credentials in `/root/.my.cnf` with mode `600`:

```ini
[client]
user=kernelbridge_backup
password=REPLACE_WITH_BACKUP_PASSWORD
host=127.0.0.1
```

Grant the backup user read/backup privileges, install the provided script, and schedule it nightly:

```bash
sudo install -m 700 deployment/scripts/backup.sh /usr/local/sbin/kernelbridge-backup
echo '30 1 * * * root /usr/local/sbin/kernelbridge-backup >> /var/log/kernelbridge-backup.log 2>&1' | sudo tee /etc/cron.d/kernelbridge-backup
```

The script creates transaction-consistent compressed database dumps, archives shared storage, writes checksums, and retains 14 local days. Replicate backups to encrypted off-site storage, apply provider-side retention, monitor job failures, and perform a documented restore test at least quarterly. Never rely on same-server backups alone.

## 8. Log rotation and monitoring

```bash
sudo cp deployment/logrotate/kernelbridge /etc/logrotate.d/kernelbridge
sudo logrotate --debug /etc/logrotate.d/kernelbridge
```

Monitor Nginx, PHP-FPM, Laravel, MySQL, Redis, Supervisor, disk capacity, certificate expiry, queue failures and backup age. Configure alerts against `GET https://kernelbridge.com/health`. It returns only `ok` or `failed` component states and HTTP 503 when a dependency is unhealthy; it never returns credentials, hosts or exception messages.

## 9. Subsequent deployment checklist

1. Confirm backups and maintenance window/rollback release.
2. Fetch a new immutable release.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Run `npm ci` and `npm run build`.
5. Run `php artisan migrate --force`.
6. Run `php artisan storage:link` and `php artisan optimize`.
7. Atomically switch `/var/www/kernelbridge/current`.
8. Restart queue workers and reload PHP-FPM.
9. Verify `/health`, login, public pages, queue processing and scheduler output.
10. Retain at least five releases and roll the symlink back if application verification fails. Database rollback requires a migration-specific plan; never blindly run `migrate:rollback` in production.
