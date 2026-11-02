# Unified Licensing and Installation Activation Implementation Plan

- **Status:** In implementation
**Scope:** KernelBridge licensing APIs and customer portal, shared licensing SDKs, and product applications such as MediFlow.

## 1. Objective

Provide one consistent installation activation experience across products:

- A product installation is activated using one installation-specific activation code.
- The code is exchanged once for a revocable installation credential and a signed entitlement snapshot.
- Licensing requires no customer-managed `.env` variables, generated environment file, machine token, or manual endpoint setup. The package uses product-specific package configuration and KernelBridge's public endpoint defaults; per-install credentials are stored in the package's protected runtime state.
- Direct-license customers and customers using a product's own platform account can both create and manage installations.
- The installed licensing package supplies the product's activation screen and mode-appropriate activation journey; the product should not build a separate licensing screen.
- KernelBridge alone determines each product's licensing model and whether or when it migrates to another model.
- KernelBridge remains authoritative for subscriptions, licensing policy, activation limits, entitlements, model transitions, and revocation.
- KernelBridge can migrate a product's licensing model through an explicit, reversible process without invalidating existing installations or requiring mass reactivation.
- A new product can be onboarded by registering it and its runtime in KernelBridge, then installing the matching licensing SDK; product teams should not need to implement licensing screens, API calls, credential storage, or entitlement checks themselves.

This plan does not claim that a customer-controlled host can be made tamper-proof. A host administrator can change the product code, database, or runtime. The design makes activation credentials scoped, short-lived, and revocable and makes accidental configuration drift less likely.

## 2. Terminology

- **Product licensing model:** The customer journey and ownership model selected and managed exclusively by KernelBridge for a product, such as direct licensing or platform-account licensing. Product applications and customers cannot change this policy.
- **Product runtime type:** The technical application and trusted execution boundary, such as a hosted Laravel web app, a desktop application with a trusted TypeScript main process, or a platform-backed product. Runtime type selects the compatible SDK and storage/integration design; it does not select or override licensing model.
- **Product platform:** The product-owned application and its customer accounts, tenants, and operational data. KernelBridge integrates with these platforms for licensing but does not become their identity provider or operational system of record by default.
- **Subscription/license:** KernelBridge's commercial entitlement for a customer and product.
- **Installation:** One running product deployment with its own stable installation UUID.
- **Activation code:** A high-entropy, single-use capability used to bind one installation to a license. It is not the recurring API credential.
- **Installation credential:** A revocable, installation-scoped secret issued after code redemption and used for verification and management.
- **Business account claim:** The link between a KernelBridge license and an authenticated account in a product's own platform. It is not itself a device activation.
- **Licensing SDK:** A versioned runtime package that owns the activation and licensing lifecycle for its supported runtime. Product applications configure and integrate its extension points, but do not implement a separate licensing protocol.
- **Package-provided activation screen:** The activation UI delivered by the runtime-appropriate licensing package and embedded in or mounted by the product. It presents only the flow allowed by KernelBridge's current product licensing mode; it is not a customer-selectable licensing-policy switch.

## 2.1 Initial implementation decisions

The following policy defaults were confirmed before implementation:

- An unredeemed activation code expires 24 hours after issuance.
- Issuing a code reserves one installation seat. Expired or revoked unused codes release their reservation; redemption converts that reservation into an installation without taking a second seat.
- Web/server and desktop installations draw from one shared per-license installation pool. KernelBridge resolves the effective limit centrally; legacy direct activation and server-grant limits must be reconciled without silently reducing an existing customer's allowance.
- Existing license keys, activations, and grants remain supported during migration; product licensing-model changes and migration execution remain KernelBridge-controlled.

## 3. Current state and gaps

1. KernelBridge has a code-based installation exchange at `/api/v1/installations/activate`, installation-bearer verification at `/api/v1/installations/verify`, signed Ed25519 entitlement snapshots, same-install retry, and a shared allocator for legacy activations, grants, installations, and reserved codes.
2. Product licensing mode, runtime type, policy revision, KernelBridge admin controls, and audit history are implemented. Existing installations remain compatible and new issuance is gated by the centrally configured product mode.
3. The unified lifecycle now supports installation-credential self-deactivation and customer-portal revocation. Both release the seat; portal revocation is ownership-checked and audited. Credential rotation/recovery, transfer, and admin recovery are not implemented.
4. A new platform-link path uses a product-scoped `licensing:account-link` credential, records the backend's tenant-membership assertion as a pending request, and requires the KernelBridge license owner to approve in the customer portal before installation codes can be issued. The primary customer user can unlink an approved account; linked installations and unused codes are revoked immediately and their seats released. Legacy platform claims still accept externally supplied business identifiers and are not trusted as proof for this new path.
5. Per-installation legacy-to-unified migration mappings/status, resumable policy-transition operations, and trusted deduplication of overlapping legacy activation/grant records are not implemented.
6. Entitlement snapshots now carry a monotonic entitlement revision, content digest, product licensing mode, policy revision, and policy effective timestamp. Revisions advance on entitlement edits and scheduled effective-time boundaries.
7. Activation-code and installation-credential HMACs support dedicated key identifiers and overlapping keys through KernelBridge server configuration; legacy rows without key IDs remain verifiable while their original application key is retained. Encrypted credential recovery and activation retry still depend on Laravel `APP_KEY`; encryption-key rotation/recovery remains unresolved.
8. The shipped unified endpoints use API v1 paths. The protocol needs a frozen, versioned schema and shared conformance fixtures before SDK releases; do not silently change the existing paths to the earlier v2 proposal.

