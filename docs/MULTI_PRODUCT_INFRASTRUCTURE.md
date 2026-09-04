# KernelBridge Multi-Product Infrastructure

This blueprint allows the commercial platform and independently deployed products to share one Ubuntu VPS initially without sharing application tables, source trees, secrets, writable storage or worker processes.

## Isolation inventory

| Application | Domain | Linux user/root | Database/user | Redis DB/cache DB | Prefix | Queues |
|---|---|---|---|---|---|---|
| Commercial platform | `kernelbridge.com` | `www-data` / `/var/www/kernelbridge` | `kernelbridge_db` / `kernelbridge` | 0 / 1 | `kernelbridge_prod_` | `notifications`, `default` |
| AlignEx | `alignex.kernelbridge.com` | `alignex` / `/var/www/alignex` | `alignex_db` / `alignex` | 2 / 3 | `alignex_prod_` | `alignex:notifications`, `alignex:default` |
| EduNexa | `edunexa.kernelbridge.com` | `edunexa` / `/var/www/edunexa` | `edunexa_db` / `edunexa` | 4 / 5 | `edunexa_prod_` | `edunexa:notifications`, `edunexa:default` |
| MediFlow | `mediflow.kernelbridge.com` | `mediflow` / `/var/www/mediflow` | `mediflow_db` / `mediflow` | 6 / 7 | `mediflow_prod_` | `mediflow:notifications`, `mediflow:default` |
| MarketLens | `marketlens.kernelbridge.com` | `marketlens` / `/var/www/marketlens` | `marketlens_db` / `marketlens` | 8 / 9 | `marketlens_prod_` | `marketlens:notifications`, `marketlens:default` |
| StayControl | `staycontrol.kernelbridge.com` | `staycontrol` / `/var/www/staycontrol` | `staycontrol_db` / `staycontrol` | 10 / 11 | `staycontrol_prod_` | `staycontrol:notifications`, `staycontrol:default` |

Redis DB numbers and prefixes prevent accidental collisions, but Redis logical databases are not a security boundary. Bind Redis to loopback, require authentication and use separate Redis instances later where stronger isolation or independent scaling is required.

Each root follows this layout:

```text
/var/www/alignex/
├── current -> releases/20260831T120000Z
├── releases/
└── shared/
    ├── .env
    └── storage/
```

Never point two products at the same database, `.env`, `storage` directory, queue name or `APP_KEY`. Product migrations run only from that product's repository.

## DNS

Create A records (and AAAA records only when IPv6 is configured correctly) for:

```text
alignex.kernelbridge.com
edunexa.kernelbridge.com
mediflow.kernelbridge.com
marketlens.kernelbridge.com
staycontrol.kernelbridge.com
```

All may initially resolve to the same VPS IP. Use a short DNS TTL, such as 300 seconds, before future product migrations. Do not use a wildcard record unless unknown subdomains are intentionally routed.

## Linux users and filesystem

Create non-login service users so one PHP-FPM pool cannot read another application's secrets:

```bash
for app in alignex edunexa mediflow marketlens staycontrol; do
  sudo adduser --system --group --home /var/www/$app --shell /usr/sbin/nologin $app
  sudo install -d -o $app -g $app /var/www/$app/{releases,shared/storage/app/tmp,shared/storage/framework/cache,shared/storage/framework/sessions,shared/storage/framework/views,shared/storage/logs}
done
```

Set each shared `.env` to owner/group `<product>:<product>` and mode `600`. Storage directories should be `775` and files `664`, owned by the product user. Nginx reaches PHP through the pool socket and does not need access to `.env`.

## MySQL databases

Run with separately generated passwords:

```sql
CREATE DATABASE alignex_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE edunexa_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mediflow_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE marketlens_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE staycontrol_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'alignex'@'localhost' IDENTIFIED BY 'UNIQUE_RANDOM_PASSWORD';
CREATE USER 'edunexa'@'localhost' IDENTIFIED BY 'UNIQUE_RANDOM_PASSWORD';
CREATE USER 'mediflow'@'localhost' IDENTIFIED BY 'UNIQUE_RANDOM_PASSWORD';
CREATE USER 'marketlens'@'localhost' IDENTIFIED BY 'UNIQUE_RANDOM_PASSWORD';
CREATE USER 'staycontrol'@'localhost' IDENTIFIED BY 'UNIQUE_RANDOM_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON alignex_db.* TO 'alignex'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON edunexa_db.* TO 'edunexa'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON mediflow_db.* TO 'mediflow'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON marketlens_db.* TO 'marketlens'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON staycontrol_db.* TO 'staycontrol'@'localhost';
FLUSH PRIVILEGES;
```

No product database user receives privileges on `kernelbridge_db` or another product database. The commercial platform does not receive product database credentials.

## Product environments and Redis

Copy `deployment/products/product.env.example` into each product's `shared/.env`. Replace all placeholders, generate a unique `APP_KEY`, select the assigned Redis DB pair and set unique cache/queue values from the inventory table.

Product notification classes should route queued mail to `env('REDIS_NOTIFICATION_QUEUE', env('REDIS_QUEUE').':notifications')`; no application should hard-code another product's queue. Scheduler mutex/cache keys must inherit the unique cache prefix.

## Nginx, PHP-FPM and SSL

For each product, render the templates by replacing `{{APP}}` and `{{DOMAIN}}`. Example for AlignEx:

