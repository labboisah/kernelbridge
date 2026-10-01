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
- [Licensing client spec](docs/LICENSING_CLIENT_SPEC.md)
- [Laravel licensing client](docs/LICENSING_CLIENT.md)
- [TypeScript licensing client](docs/TYPESCRIPT_LICENSING_CLIENT.md)
- [Spring Boot licensing client](docs/SPRING_BOOT_LICENSING_CLIENT.md)
- [Django licensing client](docs/DJANGO_LICENSING_CLIENT.md)
- [Licensing MCP server](docs/LICENSING_MCP_SERVER.md)
- [MCP implementation phases](docs/MCP_IMPLEMENTATION_PHASES.md)

## Installing the Laravel licensing client in another app

The PHP licensing client is private and is not on Packagist. From the other Laravel application's root, register this repository as a local Composer path repository before requiring the package:

```bash
composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel
composer require kernelbridge/licensing-client-laravel:^1.0
```

Use an absolute path such as `C:/laragon/www/kernelbridge/packages/kernelbridge/licensing-client-laravel` if the applications are not sibling folders.


## Client SDKs

The shared licensing behavior is now defined in [Licensing client spec](docs/LICENSING_CLIENT_SPEC.md). Laravel, TypeScript, Spring Boot, and Django clients are available:

- [Laravel client](packages/kernelbridge/licensing-client-laravel/README.md)
- [TypeScript client](packages/kernelbridge/licensing-client-typescript/README.md)
- [Spring Boot starter](packages/kernelbridge/licensing-client-spring-boot/README.md)
- [Django client](packages/kernelbridge/licensing-client-django/README.md)











## MediFlow package licensing

/account/licenses supports Pharmacy, General Clinic, Diagnostic Center, Maternity Clinic, Hospital, Enterprise Hospital and Care Network (specialist, matching MediFlow's canonical key). The reviewed source is ../mediflow/config/mediflow_modules.php; Enterprise expands the known module list at purchase time. Purchased modules and package prices are snapshotted so subsequent catalogue edits do not change an issued order.

Run the focused seeder on an existing platform:

    php artisan db:seed --class=MediFlowPackageSeeder

This adds missing package plans and features and preserves configured prices. Set actual prices, setup fees, currency and recurring billing period under **Administration > Products > MediFlow > Plans**. No software package prices were found in the reviewed MediFlow files. New packages therefore show **Contact sales** until priced; annual/NGN are initial editable catalogue defaults, not an approved price list. Legacy generic MediFlow plans are retained for existing records but excluded from public purchase listings.

Customers select packages with the same currency and period. Checkout calculates line items from server-side prices and creates one pending subscription and an issued invoice. Full verified Flutterwave payment, finance-verified payment allocations, or a finance-recorded invoice payment activates the subscription and issues one code. Partial payment and submitted but unverified proof do not issue codes. Flutterwave still requires its deployment credentials and webhook configuration; automated tests fake the gateway and do not charge money. The invoice page supports print/PDF, payment proof and the issued activation code link.

A package licence has one permanent installation binding. Activation stores a keyed machine fingerprint plus hostname, OS, architecture, client version, IP and timestamps in license_activations. Keys remain encrypted at rest. Activation limits are checked while holding a database row lock; sequential repeat activations are idempotent. Deactivation preserves the binding and cannot transfer the licence. Verification rejects a changed or omitted bound fingerprint. Refunds suspend the purchased subscription. Customers can review package additions and removals from the licensing page. Additions are prorated to the current period end, plus any setup fees, and unlock only after full settlement. Removals take effect immediately and credit the unused recurring time; setup fees are non-refundable. Account credits are currency-specific and can settle future invoices. A pending change must be settled or cancelled before another; partially paid changes cannot be cancelled. Late addition payments become credit instead of granting expired access. Renewal is offered at period expiry and uses the existing activation code. Adjusted invoices require finance reconciliation for refunds. The older single-plan change and renewal routes cannot bypass this workflow.

### MediFlow client rollout

The sibling MediFlow project includes the local Composer client, activation assets, CentralLicenseService, a public /license screen, guarded /installation/setup and EnsureCentralLicense. Apply the new platform credit migration and client onboarding migration in addition to SDK state migrations:

    # KernelBridge
    php artisan migrate --path=database/migrations/2026_10_01_000001_create_package_changes_and_customer_credits.php
    # MediFlow
    php artisan migrate --path=database/migrations/2026_10_01_000001_create_installation_onboarding.php

    php artisan migrate --path=vendor/kernelbridge/licensing-client-laravel/database/migrations
    php artisan vendor:publish --tag=kernelbridge-licensing-assets

On the platform, use the following command to create a product machine credential and store it securely in the client environment; do not commit it:

    php artisan api-client:issue MEDIFLOW

In MediFlow configure KERNELBRIDGE_API_URL, KERNELBRIDGE_API_TOKEN and an independent KERNELBRIDGE_CACHE_SIGNING_KEY, then set APP_MODE=client and rebuild configuration cache. Client mode always enforces activation, even when MEDIFLOW_CENTRAL_LICENSING=false. Non-client modes retain the local behavior unless MEDIFLOW_CENTRAL_LICENSING=true. Once enabled, an unactivated installation redirects to /license. Activate with the paid code, then complete branding and the first administrator account at /installation/setup. A short-lived browser grant prevents another visitor from taking over initial setup; existing installations require their installation administrator to sign in. Completion opens the normal welcome page. Run the Laravel scheduler continuously. Purchased entitlements drive module access; local package edits cannot grant extra modules. Branding and staff permissions remain separate. Activation and verification return an atomic package/feature configuration with descriptions and a revision, stored in the signed device-bound cache. After package changes or payment, the administrator selects Refresh subscription on the installation page; scheduled verification also refreshes entitlements. This configures modules already shipped with MediFlow; it does not download or execute remote source code.

The Laravel client hashes the Windows MachineGuid, Linux machine-id or macOS platform UUID; it sends no raw OS machine identifier. Its signed offline cache is also bound to that hash. Offline access is bounded by subscription expiry and 72-hour grace, and online rejection invalidates the cache. This detects ordinary copying of a database to a different OS installation; it is not hardware attestation and cannot defeat an administrator modifying the client or cloning/spoofing the OS identity. OS reinstalls or device replacement need support-assisted licence replacement. Existing caches must be refreshed online after this client update.

Focused verification:

    # KernelBridge
    php artisan test --filter="MediFlowLicensingTest|LicensingClientTest|CustomerPortalTest|ProductIntegrationApiTest"
    # MediFlow
    php artisan test --filter="CentralLicensingTest|InstallationSetupTest"

Concurrency protection uses row locks but the SQLite tests are not a production MySQL load test.