The implementation must treat the direct flow as a supported legacy path until each product installation has migrated. Do not invalidate existing license keys or grants as part of the rollout.

## 4. Target behavior

### 4.1 Package-provided activation experience

The installed Laravel or TypeScript licensing package must provide the activation screen and own its standard licensing interactions inside the product. The screen must visibly use KernelBridge's official logo, colors, typography, and visual design. The package owns and maintains these brand assets and defaults; product branding may provide contextual product name or links but must not replace, obscure, recolor, or white-label KernelBridge identity. The package may expose routing and UI-framework hooks, but activation UI is not optional and product teams must not need to recreate the activation screen or licensing protocol.

KernelBridge's effective product licensing mode determines the experience:

- **Direct mode:** The screen accepts the installation-specific KernelBridge activation code and exchanges it through the common code-redemption flow.
- **Platform-account mode:** The screen starts the product's platform-account journey using the product's existing authentication, then shows link/approval and activation status. The trusted product backend performs the scoped KernelBridge link request and installation-code exchange; its machine credential remains server-side and is never placed in the package UI, browser, or desktop bundle.
- **Hybrid mode:** The package may present both supported journeys only when KernelBridge has explicitly configured `hybrid` as the effective mode. The UI must not let customers switch the product's policy or expose a flow that KernelBridge has disabled.

The package must handle pending, approved, rejected, inactive, and failed states with actionable messages. It must not bypass owner approval for platform links, invent or request product backend credentials from end users, or imply that successful platform sign-in alone activates a KernelBridge license.

### 4.2 Common first activation

1. The package displays its mode-appropriate activation screen inside the product. Direct mode uses an installation-code field; platform-account mode starts the product's platform sign-in/broker flow; hybrid mode may offer both configured paths.
2. The runtime-appropriate SDK supplies its product code and public KernelBridge API URL from package/product configuration. These values identify the service; they are not secrets.
3. The SDK creates or loads a stable installation UUID in storage appropriate to the runtime. In direct mode, it submits the installation code, product code, and limited device metadata. In platform-account mode, the trusted product backend obtains an installation-bound code after account-link approval, and the package presents/consumes that code for the same installation.
4. KernelBridge validates and consumes the code atomically, binds it to the installation and license, checks the shared installation limit, and issues a revocable installation credential.
5. KernelBridge returns public license/subscription/installation identifiers, status, expiry, entitlement revision, signed entitlements, and the installation credential.
6. The client stores the credential and verified state using the host runtime's secure storage. Web and desktop SDKs use different storage adapters but the same logical state and security requirements. It never writes credentials to a generated `.env` file.
7. Subsequent verification uses the installation credential, not the activation code. Deactivation/revocation invalidates the credential centrally.

The activation code is a one-time bootstrap credential, not a long-lived password. It must not be logged, placed in URLs, retained in browser history, or included in analytics.

### 4.3 Runtime-specific client behavior

The activation API and KernelBridge product policy are runtime-neutral. Client implementations must account for each runtime's trust boundary:

- **Laravel/web app:** The PHP application server is the trusted client. Keep installation credentials server-side in protected application storage; never expose them to browser JavaScript or user-facing responses. The Laravel licensing package owns the KernelBridge-branded activation routes/screen, local state, scheduled verification, and entitlement middleware. Product applications may customize product links and post-activation redirects, but cannot replace or white-label KernelBridge branding and should not need a custom activation controller or licensing API client. Do not treat a user's browser, cookie, or submitted installation UUID as a server credential.
- **Desktop app with TypeScript SDK:** Activation and verification run in a trusted desktop main process or another protected local service. The SDK owns the required KernelBridge-branded activation experience, delivered through UI adapters/components for supported desktop frameworks; renderer/UI code calls a narrow local interface and never receives the long-lived installation credential. Store credentials using the OS-backed secure vault/keychain where available, with encrypted durable state for the installation ID and entitlement snapshot. Do not bundle platform account/API credentials or rely on renderer-controlled files as the trust boundary.
- **Platform-backed product:** The platform server owns account-link integration credentials and validates tenant ownership. Its per-installation provisioning channel delivers an installation code to the target web or desktop installation without exposing reusable platform credentials.

