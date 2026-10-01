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


### Account checkout and online implementation

Request this plan opens /account/checkout and preserves the selected plan through login or registration. Customers review features, local/online implementation and server-calculated invoice totals before proceeding to Flutterwave. The combined domain/VPS rate starts at NGN 20,000 per month. Administrators control availability, currency and price under Administration > Catalogue > Online implementation (/admin/hosting). Hosting is prepaid for the software term (1/3/6/12 months); MediFlow package renewals retain the contracted hosting rate. Package-removal credits exclude hosting fees. Domain requests do not reserve a domain or automatically create a VPS; support coordinates fulfilment.

Apply database/migrations/2026_10_01_000002_create_checkout_settings_and_client_bundles.php. Full verified payment provisions one encrypted client setup bundle with a subscription-restricted API token and independent cache-signing secret. The paid invoice and account service expose a private .env download to its customer only. Merge it into the client environment without replacing APP_KEY or database credentials, clear its configuration cache, and activate through /license. No platform Flutterwave keys or master product credentials are included. Configure APP_URL with a licensing URL reachable by the client before distributing settings.


### Managing plan prices and features

Use Administration > Products > Plans, prices & features for each product. The existing plans, plan_features and plan_limits tables store the commercial catalogue; product_features stores product-level marketing features. Plan feature descriptions are added by migration 2026_10_01_000003_add_description_to_plan_features.php. Set the price, currency, billing cycle and setup fee in the plan editor to publish a numeric amount. Unpriced MediFlow plans remain request-priced until an administrator supplies the actual software rates; the hosting rate is independent.

MediFlow package selection and checkout use saved enabled plan features, names and descriptions. New purchases snapshot these features so later catalogue edits do not silently change existing installations. Package additions use the current catalogue, while retained packages keep their purchased feature snapshot. Core and access_control must remain enabled. The MediFlow seeder initializes features only for newly created package plans and does not restore features removed by an administrator.


### Partner portal

- Public partner programmes at `/partners` cover developers, sales agents and investors. Customers register separately at `/register`.
- Partner registration creates an email/password login and a draft application without creating a customer billing account. Subsequent partner sign-ins open `/partner`.
- Admins review submissions at `/admin/partners`. Only approved sales agents can manage their own clients, prepare subscription invoices, submit payments, and open/reply to client support requests. Partner users receive no staff permissions.
- Configure percentage commissions per product at `/admin/partner-commissions`. The default rate is 0%. Rates are recorded when commissions are earned and later rate changes do not change existing entries.
- Commission accrues once per product/invoice when the subscription is active and the invoice is fully settled. Initial purchases, paid renewals and paid package additions are supported. The base is subscription line totals after discounts, excluding tax and non-plan charges, prorated to verified cash funding; credits do not earn commission twice. A refund reverses the affected invoice commission. Commission balances are grouped by currency.
- The revenue page separates earned commissions from verified client collections and outstanding client invoices. It records earnings, not commission payouts. Offline payments remain pending until finance verifies them; Flutterwave payments use the existing server-side verification workflow.
- Apply migrations `2026_10_01_000005_add_partner_owner_to_customers` and `2026_10_01_000006_create_partner_commissions` after the partner-applications migration, then refresh the configuration cache and rebuild frontend assets for other installations.


### System roles and invoice VAT

- Investor (`investor`), Agent (`agent`), Developer (`developer`) and Customer (`customer`) are listed in Admin > Access control. The internal sales role remains separate from partner agents. Partner registration assigns the corresponding role; existing partner applications are backfilled by migration `2026_10_01_000007_add_partner_roles_and_invoice_vat`. Agent operations still require an approved application.
- New invoices add VAT at the rate set in Admin > Finance > VAT settings (initially 7.5%) to each line after discounts, rounded to two decimal places. Subtotal, discount, VAT and the tax-inclusive total are displayed separately on the invoice. Checkout and package-change previews include VAT. Server calculations override submitted tax amounts for these invoices.
- The invoice stores its VAT rate. Existing invoices keep their original amounts and tax basis; editing an existing draft uses that stored basis. Converting an accepted quotation creates a new invoice using the current configured VAT rate.
- Subscription activation requires the full VAT-inclusive invoice balance to be settled. Partner commission remains based on eligible subscription charges excluding tax.

VAT settings are stored in `billing_settings`. Apply migration `2026_10_01_000008_create_billing_settings_table` on other installations. Only administrators can change the rate (0?100%, up to two decimal places); changes apply to new invoices and previews without retaxing existing invoices.


### Product commission allocation

Admin > Finance > Commission settings uses one product selector and one form for Developer, Platform, Staff and Agent percentages. All four fields are required and must total exactly 100%. Migration `2026_10_01_000009_add_product_commission_shares` preserves existing agent rates and assigns the remainder to Platform, with Developer and Staff initially 0%.

The table below the form shows saved allocations and calculates all four amounts for an editable eligible revenue amount, excluding VAT. These are illustrative calculations, not payout records. Minor-unit rounding is distributed to preserve the exact input total. Agent earnings continue to use the saved agent percentage and existing earned entries retain their original rates.


### VAT allocation and generated commissions

Commission settings now split VAT, Developer, Platform, Staff and Agent shares to exactly 100%. The VAT allocation percentage is a revenue allocation setting, separate from the VAT rate used to calculate customer invoices. The calculator uses subscription receipts including invoice VAT; hosting and credit-funded portions remain excluded.

Migration `2026_10_01_000010_add_vat_commission_allocation` introduces the fifth share using the current VAT setting, reducing Platform first (then Staff, Developer, Agent only if needed) so existing allocations still total 100%. Existing earned commissions are not recalculated.

New successful subscription payments snapshot all five allocations in `product_commission_accruals`. The product table reports generated Developer + Platform + Staff + Agent totals by currency and shows allocated VAT separately. Refunds reverse accruals. Historical agent earnings are included without inventing unrecorded historical allocations for other parties. Generated amounts represent allocations, not completed payouts; agent payout attribution still requires an approved agent client relationship.
