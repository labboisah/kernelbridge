# Licensing SDK Repository and Improvement Plan

- **Status:** Planned; implementation not started
- **Repository layout:** One SDK monorepo containing both runtime packages and the shared protocol contract
- **Canonical repository:** [labboisah/kernelbridge-license](https://github.com/labboisah/kernelbridge-license)
- **Authority:** KernelBridge owns licensing policy and the API contract; the SDK repository owns runtime client implementations and activation UX.

## 1. Goal

A product team registers its product and runtime in KernelBridge and installs the supported licensing package. The package supplies product identity and public API defaults from package configuration/manifest, the activation experience, and the standard licensing lifecycle. No licensing-specific `.env` variables, generated environment file, or manual endpoint/key setup is required for activation in any supported mode. Platform-account server credentials, when required, are provisioned and stored as server-side integration secrets and are never part of customer activation setup. Product teams focus on product features rather than maintaining a second implementation of licensing.

The packages must not choose a product's licensing mode, issue activation codes, or replace KernelBridge policy. They implement the published KernelBridge protocol and consume signed policy/entitlement responses.

The activation screen is a required package feature, not an optional product-built form. It runs in the product and is visibly KernelBridge-branded, using the official KernelBridge logo, colors, typography, and visual design. Product branding cannot replace or white-label KernelBridge identity on licensing and activation screens. The screen presents the activation journey permitted by KernelBridge's current mode: an installation-code flow for Direct, a product-platform sign-in/link flow for Platform Account, or both only when KernelBridge explicitly configures Hybrid. The platform-account screen integrates with the product's existing authentication and trusted backend broker; it never embeds backend credentials or treats sign-in alone as license approval.

## 2. Repository and package boundaries

### Shared SDK repository

- Use `https://github.com/labboisah/kernelbridge-license` as the dedicated licensing SDK monorepo, separate from the KernelBridge application repository. It contains both the Laravel and TypeScript SDKs, shared protocol artifacts, conformance tests, and common release documentation.
- Use a clear monorepo structure, for example:
  - `packages/laravel/` — Composer package source, tests, and package documentation.
  - `packages/typescript/` — npm package source, tests, and package documentation.
  - `protocol/` — canonical protocol schema/documentation, versioned request/response fixtures, shared error codes, and signature test vectors.
  - `tools/` — only if needed for fixture validation, generation, or coordinated release automation.
- The KernelBridge monorepo currently contains copies under `packages/kernelbridge/licensing-client-laravel` and `packages/kernelbridge/licensing-client-typescript`. Treat these as migration sources, not long-term competing package implementations, and remove or replace them only after downstream consumers can install reproducible tagged releases from the SDK repository.
- Migrate the existing Laravel package source and TypeScript SDK/protocol sources into this canonical repository while preserving source history where practical. The existing `labboisah/laravel-licensing-package` repository is a migration source, not a competing long-term canonical home. Preserve current Composer/npm package names and delivery compatibility during the transition.
- Preserve package names and public exports (`kernelbridge/licensing-client-laravel`, `KernelBridge\LicensingClient`, and `@kernelbridge/licensing-client-typescript`) unless a separately versioned breaking change is approved.
- Share protocol definitions and conformance fixtures in-repository to prevent contract drift, while keeping runtime-specific implementations separate. A shared source does not mean Laravel must depend on TypeScript or vice versa.
- Keep independent semantic versions and changelogs for each package. Use coordinated contract/fixture updates and CI to test compatibility across both implementations; release either package independently when its own changes are ready.
- Continue Composer-compatible Git tags and the current npm distribution approach initially where practical. Decide whether Composer/npm publication workflows can live together or need narrowly scoped package release jobs. Make no registry or public-visibility change without approval.

### KernelBridge remains authoritative for

- Product registration, product code, supported runtimes, effective licensing mode, policy revision, and transition windows.
- Activation-code issuance/replacement, seat reservation, exchange, installation verification, revocation, and signed entitlements.
- API contract documentation, compatibility policy, and server-side protocol conformance tests.

The SDK repository must not contain product machine credentials, entitlement signing private keys, or code that lets a product/customer choose its effective licensing mode.

## 3. Shared API contract

Keep KernelBridge as the authoritative server contract and the SDK monorepo's `protocol/` directory as its consumer-facing, versioned specification and conformance-fixture set. Changes to the server contract must update the SDK protocol artifacts and test both SDK implementations in the same change or coordinated changes. Do not copy independently edited protocol definitions into each package.

### Current unified installation exchange

- `POST /api/v1/installations/activate` accepts `activation_code`, `product_code`, stable `installation_uuid`, and optional minimal `device_name`.
- It does not require the product machine API token.
- It returns the installation-scoped bearer credential, public identifiers, status/expiry metadata, and `signed_entitlements` containing `payload_json`, `signature`, `algorithm`, and `key_id`.
- `POST /api/v1/installations/verify` accepts that bearer as its Authorization credential and returns a fresh signed snapshot.
- The signed payload is Ed25519 over the exact UTF-8 bytes in `payload_json`. Verify before parsing or trusting claims. The current payload uses `license_status` and includes `installation_uuid`, `entitlement_revision`, and entitlements.
- Same-install activation retry must use the same persistent installation UUID and safely recover the same credential if the exchange response was lost. The activation code is discarded only after a successful durable state write.

Before SDK rollout, complete the contract required by all packages: unified installation deactivate/revoke and credential recovery/rotation; stable error codes; public Ed25519 verification key distribution and key rotation; status/expiry semantics; and a defined protocol/capability version. Do not have a package fake missing server capabilities.

## 4. Laravel package improvement plan

### Improvement objectives

- Make the Laravel package a complete, secure licensing integration for Laravel products: required mode-aware activation screen, durable installation identity and credential storage, signed-entitlement verification, middleware/feature access, operational status, and supported recovery/deactivation.
- Reduce product-specific licensing code to documented configuration and narrow hooks. Keep KernelBridge policy and account/product ownership decisions outside the package.
- Maintain a safe upgrade path from the current reusable-key implementation; package upgrades must not silently invalidate existing installations or convert legacy credentials into installation credentials.

### Delivery sequence

1. **Baseline and compatibility inventory:** Record current package APIs, schema, routes, config, middleware, supported Laravel/PHP versions, test coverage, and MediFlow customizations. Establish fixtures for current legacy behavior and capture an existing-state upgrade test.
2. **Protocol client boundary:** Separate activation-code exchange (no product machine token) from installation-bearer verification and legacy machine-token calls. Add typed protocol/error/credential models and prevent secrets from appearing in logs or user-visible exceptions.
3. **Durable state and migration:** Introduce versioned state for credential kind, stable installation UUID, encrypted installation credential, verified signed snapshot, key ID, revision, and timestamps. Add reversible/non-destructive migration from legacy rows and document backup/rollback behavior.
4. **Activation and lifecycle UX:** Implement the required package-owned, mode-aware activation screen, direct code entry, and platform-account sign-in/link status integration; provide validation, throttling, retry-safe exchange, status/renewal behavior, and deactivation/recovery once KernelBridge endpoints exist. Integrate with product authentication and trusted backend brokers through stable hooks rather than copied controllers.
5. **Cryptographic entitlement verification:** Verify exact `payload_json` bytes using sodium Ed25519 and trusted key IDs; validate schema, product and installation binding, status, validity period, and entitlement revision before persistence or authorization. Add key-rotation and invalid-signature tests.
6. **Laravel integration and configuration:** Provide documented service-provider bindings, config publishing, middleware/feature/limit APIs, queue/scheduler integration where applicable, and framework-compatible defaults. Read configuration through Laravel's config repository at runtime; avoid direct `env()` calls in services.
7. **MediFlow pilot and compatibility release:** Adapt MediFlow's onboarding proof to stable installation identity, preserve its entitlement/module mapping, migrate a staging installation, and verify legacy installs continue through the announced overlap window. Release an opt-in/compatible package before any product policy switch.
8. **Stable release and retirement:** Test clean installation from a tagged artifact, publish upgrade and rollback instructions, observe migration/error telemetry without secret values, and retire legacy paths only after KernelBridge approves per-product migration completion.

### Laravel-specific acceptance tests

- Fresh installation-code activation, wrong product/code, expiry, revoked/replayed code, rate limits, lost response retry, and persistence failure recovery.
- No product machine token required for direct code exchange; installation credential is used only for installation-scoped calls and is never sent to browser clients.
- Legacy stored key state remains readable during the compatibility window and is never silently reinterpreted as a new credential.
- Exact-byte Ed25519 verification rejects altered payloads, wrong key IDs, invalid signatures, mismatched product/installation IDs, expired snapshots, and malformed claims.
- Laravel encryption protects credentials at rest; missing/rotated `APP_KEY` produces an explicit recoverable operational error rather than silent deactivation.
- Package middleware and entitlement helpers consistently deny expired, revoked, invalid, or unavailable states according to documented offline policy.
- MediFlow's first-admin onboarding, redirects, entitlement mapping, and existing installations pass integration tests with no copied licensing HTTP client.

### Package-owned experience

- Ship an activation screen as a required package feature and mount it within the product. Product teams configure documented branding, routes, and extension hooks rather than building or copying their own licensing screen.
- Apply KernelBridge's official brand to all activation, licensing status, and recovery screens: use the official KernelBridge logo, brand colors, typography, and visual style. The licensing package owns these assets and presentation defaults. Product branding may appear only as contextual product identity (for example, product name) and must not replace, obscure, recolor, or white-label KernelBridge branding.
- Render the activation flow according to KernelBridge's effective product mode: one-time installation-code entry in Direct; product-platform authentication/linking with pending/approved/rejected states in Platform Account; both only in KernelBridge-configured Hybrid. Never expose a customer-controlled mode switch.
- In Platform Account mode, delegate authentication to the product platform and use a trusted product backend to request customer-approved links and installation-bound codes. Keep backend machine credentials server-side; product sign-in by itself does not activate a KernelBridge license.
- Replace the default reusable-license-key form with a one-time installation-code form. Keep any legacy flow available only through an explicit compatibility path while old product installations migrate.
- Own activation routes, form request validation, CSRF/throttle handling, safe error messages, activation status page, retry behavior, verification job, cache, and deactivation UX.
- Provide extension points for contextual product identification, product links, post-activation redirect, middleware selection, and feature/limit mappings. Do not expose hooks that replace or suppress KernelBridge identity, logo, or brand styling. Products should not copy or fork package controllers or call licensing endpoints directly.
- Ensure activation code, bearer credential, and full signed payload are never flashed to sessions, logs, analytics, browser output, or exception messages.

### Client and state changes

- Add a typed credential kind and encrypted installation credential to the package state schema. Do not overload `encrypted_license_key` to contain a different kind of secret.
- Add a versioned state migration that preserves an existing license-key state and can continue legacy verification until the product is explicitly migrated. Avoid destructive reset or forced mass activation on package upgrade.
- Persist installation UUID before the first request and retain it across request retries, deployment restarts, and normal app updates.
- Verify KernelBridge Ed25519 snapshots using PHP sodium and a configured public-key map keyed by `key_id`. Preserve the exact signed JSON bytes; validate product, installation, license, subscription, status, expiry, entitlement revision, and entitlements.
- Keep the package's local cache-integrity MAC separate from KernelBridge's server signature. The public verification key is not a secret; private signing keys remain only in KernelBridge.
- Preserve Laravel encrypted-at-rest credential storage and document `APP_KEY` backup/rotation implications. Use installation credential authentication for verify/revoke; do not require a product machine token for direct installation exchange.
- Make public defaults (product code, API URL, timeout, verification interval, UI) package-config/manifest driven, with no required licensing environment variables. Platform-account broker credentials are a separate server-side integration secret, provisioned and stored through secure integration tooling rather than shipped in package config or requested from customers in `.env`.
- Remove runtime calls to `env()` from package services and avoid config fingerprints that force reactivation merely because a legacy machine-token setting is absent in the new protocol.

### MediFlow integration

- Upgrade the package in MediFlow and remove its license-key-specific activation controller behavior where package extension points suffice.
- Adapt installation onboarding's short-lived first-admin proof to the stable installation identity, not the legacy `activation_identifier`.
- Keep MediFlow-specific entitlement-to-module mapping in a small adapter/configuration layer; the package owns verification and signed-state validation.
- Preserve the current server URL selection safeguards only if still needed for deployment discovery; do not let an untrusted request permanently change the trusted API endpoint or bypass deployment policy.
- Test upgrades against existing persisted package state and against MediFlow's customized activation, setup redirect, administrator, and module enforcement flows.

## 5. TypeScript SDK improvement plan

### Protocol and state changes

- Add `exchangeActivationCode(code, installationUuid, ...)` and installation bearer verification methods using the new unauthenticated-code-exchange / installation-bearer protocol, not the legacy reusable-key routes.
- Make token-provider semantics explicit: for the new protocol it supplies the stored installation credential to verification/lifecycle calls; code exchange does not use a bundled product machine token.
- Add a new local-state schema version containing credential kind, installation credential, stable installation UUID, signed snapshot, key ID, entitlement revision, and last server verification metadata. Migrate v1/v2 legacy key state without silently treating it as the new credential.
- Implement Ed25519 verification in Node using built-in crypto or a reviewed implementation; support a public-key map keyed by `key_id`. Preserve exact payload bytes and validate all signed claims and entitlements before caching.
- Retain Electron `safeStorage` and SQLite adapters, but make vault availability an explicit activation prerequisite. Keep all bearer credentials in the trusted main process and out of renderer IPC, renderer storage, logs, and diagnostics.
- Provide a package-owned activation experience for supported desktop frameworks behind a narrow renderer-to-main interface. It must support KernelBridge-mode-driven code entry or product-platform sign-in/link status without exposing installation or backend credentials to the renderer. Keep protocol and credential operations in the main-process SDK and avoid making a UI framework a mandatory dependency of the headless core.
- Document adapters for OS vault availability, first-run identity, device replacement, restore/clone behavior, app updates, uninstall, and multi-user installations.
- Keep a Node backend use case supported only where its trust boundary and secure state storage are documented; do not present browser-only code as a safe credential store.

### Desktop product onboarding

- Document a minimal host setup: package install, package-provided product identity/API defaults, trusted main-process storage, package-provided activation screen, `hasFeature`/limit guards, and lifecycle start/stop. No licensing `.env` setup is required.
- Provide a sample/reference integration and conformance tests without requiring product teams to write HTTP clients, signature checks, durable credential tables, or activation forms. Platform-account products supply only their existing authentication and trusted backend broker integration hooks.
- Update README guidance that currently describes RSA-SHA256 signed claims and says KernelBridge does not yet provide a signed-claims contract; those statements do not match the current Ed25519 exchange response.

## 6. Cross-package engineering standards

- Keep independent semantic versions and changelogs for Laravel and TypeScript packages inside the shared repository; a release in one package must not require publishing the other.
- Declare the supported KernelBridge API protocol range and minimum server capability in each package. Use explicit capability negotiation or documented server minimums, not customer-controlled licensing-mode flags.
- Define support matrices: Laravel/PHP/framework versions for the Laravel package; Node/Electron/OS and storage-vault versions for TypeScript.
- Use the same fixture scenarios for code exchange, lost response retry, verification, Ed25519 signature failures/key rotation, expiry, revocation, outage/offline grace, state migration, and safe error redaction.
- Run static analysis, formatting, unit/integration tests, package builds, dependency/security review, and install-from-tag smoke tests in the shared repository's CI, with package-specific jobs and release gates.
- Pin dependencies and review native dependency/platform support, especially TypeScript's optional `better-sqlite3` adapter and Electron native rebuild requirements.
- Use immutable release tags, signed/protected release workflows where available, generated release notes, and tested rollback guidance.
- Add a migration guide that explains compatibility, state backup, rollback limits, and when a KernelBridge product policy can be changed.

## 7. Repository migration sequence

1. **Canonical-source audit:** Verify both package copies in KernelBridge, the Laravel VCS repository, package ownership, unique commits/tags, consumers, and release processes as migration sources for the already-selected `labboisah/kernelbridge-license` monorepo. Do not overwrite or force-push repositories.
2. **Protocol gate:** Finalize the server API contract, Ed25519 key distribution/rotation, installation lifecycle endpoints, error codes, state semantics, and conformance fixtures.
3. **SDK monorepo setup:** Establish the shared directory structure, package-specific manifests, shared protocol artifacts, test/fixture conventions, CI, branch protections, and release permissions. Preserve source history where practical and retain package names.
4. **Laravel package migration:** Import the Laravel package and its tests/history, reconcile the external VCS source and current monorepo copy, and verify Composer installation from a clean tagged artifact. Do not copy secrets or deployment-specific values.
5. **TypeScript SDK migration:** Import TypeScript source/history into the same repository, preserve package exports, and verify npm/package installation and builds independently.
6. **Compatibility releases:** Publish Laravel and TypeScript releases that can read existing state and keep legacy activation alive while adding the new protocol behind a compatible path. Do not yet switch MediFlow or other products to strict Direct mode.
7. **Integration pilots:** Upgrade MediFlow staging with the Laravel SDK; separately integrate the TypeScript SDK into an identified desktop product. Validate installation identity, credential storage, outage behavior, and rollback.
8. **Product onboarding:** Update KernelBridge product registration/admin workflow to show supported runtime, recommended package/release, and package install instructions plus any non-secret package-manifest configuration. Validate a fresh sample product can activate using the package without custom licensing code or licensing `.env` setup.
9. **Controlled rollout and retirement:** Upgrade product packages first, migrate installations under KernelBridge control, monitor results, then switch each product's new-activation policy. Deprecate legacy reusable-key activation only after usage evidence and migration support meet release gates.

## 8. Acceptance criteria

- Both SDKs and the shared protocol artifacts have a canonical home in one SDK repository; each package can be independently installed from a tagged release in a clean product application.
- A fresh supported product installation requires no licensing `.env` entries, generated environment file, or manual API endpoint/machine-token setup; public product identity/defaults come from the package or generated manifest, while runtime credentials remain in protected package state.
- A new Direct-mode product can be registered in KernelBridge, install its supported package, enter one installation code in the package-owned activation screen, and become licensed without a product machine token or custom licensing client.
- The package supplies the in-product activation screen for Direct and Platform Account modes; the screen follows KernelBridge policy, and platform-account activation integrates the product's existing authentication and trusted backend without exposing backend credentials or bypassing owner approval.
- Laravel never exposes the installation credential to browser code; TypeScript never exposes it to the renderer.
- Both clients verify the exact Ed25519-signed payload and reject malformed, mismatched, stale, or invalid signatures.
- Existing Laravel and TypeScript local state remains recoverable through documented migration and rollback paths.
- Same-install retry after a simulated lost exchange response returns usable state without consuming another seat; another installation cannot redeem the same code.
- The package activation/status UX, error reasons, entitlement cache, offline policy, and revocation behavior are consistent with the server contract.
- Product configuration is limited to documented public identity and integration hooks; products do not set licensing mode or ship KernelBridge product API credentials.
- CI validates protocol fixtures and both package builds/tests from the shared repository while each tagged package artifact remains independently installable.

## 9. Open decisions to resolve during implementation

- Composer/npm package publication and release access models for the shared repository.
- Supported desktop UI framework adapters and the distribution boundary between the required KernelBridge-branded package-provided activation experience and the headless protocol core.
- Protocol version/capability negotiation and the schedule for removing legacy state/activation support.
- Final activation code and installation lifecycle API behavior, including credential recovery/rotation and deactivation.