The shared SDK contract must define storage, rotation, backup/restore, uninstall/reinstall, and machine/installation identity behavior per runtime. A desktop installation UUID should survive normal application updates but must not be copied as an active installation identity to a second device. A server-backed web deployment's installation identity describes that server installation; individual browser users are not separate licensed devices unless KernelBridge's product policy explicitly defines them as seats. A browser-only frontend is not a trusted secret store: where no trusted server or desktop main process exists, activation must use a supported delegated flow and must not embed a reusable credential in JavaScript.

### 4.4 Product onboarding contract

For each new product, the expected onboarding path is:

1. A KernelBridge administrator registers the product, its stable product code, supported runtime(s), licensing model, and policy revision in KernelBridge.
2. KernelBridge identifies the compatible SDK/package release and provides package installation instructions and non-secret product configuration.
3. The product team installs the runtime-appropriate package and supplies only product-specific integration points, such as route protection, feature-to-entitlement mappings, contextual product identity, and the post-activation destination. KernelBridge activation screens retain KernelBridge branding and cannot be white-labeled.
4. The package supplies the activation screen and recurring licensing lifecycle. Customers enter the KernelBridge installation code there; it is not a reusable product license key or a product-platform credential.
5. The product can use licensing without product-authored licensing controllers, HTTP clients, credential tables, polling jobs, activation forms, or hand-written entitlement signature verification.

KernelBridge registration is the control-plane setup for the product; installing an SDK does not register a product or let that product change its licensing model. Direct-mode products need only public package configuration such as product code and KernelBridge API base URL for activation and installation verification. Product machine credentials are not distributed to clients. A platform-account product may additionally require a separately provisioned, scoped server-to-server integration, but that is a KernelBridge-approved platform integration—not a prerequisite for direct installation-code activation.

The package must expose clear extension points and safe defaults without forcing the product to fork or copy SDK licensing code. Unsupported runtime or UI frameworks may require a thin adapter, but that adapter must delegate protocol, storage, signature verification, retries, and status handling to the SDK rather than reimplement those responsibilities.

### 4.5 Direct-license customer

The customer purchases or receives a KernelBridge subscription. KernelBridge generates an installation code for each authorized installation. The customer enters that code in the product being activated. KernelBridge's customer portal shows installation status, usage, revoke/transfer actions, and replacement-code issuance.

### 4.6 Product-platform account customer

1. The customer signs in to the product's own platform using its normal account authentication.
2. The trusted product backend verifies that the signed-in user is authorized to manage the tenant, then submits a link request for the public license UUID and tenant UUID using its KernelBridge-managed `licensing:account-link` credential. This is a backend assertion, not proof supplied by a browser.
3. KernelBridge creates a pending link. The KernelBridge customer/license owner reviews and approves or rejects the request in the customer portal. A pending or rejected link cannot request installation codes.
4. After approval, the product backend requests a short-lived activation code bound to the approved link and exact installation UUID. The code is shown only in that target installation and is redeemed through the common first-activation flow.
5. The primary customer/license owner can unlink an approved account in the customer portal. Unlinking revokes the link, its active installations, and its unused activation codes in one transaction; installation credentials are invalidated and their seats are released immediately. A replacement link must be requested and approved again. Account-link transfer is not implemented.

The platform's server-to-server credential stays in server-side secret storage. It is never included in the product package, product `.env` download, browser response, desktop bundle, or installation credential. Customer approval protects the KernelBridge license owner; the backend credential attests only that the registered product server made the request.

### 4.7 KernelBridge control plane and product-platform boundary

KernelBridge should centrally manage the product registry and licensing control plane. For each product, KernelBridge is authoritative for its product identity, supported runtime/SDK integrations, licensing model, policy revisions, subscriptions/licenses, activation codes, installation/seat records, account-link authorization, entitlements, and model-migration lifecycle. Only KernelBridge can set or change licensing policy and initiate or execute a licensing-model migration.

The product platform remains authoritative for its own customer identity, authentication, business/tenant membership, roles, and operational data. KernelBridge should store only the minimum external identifiers and verified account-link state needed to associate a KernelBridge subscription with that product account. Account credentials and product-domain records should not be copied into KernelBridge. The product platform proves account ownership to KernelBridge through a scoped server-to-server integration; KernelBridge validates that integration and records the resulting license/account relationship.

