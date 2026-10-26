# KernelBridge Licensing Client Specification

This specification defines the shared behavior every KernelBridge product licensing client must implement. Laravel and TypeScript are the reference implementations; Spring Boot and Django clients should match this contract unless a framework limitation is documented.

## Packages

| Runtime | Package | Intended host |
| --- | --- | --- |
| PHP | `kernelbridge/licensing-client-laravel` | Laravel web applications |
| TypeScript | `@kernelbridge/licensing-client-typescript` | Electron main process, trusted Node services |
| Java | `kernelbridge-licensing-spring-boot-starter` | Spring Boot web/API applications |
| Python | `kernelbridge-licensing-django` | Django web/API applications |

## API Contract

All clients communicate with the KernelBridge Product Integration API at `/api/v1` using a bearer machine credential or revocable installation credential.

Required request headers:

```text
Accept: application/json
Authorization: Bearer <credential>
Content-Type: application/json
X-KernelBridge-Product: <PRODUCT_CODE>
```

Required endpoints:

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/licenses/activate` | `POST` | Activate a license key on one installation/device |
| `/licenses/verify` | `POST` | Verify an active installation/device |
| `/licenses/deactivate` | `POST` | Deactivate the current installation/device |
| `/subscriptions/status` | `GET` | Fetch subscription status and expiry/grace dates |
| `/entitlements` | `GET` | Fetch feature and limit entitlements |

Activation and verification requests must send a stable installation identifier as `device_id`. The identifier must be generated once per product installation and stored only in the client app's local encrypted state.

When a license or product policy requires machine binding, clients that can obtain a stable host identity must additionally send `machine_fingerprint` during activation, verification, and deactivation. The raw OS identifier must not be sent or persisted. This is a server-enforced binding signal, not hardware attestation; clients must fail closed if a bound installation cannot provide or match its fingerprint.

### Platform business claims

The multi-tenant MarketLens Platform uses `POST /licenses/redeem` to link an issued activation code to one external MarketLens business UUID. The request requires `activation_code` and `business_uuid`; `product_code` may be supplied and must match the authenticated API client's product. The operation is idempotent for the same business, rejects a different business claiming the same license, and rejects a second license claim for the same product/business pair. It does not create a `LicenseActivation` or consume `max_activations`; Business Server installations continue to use the device activation flow separately.

### Platform installation grants

After a business claim, the MarketLens Platform uses `POST /licenses/installation-grants` with its product-level API credential to issue a grant bound to `license_uuid`, `business_uuid`, `branch_uuid`, `device_uuid`, and a stable `installation_uuid`. Each issued grant reserves one `max_server_installations` slot. An identical retry returns the same grant and credential; changing any bound identity is rejected. `POST /licenses/installation-grants/{grant_uuid}/rotate` invalidates and replaces the bearer credential, while `POST /licenses/installation-grants/{grant_uuid}/revoke` invalidates it and releases the reserved slot. KernelBridge stores a credential hash and an encrypted copy for idempotent delivery; the plaintext credential is returned only by issue/rotation responses and must be stored by the Platform only long enough to deliver it to the Server.

The current `KernelBridgeLicenseClient` still uses `license_key` on its device activation/verification endpoints and does not yet consume installation-grant credentials. Do not pass the owner's master activation code to a Business Server or configure this grant as a product-wide token. The grant-authenticated Server activation and verification client flow is a required follow-up before these grants are used for production enforcement.

## Local State

Each client stores exactly one local state record per product code. The storage backend is framework-specific, but the logical fields are shared:

| Field | Required | Notes |
| --- | --- | --- |
| `product_code` | yes | Uppercase product code |
| `installation_identifier` | yes | Stable UUID or secure random identifier |
| `machine_fingerprint` | when host identity is supported or server policy requires it | Domain-separated hash; never the raw OS identifier |
| `license_identifier` | after activation | Public KernelBridge license UUID |
| `subscription_identifier` | after activation | Public KernelBridge subscription UUID |
| `activation_identifier` | after activation | Public activation UUID |
| `status` | yes | `unlicensed`, `active`, `trial`, `grace`, `invalid`, `deactivated`, etc. |
| `last_successful_verification_at` | after activation | Last successful central verification |
| `expires_at` | optional | License/subscription expiry |
| `offline_grace_expires_at` | after activation | Local offline fail-closed deadline |
| `license_key` | after activation | Must be encrypted at rest |
| `entitlements` | after activation | Feature and limit snapshot |
| `entitlement_signature` | yes where applicable | HMAC or server signature for local tamper detection |
| `config_fingerprint` | after activation | Signed fingerprint of sensitive client configuration |
| `last_error_code` | optional | Last explicit central rejection code |

Local state must not contain central database IDs. Use public UUIDs only.

When the API provides a server-signed entitlement snapshot, clients must verify it with a pinned public key and bind it to the product code, public license and subscription UUIDs, status, expiry, and exact entitlement map before using the snapshot offline. A key identifier must be present for verification-key rotation. The local cache signature and the server signature are separate protections; clients must not treat an unsigned entitlement map as server-authenticated.

## Activation Flow

1. Load or create local state for the product code.
2. Send `license_key`, `device_id`, and optional `device_name` to `/licenses/activate`.
3. Fetch subscription status with `/subscriptions/status`.
4. Fetch entitlements with `/entitlements`.
5. Store encrypted license key, identifiers, status, expiry, offline grace, entitlements, entitlement signature, and config fingerprint.
6. Return active state to the host app.

If KernelBridge returns `activation_limit_reached`, the client must show or expose this message:

```text
This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.
```

## Verification Flow

Normal user requests should use local state. They must not call KernelBridge on every page load.

Clients must verify:

- on application startup;
- on a scheduler/queue/timer at the configured interval;
- manually when the host app asks for forced verification.

If KernelBridge is temporarily unavailable and the local state is valid, the client may continue until the earlier of `expires_at` and `offline_grace_expires_at`. Explicit central rejection must mark local state invalid and fail closed.

## Fail-Closed Rules

Every client must deny protected access when:

- no license has been activated;
- license status is `invalid`, `expired`, `cancelled`, `suspended`, or `deactivated`;
- central verification explicitly rejects the license;
- local encrypted state cannot be decrypted;
- entitlement payload/signature is missing or tampered;
- offline grace has expired;
- sensitive configuration changed after activation.

## Activation Requirement Reasons

Clients must expose a reason code and user-facing message for activation gates.

| Reason | Message |
| --- | --- |
| `license_not_activated` | `Activate this application before continuing.` |
| `configuration_tampered` | `KernelBridge licensing configuration changed. Re-activation is required.` |
| `local_license_tampered` | `The local license cache was changed or cannot be trusted. Re-activation is required.` |
| `license_deactivated` | `This installation has been deactivated. Re-activation is required.` |
| `license_expired` | `This license has expired. Renew or activate a valid license.` |
| `license_revoked` | `This license was revoked by KernelBridge. Activate a valid license.` |
| `offline_grace_expired` | `Offline grace has expired. Reconnect to KernelBridge and verify the license.` |
| `activation_limit_reached` | `This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.` |
| default | `An active KernelBridge subscription is required.` |

## Configuration Fingerprint

On activation and successful verification, clients must store a signed fingerprint of sensitive configuration. At minimum:

- API URL;
- uppercase product code;
- API token or credential hash when available;
- application encryption key or stable app installation scope;
- local cache signing key;
- any framework-specific extra fingerprint values.

If the fingerprint changes later, protected access must fail with `configuration_tampered` and the host app must require re-activation.

## Route and Page Protection

Laravel clients may provide automatic web-route protection and route middleware.

TypeScript, Spring Boot, and Django clients should expose both:

- a programmatic guard returning `{ allowed, reason, message }` or equivalent;
- framework middleware/filter/decorator support where the framework provides a request pipeline.

Expected behavior:

- browser/web requests redirect to the activation UI when appropriate;
- API/JSON requests return `402 Payment Required` with an error reason/message;
- activation routes/pages must always be reachable;
- configured public routes such as login/password reset should be excluded when necessary.

## Feature and Limit Checks

Clients must support:

- active license check;
- feature check by key;
- limit check by key and required quantity.

Feature and limit checks must read the local signed entitlement snapshot. Host applications should repeat checks inside sensitive business operations, not only in UI visibility.

## Renewal Notices

Clients must expose non-blocking renewal notices when an active license is due within about 3, 2, or 1 month.

Required API shape:

```text
level: 3_months | 2_months | 1_month
message: Your KernelBridge license expires in about <n> month(s) on <date>. Renew to avoid service interruption.
expires_at: <date/time>
```

Renewal notices must not block access while the license remains active and valid.

## Security Requirements

- Never expose API tokens, license keys, encrypted state, or raw entitlement payloads to untrusted renderers, browsers, logs, tickets, screenshots, or diagnostics.
- Encrypt local state before writing it to disk or a database.
- Sign local entitlement snapshots and sensitive config fingerprints.
- Use framework-native secure configuration APIs and background schedulers.
- Keep credentials product-scoped and environment-scoped.
- Prefer revocable installation credentials for distributed desktop applications.

## Test Matrix

Every client package must include tests for:

- activation stores encrypted local state and uses correct API headers;
- recent verification uses cache without an API request;
- temporary outage uses signed offline grace;
- offline grace expiry fails closed;
- explicit central rejection invalidates local access;
- deactivation invalidates local access;
- local entitlement tamper fails closed;
- a copied cache on a different machine fails closed when machine binding is enabled;
- sensitive config tamper requires re-activation;
- protected route/page redirects or returns `402` before activation;
- active, feature, and limit guards use cached decisions;
- renewal notice levels for 3, 2, and 1 month windows;
- activation limit errors show the KernelBridge device-limit message.

## Spring Boot Starter Requirements

The Spring Boot package provides:

- auto-configuration through `@ConfigurationProperties`;
- `KernelBridgeLicenseClient` HTTP client;
- encrypted/signed local state repository;
- servlet filter and optional Spring Security filter;
- annotations such as `@RequiresKernelBridgeLicense`, `@RequiresKernelBridgeFeature`, and `@RequiresKernelBridgeLimit`;
- scheduled verification;
- activation/deactivation services;
- renewal notice service;
- starter service tests with a fake KernelBridge client.

## Django Package Requirements

The Django package provides:

- Django app config;
- settings namespace such as `KERNELBRIDGE_LICENSING`;
- encrypted/signed local state model;
- middleware for protected pages and API responses;
- decorators such as `@requires_kernelbridge_license`, `@requires_kernelbridge_feature`, and `@requires_kernelbridge_limit`;
- management commands for activate, verify, and deactivate;
- scheduled verification hook compatible with Celery/cron through `kernelbridge_verify`;
- renewal notice context helper;
- Django service and middleware tests with a fake KernelBridge client.



