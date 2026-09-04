# KernelBridge TypeScript Licensing Client

Framework-neutral licensing for Electron main processes and Node.js desktop services. It implements the same KernelBridge `/api/v1` activation, verification, deactivation, subscription, and entitlement protocol as the Laravel client while storing its local snapshot in SQLite behind operating-system encryption.

## Security boundary

Run this package only in Electron's **main process**. Never import it into a renderer or expose license keys, API tokens, encrypted state, or raw entitlements over IPC. Expose narrow decisions such as `hasFeature('finance')` and `limit('max_users')`.

A permanent `KERNELBRIDGE_API_TOKEN` can be extracted from a distributed desktop binary. `apiToken` exists for controlled/private deployments and protocol compatibility. Public Electron releases should provide a short-lived, revocable installation credential through `tokenProvider`; the central platform should issue it after license activation. Never put a central product machine token in source, asar files, renderer environment variables, or SQLite.

## Install and build

```bash
npm install @kernelbridge/licensing-client-typescript better-sqlite3
```

This repository builds/tests the private package with:

```bash
cd packages/kernelbridge/licensing-client-typescript
npm install
npm run typecheck
npm test
```

## Electron main-process setup

```ts
import Database from 'better-sqlite3';
import { safeStorage } from 'electron';
import {
  ElectronSafeStorageVault, KernelBridgeLicenseClient,
  LicenseActivationService, LicenseCacheService, LicenseGuard,
  LicenseVerificationService, PeriodicLicenseVerifier, SqliteLicenseStore,
} from '@kernelbridge/licensing-client-typescript';

const options = {
  apiUrl: process.env.KERNELBRIDGE_API_URL!,
  productCode: 'ALIGNEX',
  tokenProvider: installationCredentials,
  verificationIntervalMs: 15 * 60_000,
  offlineGraceMs: 72 * 60 * 60_000,
};
const store = new SqliteLicenseStore(new Database('/private/app-data/license.sqlite'));
const vault = new ElectronSafeStorageVault(safeStorage);
const cache = new LicenseCacheService(store, vault, options);
const api = new KernelBridgeLicenseClient(options);
export const activation = new LicenseActivationService(api, cache, options);
export const verification = new LicenseVerificationService(api, cache, options);
export const license = new LicenseGuard(cache);
const verifier = new PeriodicLicenseVerifier(() => verification.verify(true), options.verificationIntervalMs, console.error);
verifier.start();
```

For controlled installations, `licensingOptionsFromEnv()` loads the compatible environment names:

```dotenv
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-revocable-installation-token
KERNELBRIDGE_API_TIMEOUT_MS=10000
KERNELBRIDGE_VERIFICATION_INTERVAL_MS=900000
KERNELBRIDGE_OFFLINE_GRACE_MS=259200000
KERNELBRIDGE_REQUIRE_SIGNED_ENTITLEMENTS=false
```

Prefer a runtime `tokenProvider` backed by an installation credential for distributable applications; environment variables are not a secure secret boundary inside a packaged Electron application.

Activation and protocol methods:

```ts
await activation.activate(licenseKey, 'Front desk PC');
await verification.verify();
await verification.deactivate();
const entitlements = await verification.getEntitlements();

await license.assertActive();
await license.assertFeature('finance');
await license.assertLimit('max_users', currentUserCount + 1);
```

Invoke `verify(true)` on application startup and periodically in the main process. Normal navigation reads the encrypted local state and makes no network request. Stop the periodic verifier during application shutdown.


## Activation gating

The TypeScript package cannot automatically protect Electron windows or Node routes the way the Laravel package can push middleware into a web route group. Instead, call `LicenseGuard` from the Electron main process or from server-side Node route middleware before opening protected screens or running protected operations.

```ts
const decision = await license.checkActive();
if (!decision.allowed) {
  showActivationWindow(decision.message);
  return;
}

await openMainApplicationWindow();
```

`checkActive()` returns `{ allowed, reason, message }`. `assertActive()` throws `LicenseAccessError` with the same reason and message. After activation, the package stores a signed fingerprint of `apiUrl`, `productCode`, `apiToken`, `appKey`, `cacheSigningKey`, and any `extraConfigFingerprintValues`. If those values change, `checkActive()` returns `configuration_tampered` and the app should show the activation UI again.


## Renewal notices

Use `LicenseGuard.renewalNotice()` or `LicenseCacheService.renewalNotice()` to notify users before the current license expires. The default thresholds are 3, 2, and 1 month before expiry.

```ts
const notice = await license.renewalNotice();
if (notice) {
  showRenewalBanner(notice.message);
}
```

Warnings do not block access. Continue to use `checkActive()` or `assertActive()` for the activation gate.

## Local SQLite state

`SqliteLicenseStore` creates only `kernelbridge_license_state`. The entire JSON state—including the license key—is encrypted before SQLite receives it. The record includes product/installation/license/subscription/activation identifiers, last successful verification, expiry, offline grace expiry, feature/limit snapshot, optional server signature, a signed configuration fingerprint, and last rejection code.

Electron `safeStorage` must report encryption availability. The package refuses to save when it is unavailable. Protect the application data directory with normal OS permissions and exclude the licensing database from diagnostics uploaded to support.

## Offline behavior

Network and HTTP 5xx failures may use the last encrypted snapshot until the earlier of central license expiry and offline grace expiry. Explicit API rejection marks the local state invalid. Deactivation, expiry, changed sensitive configuration, missing activation, and corrupted local state fail closed. The package does not trust a renderer-provided status or total.

For stronger clock-rollback protection, production applications should persist the greatest signed server time and compare it with a monotonic session clock. For cryptographic offline entitlements, configure `requireSignedEntitlements` with `RsaSha256EntitlementVerifier`; this requires KernelBridge API responses to include `signed_payload` and `signature`. The public verification key may ship with the desktop app; the signing private key must remain only on KernelBridge servers.

## IPC example

Expose decisions, not objects:

```ts
ipcMain.handle('license:feature', (_event, key: string) => cache.hasFeature(key));
ipcMain.handle('license:limit', (_event, key: string) => cache.limit(key));
```

Validate allowed feature keys in the IPC handler and use Electron context isolation. Repeat checks inside sensitive main-process business operations; hiding a renderer button is not authorization.