This separation lets KernelBridge make licensing consistent across Laravel web applications and TypeScript desktop applications without forcing every product to move its user platform into KernelBridge. KernelBridge may provide a shared customer portal for KernelBridge billing, license, and installation management, while the product retains its own application login and business workflows. Any future central identity or shared account feature should be a separate, explicit product decision with its own security, privacy, and migration design—not an implicit consequence of licensing integration.

### 4.8 Offline and recurring operation

Clients verify on startup, at the configured interval, and when explicitly requested. They may use a locally verified, signed entitlement snapshot for the documented offline grace period. Explicit rejection, expiry, revocation, tampered state, or exhausted grace fails closed. Normal user requests must not call KernelBridge synchronously.

The client caches only what it needs: installation UUID, installation credential (encrypted/secure storage), public IDs, status/timestamps, entitlement revision and signed snapshot, and last explicit error. It does not need to retain the activation code after a successful exchange.

## 5. Product licensing model and safe changes

Add a centrally managed licensing policy to each KernelBridge product. Prefer an explicit typed field (for example `licensing_model`) over unstructured product metadata. KernelBridge administrators and authorized KernelBridge-controlled policy workflows are the only actors permitted to create or change this field. Product apps may read the effective policy but must not set, override, or infer it from local configuration. Customers may request a change through support or account channels, but the request is not a policy change until KernelBridge approves and executes it. Keep licensing model separate from product runtime type and SDK; changing a web app to desktop, or changing the SDK/runtime, must not implicitly change licensing policy. Initial values:

- `direct`: customer receives per-installation codes from KernelBridge.
- `platform_account`: a product platform account links to a KernelBridge license and brokers per-installation codes.
- `hybrid`: both customer journeys are allowed during onboarding or transition.

Keep the model separate from an installation's persisted activation method and credential type. KernelBridge's product-level model controls which new sales, UI, account-linking behavior, and issuance policy are allowed; it must not change the meaning of an already issued installation credential. Product applications consume signed/versioned effective policy from KernelBridge and use package configuration only for product identity, public endpoint defaults, presentation, and local integration—not to select the licensing model. Product identity can be set in the package's ordinary configuration file or generated package manifest; customers must not need to edit `.env` for licensing.

### Mode-change contract

1. Every mode change is initiated and authorized by KernelBridge, recorded as an audited KernelBridge administration action with old/new values, effective time, actor, and reason. A product application or customer cannot flip the mode through an API parameter, local config, environment variable, product-account admin page, or client release.
2. KernelBridge owns the transition period and decides whether to use `hybrid` behavior, its start/end times, and when old issuance paths stop. KernelBridge continues verifying and managing existing installations during the transition.
3. Existing direct activations and platform grants remain valid until their own expiry/revocation. Changing a product mode must never silently revoke or rebind them.
4. KernelBridge owns migration planning and execution, including the target mode, schedule, eligibility rules, batch sizes, customer communication, and completion criteria. Product applications provide migration-compatible SDK behavior and account ownership evidence where required, but cannot independently migrate their product.
5. Provide KernelBridge-operated migration operations for existing installations:
   - Direct to platform-account: KernelBridge approves the target policy, verifies/link-authorizes the account through the product's trusted server-to-server integration, associates each existing installation with the owned tenant, and retains or rotates its installation credential. Do not ask every device to enter a master license key.
   - Platform-account to direct: KernelBridge verifies current account/tenant authority, issues a one-time installation code or converts the existing installation credential in place, and unlinks only after the migration succeeds.
6. Make migration resumable and idempotent. KernelBridge tracks per-installation migration status, errors, and completion; it provides dry runs, progress reporting, retries, and rollback/forward-recovery controls.
7. Keep verification credential-type agnostic during the KernelBridge-managed compatibility period. KernelBridge removes old direct-key and grant paths only after usage telemetry shows no remaining clients and its deprecation window has elapsed.
8. Define an explicit product-level `mode_changed_at`/policy revision. Clients record and enforce the signed revision received; they never infer, choose, or locally override policy from environment variables.

Do not make licensing type part of a plan or subscription unless KernelBridge identifies and approves a demonstrated commercial requirement for per-customer exceptions. Start with one KernelBridge-managed product-level default. Any exception must be created, audited, and retired only by KernelBridge.

## 6. Configuration and secret handling

The standard licensing installation must work without adding licensing-specific variables to `.env`, creating a generated licensing environment file, or asking customers to configure a KernelBridge URL or machine token. Product-specific public identity is supplied in the installed package's configuration/manifest, and the package ships the public KernelBridge endpoint default. KernelBridge product registration/admin tooling should provide package installation instructions and any non-secret product-specific config needed by the supported runtime.

### Product/package configuration