```bash
sed -e 's/{{APP}}/alignex/g' -e 's/{{DOMAIN}}/alignex.kernelbridge.com/g' deployment/products/php-fpm/product-pool.conf.template | sudo tee /etc/php/8.3/fpm/pool.d/alignex.conf
sed -e 's/{{APP}}/alignex/g' -e 's/{{DOMAIN}}/alignex.kernelbridge.com/g' deployment/products/nginx/product-bootstrap.conf.template | sudo tee /etc/nginx/sites-available/alignex
sudo ln -s /etc/nginx/sites-available/alignex /etc/nginx/sites-enabled/alignex
sudo php-fpm8.3 -t && sudo nginx -t
sudo systemctl reload php8.3-fpm nginx
sudo certbot certonly --webroot -w /var/www/alignex/current/public -d alignex.kernelbridge.com
sed -e 's/{{APP}}/alignex/g' -e 's/{{DOMAIN}}/alignex.kernelbridge.com/g' deployment/products/nginx/product-site.conf.template | sudo tee /etc/nginx/sites-available/alignex
sudo nginx -t && sudo systemctl reload nginx
```

Repeat for every product. Run `sudo certbot renew --dry-run`. Individual certificates make later migration of one product simpler than a shared SAN certificate.

## Supervisor and scheduler

Render one Supervisor template per product and install the shared scheduler file:

```bash
for app in alignex edunexa mediflow marketlens staycontrol; do
  sed "s/{{APP}}/$app/g" deployment/products/supervisor/product-worker.conf.template | sudo tee /etc/supervisor/conf.d/$app-worker.conf
done
sudo supervisorctl reread && sudo supervisorctl update
sudo cp deployment/products/cron/product-schedulers /etc/cron.d/kernelbridge-product-schedulers
sudo chmod 644 /etc/cron.d/kernelbridge-product-schedulers
```

Start with one worker per product and adjust `numprocs`, PHP-FPM children and MySQL limits based on measured memory/CPU. Confirm every group independently with `supervisorctl status` and `php artisan schedule:list` under the corresponding product user.

## Licensing API integration

Products communicate only through HTTPS with `https://kernelbridge.com/api/v1`; they never query `kernelbridge_db`. Product applications should implement the shared client behavior in `docs/LICENSING_CLIENT_SPEC.md`. Laravel products should install the reusable client documented in `docs/LICENSING_CLIENT.md`; Electron or trusted Node products should use `docs/TYPESCRIPT_LICENSING_CLIENT.md`; Spring Boot products should use `docs/SPRING_BOOT_LICENSING_CLIENT.md`; Django products should use `docs/DJANGO_LICENSING_CLIENT.md`. From the commercial platform, issue independent credentials:

```bash
cd /var/www/kernelbridge/current
sudo -u www-data php artisan api-client:issue ALIGNEX production-primary
sudo -u www-data php artisan api-client:issue EDUNEXA production-primary
sudo -u www-data php artisan api-client:issue MEDIFLOW production-primary
sudo -u www-data php artisan api-client:issue MARKETLENS production-primary
sudo -u www-data php artisan api-client:issue STAYCONTROL production-primary
```

Copy each token once into only that product's `.env` as `KERNELBRIDGE_API_TOKEN`. Never commit or log tokens. Each product integration client must:

- send `Authorization: Bearer <token>` and JSON accept headers;
- set connect/request timeouts and bounded retries only for safe/idempotent operations;
- preserve the response request ID in internal logs while redacting credentials and licence keys;
- call license activation, verification and deactivation endpoints from server-side code;
- cache entitlements briefly but fail closed when licence validity cannot be established beyond the approved offline grace policy;
- never expose the machine token to browsers or mobile clients.

Rotate with `api-client:rotate <credential-uuid> production-rotated`; deployment of the returned token and revocation timing should be coordinated. Immediately use `api-client:revoke <credential-uuid>` for suspected compromise. The API contract is documented in `docs/api-v1-openapi.yaml`.

## Backups

Install `deployment/products/scripts/backup-products.sh` as root with mode `700` and run nightly after the central platform backup. It produces separate MySQL dumps and storage archives per product plus checksums. Use a backup-only MySQL account stored in `/root/.my.cnf`.

Replicate every set to encrypted off-site storage, monitor freshness/failure, apply immutable retention where available, and conduct per-product restore tests quarterly. A product restore must target a clean database bearing only that product's schema. Redis is disposable runtime state and is not the authoritative backup for application data.

## Product deployment

Each application is an independent Git repository and release pipeline. Install the generic deployment helper with mode `700`, then invoke it with an approved repository:

```bash
sudo deployment/products/scripts/deploy-product.sh alignex git@github.com:kernelbridge/alignex.git main
```

The helper validates the product name, creates an immutable release, links only that product's `.env` and storage, installs dependencies, builds assets, runs only that product's migrations, optimizes Laravel, switches the product symlink, and restarts only its worker/FPM processes.

Before each deployment verify backups and rollback release. Afterward check the product health route, licensing API connectivity, login, critical workflow, queue consumption and scheduler. Never run a product migration from the commercial platform repository.

## Capacity and migration triggers

One VPS is a cost optimization, not high availability. Monitor memory, swap, CPU steal, MySQL connections/slow queries, Redis memory/evictions, PHP-FPM saturation, queue age, disk/inodes and backup duration. Move a product to its own host when it creates noisy-neighbour contention, needs independent compliance boundaries, requires separate scaling or exhausts safe VPS headroom. DNS, individual certificates, isolated roots/databases and independent credentials make that migration straightforward.







