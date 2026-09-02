# KernelBridge Platform

Production foundation for KernelBridge Technologies LTD, built with Laravel 12, Vue 3 Composition API, Inertia.js, Tailwind CSS, Vite, Sanctum, and Spatie Permission.

> Commercial launch status: **NO-GO until production infrastructure checks pass.** Start with the [launch checklist](docs/LAUNCH_CHECKLIST.md) and [commercial verification report](docs/COMMERCIAL_LAUNCH_REPORT.md).

## Requirements and installation

Requires PHP 8.3+ with MySQL, Redis, OpenSSL, Mbstring, PDO and XML extensions; Composer 2; Node.js 20+; MySQL 8+; and Redis 6+.

```bash
git clone <repository-url> kernelbridge
cd kernelbridge
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

Point the web server document root at `public/`. Production must set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://kernelbridge.com`.

## Environment

Copy `.env.example` and configure application, mail, logging, MySQL, Redis, session domain, and `SANCTUM_STATEFUL_DOMAINS` for kernelbridge.com and approved product subdomains. Essential values include:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kernelbridge
DB_USERNAME=kernelbridge
DB_PASSWORD=change-me
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
SUPER_ADMIN_NAME="KernelBridge Super Administrator"
SUPER_ADMIN_EMAIL=admin@kernelbridge.com
SUPER_ADMIN_PASSWORD=use-a-long-random-secret
```

Never commit `.env` or credentials.

## Migrations and seeding

Create an `utf8mb4` MySQL database, then run:

```bash
php artisan migrate --force
php artisan db:seed --force
```

The idempotent seeders create `super_admin`, `admin`, `sales`, `finance`, `support`, and `customer`, then create or update the first super administrator from environment values. For local reset only, use `php artisan migrate:fresh --seed`.

## Frontend

```bash
npm run dev
npm run build
```

## Queue and Redis

```bash
php artisan queue:work redis --queue=default --tries=3
php artisan queue:restart
```

Manage production workers using Supervisor or systemd. Inspect and retry failures with `php artisan queue:failed` and `php artisan queue:retry <id>`.

## Tests and quality

Tests use SQLite in memory and do not require local MySQL or Redis services.

```bash
php artisan test
vendor/bin/pint --test
npm run build
```

Run `vendor/bin/pint` to apply PHP formatting.

## Architecture

- `app/Http/Controllers/Admin`, `Customer`, and `PublicSite` contain area-specific transport controllers.
- `app/Http/Controllers/Api/V1` and `app/Http/Resources/Api/V1` define the versioned integration contract.
- `app/Http/Requests` owns validation; `app/Policies` owns model authorization; `app/Enums` owns canonical values.
- `app/Domain` contains transactional business services and contracts. Repositories are added for persistence-heavy aggregate queries, avoiding ceremonial wrappers around Eloquent.
- `routes/api/v1.php` is the API v1 boundary.

Important mutations belong in domain services and database transactions. Product applications authenticate through Sanctum tokens with narrowly scoped abilities; never share database credentials between products.

## Production deployment

After installing dependencies, building assets, and migrating, run:

```bash
php artisan optimize
php artisan queue:restart
```

Use `php artisan optimize:clear` when troubleshooting stale configuration or routes.

## Operations documentation

- [Administrator guide](docs/ADMINISTRATOR_GUIDE.md)
- [Sales staff guide](docs/SALES_GUIDE.md)
- [Finance staff guide](docs/FINANCE_GUIDE.md)
- [Support staff guide](docs/SUPPORT_GUIDE.md)
- [Customer onboarding](docs/CUSTOMER_ONBOARDING.md)
- [Production deployment](docs/PRODUCTION_DEPLOYMENT.md)
- [Multi-product infrastructure](docs/MULTI_PRODUCT_INFRASTRUCTURE.md)

composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client