Move safe, stable defaults to each product's package configuration:

- product code (build/product identity);
- public KernelBridge API base URL;
- KernelBridge-branded activation UI wording/routes and product-context labels;
- verification interval, timeout, and offline-grace maximum;
- feature-to-entitlement mappings and local enforcement hooks.

Use reviewed package configuration changes and deployment releases for these defaults. Avoid calling `env()` at runtime outside configuration bootstrap. A public API URL or product code is not an authentication secret. Do not make environment variables a required or documented customer setup step for licensing.

### Per-install state and server secrets

- Generate installation identity and receive the installation credential during code exchange; persist them in protected local storage/database, not deployment configuration.
- Keep account-platform integration credentials only on the product platform server or in a secret manager; never put them in the licensing package's public config, customer `.env` template, or product client. Their secure provisioning/storage is a server-side integration concern and must not become a customer licensing environment-setup requirement.
- Never distribute a reusable product API token or global signing secret to customer installations.
- Sign entitlement snapshots centrally; clients verify with a pinned public verification key. Keep local cache-integrity protection distinct from central entitlement signatures.
- Redact activation codes, bearer credentials, signing keys, and license keys from logs, errors, support bundles, and telemetry.

### Credential HMAC key rotation

KernelBridge server operators may configure `product_integration.credential_hmac.active_key_id` and the `KERNELBRIDGE_LICENSE_HMAC_KEYS` JSON key map. New activation-code lookups and installation/grant credential verifiers are written with the active key ID. Verification accepts configured overlap keys, and legacy rows without a key ID continue to use any retained configured key or the current application key. During rotation, retain the previous key in the server key map until all activation codes issued under it have expired and all credentials using it have been rotated or retired. Never remove an old key while live records still depend on it. This HMAC rotation is separate from Laravel `APP_KEY`, which still protects encrypted retry credentials and other application data; do not rotate `APP_KEY` until a separate encryption-key migration/recovery process is implemented.

### Backward compatibility

For existing installations, retain a temporary compatibility reader for current environment keys and persisted deployment profiles. On upgrade, report whether effective configuration came from package config or legacy environment values. Once an operator confirms migration, persist the installation credential/state, remove the legacy values from deployment instructions, and deprecate the old reader on a published schedule. New installations must not require these legacy variables. Do not silently adopt new defaults when they change a live installation's endpoint.

## 7. API and data changes

Freeze the exact API schema before package upgrades. The implemented operations are:

- `POST /api/v1/installations/activate`: redeem a one-time code and bind an installation.
- `POST /api/v1/installations/verify`: authenticate with installation credential and return a signed current state.
- `POST /api/v1/installations/deactivate`: deactivate the authenticated installation and release its seat.
- `POST /api/v1/platform/account-links/requests`: create or retrieve a pending tenant-link request using the exact `licensing:account-link` product API scope.
- `GET /api/v1/platform/account-links/{link}`: poll link approval status for the calling product.
- `POST /api/v1/platform/account-links/{link}/activation-codes`: issue or replace an installation-bound code only for an approved link; replacing an unused code retains its reserved seat.

Still required:

- Platform-account transfer operations, including ownership verification and installation migration.
- Customer/admin installation transfer and credential rotation/recovery operations with idempotent retry behavior.
- Customer/admin portal transfer and recovery actions; code issuance/listing/revocation are implemented for the current customer portal flow.
- KernelBridge-admin-only policy and migration operations to preview, approve, schedule, execute, pause, resume, and complete product model transitions.

Use public UUIDs in contracts. Store a keyed hash of one-time code verifiers, with expiry, product/license scope, permitted use count, issuance actor, and consumed installation. Installation credentials are hashed for verification and encrypted at rest only to recover a same-install activation response after a lost network response; future credential rotation must keep an equally narrow, audited recovery/idempotency design.

Create one authoritative installation-binding/seat service used by every code exchange, direct activation compatibility path, and platform grant issuance. In one transaction it must validate license/product/subscription dates and status, lock the license/seat ledger, enforce the effective limit, and create/update the installation binding. Define how active legacy activations and unexpired grants count during cutover so migration cannot grant extra seats.

Record a monotonically increasing entitlement/policy revision and effective timestamp. Snapshot revisions now advance on entitlement edits and effective-time boundaries; signed snapshots carry the product licensing mode and policy revision. Responses distinguish code invalid/expired/revoked/used, product mismatch, seat limit, and installation inactive/revoked/deactivated/expired. Account ownership and temporary-service reason codes remain to be added with their corresponding operations. API clients must not collapse all causes into “invalid license.”

