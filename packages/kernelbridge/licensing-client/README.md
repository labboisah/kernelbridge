# KernelBridge Laravel Licensing Client

This package is the reusable licensing boundary for AlignEx, EduNexa, MediFlow, MarketLens, StayControl, and future Laravel products. Product applications retain only a signed, encrypted local license snapshot; KernelBridge remains the source of truth.

## Installation

This is a private package and is not available from Packagist. Composer must first be told where to find it.

### Local sibling application

If the applications use this layout:

```text
C:\laragon\www\kernelbridge
C:\laragon\www\staycontrol
```

run these commands from the client application, for example `C:\laragon\www\staycontrol`:

```bash
composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client
composer require kernelbridge/licensing-client:^1.0
```

Composer records the path repository in the client application's `composer.json`. The equivalent manual configuration is:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../kernelbridge/packages/kernelbridge/licensing-client",
            "options": { "symlink": true }
        }
    ]
}
```

The repository URL is resolved relative to the client application's `composer.json`, not relative to the KernelBridge package. If the package is copied into the client project at `packages/kernelbridge/licensing-client`, use that path instead.

Then finish the Laravel installation:

```bash
php artisan vendor:publish --tag=kernelbridge-licensing-config
php artisan vendor:publish --tag=kernelbridge-licensing-migrations
php artisan migrate
```

### Private Composer repository

For production deployments, publish and tag this package as `1.0.0` in the private KernelBridge Composer/VCS repository, configure that repository in each product, and run:

```bash
composer require kernelbridge/licensing-client:^1.0
```

Do not use `minimum-stability: dev` as a workaround. The package now declares a stable `1.0.0` version for path-repository installations.

Laravel package discovery registers the provider, migrations, middleware aliases, and scheduled verification job.

## Environment

```dotenv
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-the-product-machine-token
KERNELBRIDGE_API_CONNECT_TIMEOUT=3
KERNELBRIDGE_API_TIMEOUT=10
KERNELBRIDGE_VERIFICATION_INTERVAL=15
KERNELBRIDGE_OFFLINE_GRACE_HOURS=72
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-an-independent-long-random-secret
```

Give every product and environment independent, least-privilege API credentials. Never commit tokens or the signing key. Rotate and revoke product tokens from KernelBridge when required.

## Activation and verification

```php
use KernelBridge\LicensingClient\Services\LicenseActivationService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;

$state = app(LicenseActivationService::class)->activate($licenseKey, 'AlignEx production');
$state = app(LicenseVerificationService::class)->verify();
$features = app(LicenseVerificationService::class)->getEntitlements();
app(LicenseVerificationService::class)->deactivate();
```

`KernelBridgeLicenseClient` exposes `activate()`, `verify()`, `deactivate()`, and `getEntitlements()` for lower-level integrations. Prefer the services because they atomically maintain local state.

## Route protection

```php
Route::middleware('kernelbridge.subscription.active')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});

Route::middleware('kernelbridge.feature:finance')->group(function () {
    Route::resource('invoices', InvoiceController::class);
});

Route::post('/users', StoreUserController::class)
    ->middleware('kernelbridge.limit:max_users,25');
```

Use the limit middleware only when a static required quantity is appropriate. For live usage counts, resolve `LicenseCacheService`, read `limit('max_users')`, and compare it with an authoritative backend count inside the business transaction.

## Local state and offline operation

The package stores one row per product containing public license, activation, subscription, and installation identifiers; encrypted license key and entitlement payload; verification/expiry timestamps; and an HMAC signature. It never stores central database IDs.

Normal requests read the local snapshot and do not call KernelBridge. The queued scheduled job refreshes the snapshot at `KERNELBRIDGE_VERIFICATION_INTERVAL`. Run both infrastructure processes:

```bash
php artisan queue:work --tries=3
php artisan schedule:work
```

If KernelBridge returns a temporary network/5xx failure, a correctly signed snapshot remains usable only until the earlier of license expiry and offline grace expiry. Explicit rejection, revocation, expiry, deactivation, a changed payload, or an invalid signature fails closed. Configuration errors are not treated as outages.

## Operational guidance

- Back up the local application database, but treat KernelBridge as the licensing source of truth.
- Protect `APP_KEY` and `KERNELBRIDGE_CACHE_SIGNING_KEY`; losing either can make the encrypted snapshot unreadable.
- Monitor failed `VerifyLicenseJob` jobs and API connectivity.
- Do not expose the local state model or entitlement payload through public controllers.
- Check entitlements again inside sensitive service methods; route middleware is a convenience boundary, not the only authorization control.

Run the package coverage from this repository with:

```bash
php artisan test --filter=LicensingClientTest
```