Maintain a KernelBridge product integration registry that distinguishes commercial licensing model from technical runtime/client compatibility. Record the supported runtime type(s), SDK/package and minimum version, integration capability, and migration readiness per product/version. Where useful, clients may report SDK/runtime version for compatibility and diagnostics; these values are informational and must never authorize a licensing-model change or weaken server-side policy.

### Exchange idempotency and recovery

Activation must be safe under real network failures: the server may consume a code and commit an installation while the response is lost. Design exchange as an idempotent operation keyed by the activation code and stable installation UUID (and/or an explicit idempotency key). A retry from the same installation must recover a usable activation result without allocating another seat or requiring a new customer code; a replay from a different installation must be rejected. If recovering the original credential requires retaining it, store it encrypted with a narrow purpose and rotation policy; otherwise rotate the credential on an authenticated same-install retry and invalidate its previous value. Never log the code, idempotency key if it is secret-bearing, or credential.

Define explicit lifecycle operations for a damaged server, reinstalled desktop app, lost credential, restored backup, or suspected copied installation. Recovery must authenticate the authorized customer/account or KernelBridge operator, revoke or rotate the prior credential, preserve or deliberately replace the installation binding, and maintain seat accounting. Do not silently treat a cloned database or copied desktop state as a second authorized installation. Explain that machine identity is a useful signal, not unforgeable hardware attestation.

## 8. Phased implementation

### Phase 0 — Contract and migration inventory

- [x] Confirm activation-code lifecycle: 24-hour expiry, issuance reserves one seat, and replacement revokes the unused code while retaining the seat.
- [x] Confirm web/server and desktop activations share one installation pool. The shared allocator takes the maximum of the legacy license activation cap and applicable `max_installations` / `max_server_installations` entitlements so migration does not silently reduce existing allowances.
- [x] Confirm customer-initiated unified installation deactivation/revocation releases its seat immediately.
- [x] Confirm platform-link requests require a trusted product-backend assertion and KernelBridge customer/license-owner approval.
- [x] Confirm unlinking a platform account immediately revokes linked installations and releases their seats.
- [ ] Confirm account-link transfer/reset rules, offline policy, and model-change semantics.
- Inventory products and deployment variants by runtime type (Laravel web/server, TypeScript desktop main process, platform backend, or browser-only frontend), SDK/package version, storage capability, subscription/license states, API clients, current direct activations, business claims, grants, deployment profiles, and product environment values.
- Inventory backup/restore, cloning, reinstall, disaster-recovery, credential-loss, key-rotation, and customer-support recovery scenarios for each runtime type.
- Establish baseline metrics and support/runbook procedures. Do not log secrets while collecting inventory.
- Deliver: versioned API contract, runtime-specific storage/identity contracts, data migration map, threat model, and product-by-product/runtime-variant cutover checklist.

### Phase 1 — KernelBridge data model and shared policy

- [x] Add product licensing model, runtime type, policy revision, and audited KernelBridge-admin-only setting through product management; reject model changes from product/customer credentials.
- [x] Add one-time installation-code lifecycle and installation records/bindings, including 24-hour issue expiry, replacement, redemption, and one credential per installation.
- [x] Record code issuance/replacement in the audit log without recording the plaintext activation code.
- [x] Implement the shared transactional seat allocator across legacy direct activations, existing platform grants, new installations, and unredeemed activation-code reservations.
- [x] Enforce product mode for new activations: direct mode issues codes instead of reusable-key activations or new platform grants; existing active activations/grants remain compatible.
- [x] Add pending/approved platform account-link records, a narrowly scoped product backend credential preset, customer-owner approval/rejection, and installation-bound code issuance.
- [x] Add primary-customer-owner platform unlink with transactional revocation of active linked installations and unused codes, immediate seat release, and audit records.
- [x] Add versioned HMAC key identifiers and overlapping verification keys for activation codes, unified installation credentials, and legacy platform grants while retaining compatibility for existing rows.
- [ ] Add migration mapping/status records for legacy activations and grants.
- [x] Add installation self-deactivation and customer-portal revocation with audit records and immediate seat release.
- [ ] Add credential rotation/recovery, transfer, admin recovery, and migration actions with idempotency, rate limits, audit records, and safe secret redaction. Keep policy and migration controls restricted to authorized KernelBridge operators.
- [x] Preserve existing v1 endpoints; add the short-rate-limited `/api/v1/installations/activate` code-exchange endpoint and installation-credential verification. The exchange returns a signed entitlement snapshot, and same-install retries return the same credential.

The new platform-account linking and unlinking paths are implemented, but legacy claims remain compatible and are not proof for the new path. Do not select `platform_account` for rollout until product-backend deployment, customer approval UX, transfer policy, migration accounting, and SDK handling are ready.

Do not switch a product to `direct` until its compatible licensing SDK has been deployed: direct mode stops new reusable-key activations. Existing active installations remain compatible, and pre-issued 24-hour codes can still be redeemed.

### Phase 2 — Shared Laravel client and MediFlow pilot

- Follow [the SDK repository and improvement plan](./LICENSING_SDK_REPOSITORY_AND_IMPROVEMENT_PLAN.md) to establish canonical package ownership and add code exchange and installation-credential verification.
- Make the Laravel package own the KernelBridge-branded activation screen/routes, server-side state, verification schedule, entitlement middleware, and local error/recovery UX; leave products only integration, contextual product identity, and navigation extension points that cannot suppress or white-label KernelBridge branding.
- Make product identity and public endpoint defaults package-config driven; keep secrets out of client configuration.
- Add server-side credential storage, signed entitlement validation, activation status/reason codes, retry-safe verification, and package integration for the server-side deactivation endpoint. Credential rotation remains blocked on the lifecycle API.
- Make MediFlow read the package's central entitlement state consistently; remove environment-controlled standalone fallback for centrally licensed installations after migration.
- Migrate one staging MediFlow web/server installation, test application-server replacement/reinstall, mode change, outage, expiry, renewal, and revocation.
- Keep a rollback path to the old client and endpoint until the pilot passes.

### Phase 3 — Other SDKs and product-platform integration

- Move the TypeScript SDK into the shared SDK repository alongside the Laravel package, and implement the same code-entry/activation workflow and state transitions, supported optional desktop UI adapter, main-process-only credential handling, and OS-vault storage as specified in the SDK repository plan.
- Implement the same API contract and state transitions for Spring Boot and Django clients, with runtime-specific credential storage adapters and identity lifecycles.
- Add a reference product-backend integration for one platform product, including authenticated tenant assertion, KernelBridge customer approval UX, approved-link polling, installation-bound code delivery, and clear pending/rejected states.
- Implement account unlink/transfer only after the policy for already activated installations and seat release is approved.
- Ensure untrusted browser clients and desktop renderers never receive platform integration or installation credentials. Document a backend-brokered activation pattern for browser-only products.
- Add SDK conformance fixtures that run against the same activation, renewal, revocation, and migration scenarios.

### Phase 4 — Gradual product and installation migration

- KernelBridge selects and authorizes each product migration and controls the transition window; make `hybrid` available only when KernelBridge chooses it as a migration state.
- KernelBridge operators receive a migration preview of affected accounts/installations, seat impact, incompatibilities, and required action. Customer admins may see relevant status and required customer actions, but cannot approve or execute product policy changes.
- KernelBridge migrates installations in idempotent batches. Preserve installation UUIDs where trustworthy; require reactivation only when a credential cannot be safely bound or recovered.
- Monitor migration completion, legacy API use, activation failures, seat counts, API health, offline-grace incidents, and support tickets.
- KernelBridge does not switch a product to a strict new mode until required installations are migrated or explicitly exempted by an audited KernelBridge decision.

### Phase 5 — Deprecation and cleanup

- Announce and enforce a per-product deprecation date for legacy key-based activation.
- Disable legacy code issuance first, then legacy activation, only after confirming remaining usage and offering a supported migration path.
- Remove obsolete environment templates, profile fingerprints, generated `.env` downloads, and old SDK code in a separate release.
- Retain audit and installation history according to the product's retention policy.

## 9. Operational, resilience, and privacy requirements

- **Atomic replay-safe exchange:** Test lost responses, duplicate requests, concurrent redemptions, retries from the same installation, and replay from a different installation. The accepted path must consume at most one seat and return or rotate one usable installation credential.
- **Recovery and lifecycle:** Define and test deactivation, credential rotation, installation transfer, reinstall, lost local state, host replacement, desktop reinstall, backup restore, cloned-state detection, and abandoned-seat release. Each action needs an explicit authorized actor and audit history.
- **Signing and key rotation:** Document rotation for activation-code HMAC keys, installation-credential verifiers, entitlement-signing keys, and local encryption keys. HMAC key IDs and overlap support are implemented; overlapping Ed25519 signing-key distribution/rotation and Laravel `APP_KEY` encrypted-state migration remain.
- **Availability and consistency:** Define API timeout/retry/backoff behavior, rate limits, idempotency retention, outage behavior, and the maximum offline grace. Do not allow network failure to appear as successful activation or clear an explicit server rejection. Prevent delayed/out-of-order entitlement updates from replacing a newer revision.
- **Seat and status lifecycle:** Define when a seat is reserved, active, suspended, released, expired, or awaiting transfer. Specify how grace-period, revoked, deactivated, and abandoned installations count, and ensure the portal and API show the same authoritative count.
- **Privacy and telemetry:** Collect only device/runtime metadata needed for support and policy. Document purpose, retention, access, and deletion. Keep activation codes, credentials, raw machine identifiers, patient/customer data, and signing keys out of logs and telemetry.
- **Support diagnostics:** Provide a correlation/request ID and safe reason code for support. Permit a customer to share installation UUID, product code, SDK version, and request ID without disclosing activation codes or bearer credentials.
- **Runtime-specific operations:** For Laravel, document server-side storage permissions and application-key/backup handling. For desktop, document OS-vault availability, fallback encryption, multi-user/device identity, backup/restore, and renderer isolation. For browser-only products, require a trusted server broker.

## 10. Required tests and release gates

- One-time code: valid exchange, normalization, wrong product, expired, revoked, replay, concurrent replay, rate limiting, and safe error output.
- Exchange idempotency: a simulated lost response and retry from the same installation returns a usable state without consuming another seat; a different installation cannot replay that code.
- Installation binding: same-install retry is idempotent; another installation consumes another seat; device/installation replacement follows policy.
- Seat integrity: mixed legacy activation/grant/new credential paths cannot exceed the license limit, including concurrent requests.
- Account path: unauthenticated/arbitrary tenant linking is rejected; trusted product backend can request a link; only the KernelBridge license owner can approve it; installation-code issuance is blocked until approval and bound to the target installation UUID. Primary license owners can unlink, immediately revoking linked installations and codes and releasing seats. Transfer requires separate ownership/migration policy.
- Model changes: direct, platform-account, and hybrid transitions preserve valid installations; dry-run, retries, rollback, and partial migration work.
- Runtime boundaries: Laravel credentials remain server-side; desktop credentials are unavailable to renderer code and use the approved secure-storage adapter; browser-only clients never receive reusable credentials; installation identity survives updates and handles reinstall/restore according to policy.
- New-product onboarding: registering a product and installing its supported SDK is sufficient for the standard activation and entitlement lifecycle; custom product code is needed only for documented integration/presentation extension points or a trusted platform-account broker.
- SDK compatibility: each runtime/client version reports and negotiates supported protocol capabilities without being able to override KernelBridge's licensing model.
- Entitlements: signature verification, revision/effective-time behavior, renewal, downgrade, expiry, revocation, offline grace, and service outage.
- Recovery/key lifecycle: credential rotation and revocation, reinstall/transfer, backup restore, copied-state detection, and signing-key rotation without forced mass activation.
- Configuration: standard installation works without licensing-specific `.env` entries or a generated environment file; product identity and public endpoint defaults come from package config/manifest; no customer activation or recurring installation secret is placed in `.env`; legacy environment migration behaves as documented.
- SDK conformance: shared request/response fixtures produce equivalent state across Laravel, TypeScript, Spring Boot, and Django.
- Operations: dashboards and support diagnostics identify failures by reason code without exposing activation codes or bearer credentials.

Release gates: migration is reversible; no customer secret is emitted to public product clients; seat limits are transactionally enforced; existing installations are inventoried; a tested rollback and support runbook exist.

## 11. Decisions required before implementation

Confirmed before implementation: activation codes expire after 24 hours; issuance reserves a seat; an unused code can be replaced while retaining its seat; same-install retry recovers the same credential; web/server and desktop installations share a seat pool; KernelBridge exclusively controls product licensing model changes and migrations; Laravel and TypeScript SDKs are maintained in one shared SDK repository with independent package versions and releases.

Also confirmed: customer-initiated unified installation deactivation/revocation releases the seat immediately.

Also confirmed: platform-account links require trusted product-backend attestation and KernelBridge customer/license-owner approval before bound installation codes can be issued.

Also confirmed: unlinking a platform account immediately revokes its active installations and unused codes and releases their reserved seats; a future association requires a new owner-approved link.

Still to resolve: offline-grace defaults and tier limits; per-license code allocation and customer/admin issuance policy; account-link transfer rules; governance/approval requirements for licensing policy changes; migration overlap and legacy API retirement criteria; package registry choices and protocol capability negotiation. Before SDK upgrades, complete the remaining key rotation/recovery design, migration mapping/operations, and cross-runtime conformance contract. The first SDK release may support the new platform path only if its UI exposes pending/approved/rejected states and cannot issue codes before approval.

## Related documentation

- [Licensing Client Specification](./LICENSING_CLIENT_SPEC.md)
- [Reusable Licensing Client](./LICENSING_CLIENT.md)
- [Laravel Licensing Client README](../packages/kernelbridge/licensing-client-laravel/README.md)
- [Licensing SDK Repository and Improvement Plan](./LICENSING_SDK_REPOSITORY_AND_IMPROVEMENT_PLAN.md)
