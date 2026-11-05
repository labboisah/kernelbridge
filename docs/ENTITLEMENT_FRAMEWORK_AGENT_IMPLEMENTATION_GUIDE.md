# Entitlement Framework Agent Implementation Guide

## Purpose

This guide directs an implementation agent through a staged, backward-compatible upgrade that standardizes plan entitlements between KernelBridge and product servers.

The framework must support:

- KernelBridge-managed plan grants for Direct installations.
- Product-platform-managed grants, validated and shared with KernelBridge, for Platform Account installations.
- Explicit source selection per installation for Hybrid products.
- Product-server enforcement of features and limits.
- Separate product-owned user roles, permissions, and resource authorization.

This is an implementation guide, not authorization to begin every phase at once. Work one phase at a time, stop at each checkpoint, report evidence, and wait for approval before proceeding to the next phase.

## Non-negotiable preservation requirements

1. **Do not alter, replace, or remove existing licensing behavior as part of this upgrade.**
2. Do not rewrite existing activation, plan, subscription, entitlement, role, or permission implementations to fit the new design. Introduce new interfaces and implementations alongside them.
3. Do not change current API response shapes, routes, database meanings, or package behavior without explicit approval for a separate compatibility change.
4. Do not edit historical migrations. Add new, reversible migrations only.
5. Do not delete, reset, revert, or overwrite pre-existing worktree changes. Inspect worktree status first and keep unrelated changes untouched.
6. Do not make the new resolver authoritative during initial implementation. Begin with validation, shadow evaluation, and comparison against current behavior.
7. Do not publish packages, run production migrations, alter production configuration, or deploy without explicit approval.
8. Do not put product-platform credentials, user records, role assignments, or sensitive account data into client packages or entitlement snapshots.
9. Keep KernelBridge authoritative for activation, license validity, subscription lifecycle, renewal, expiry, revocation, and signed entitlement delivery.
10. A product server must enforce entitlements against its own operations and data. A client UI may hide unavailable functionality but is never the security boundary.

If existing code or data conflicts with these requirements, stop at the relevant checkpoint and report the conflict. Do not silently adapt or migrate current behavior.

## Ownership and trust model

| Concern | Direct installation | Platform Account installation | Hybrid installation |
|---|---|---|---|
| Activation, license lifecycle, renewal, expiry, revocation | KernelBridge | KernelBridge | KernelBridge |
| Plan-to-grant authority | KernelBridge | Product platform | Determined by this installation's explicit activation/source binding |
| Product capability catalog | Product defines stable product-specific keys and semantics | Product defines stable product-specific keys and semantics | Same catalog |
| Grant validation and signed client delivery | KernelBridge | KernelBridge validates platform update and signs the effective snapshot | KernelBridge validates and signs the selected source's snapshot |
| Product usage counting and feature enforcement | Product server | Product server | Product server |
| User roles and permissions | Product | Product | Product |
| Product-specific account and device inventory | Product platform if present; KernelBridge manages direct client licensing-device information | Product platform | Platform-linked installs: product platform; direct installs: KernelBridge direct flow |

The effective access decision is conceptually:

```text
valid KernelBridge license lifecycle
AND accepted effective entitlement grant
AND authenticated user permission for this action
AND product-specific tenant/resource authorization
```

Entitlement grants do not extend or restore a license that KernelBridge considers expired, revoked, suspended, or otherwise inactive.

## Shared contract principles

The contract must distinguish **catalog definitions**, **plan mappings**, **source-supplied grants**, and **effective installation entitlements**. Do not conflate those records.

The product owns capability names and domain semantics. Prefer stable, product-namespaced keys such as:

- `laboratory.results.view`
- `laboratory.results.export`
- `branches.max`
- `invoices.per_period`

The human-facing hierarchy may be `product -> plan -> package -> module -> capability`; SDKs should consume a deterministic map of grants rather than infer behavior from plan names or an arbitrary hierarchy.

Example catalog and plan mapping for design discussion only:

```json
{
  "schema_version": 1,
  "product_code": "MEDIFLOW",
  "catalog_revision": 12,
  "packages": [
    {
      "key": "clinical",
      "modules": [
        {
          "key": "laboratory",
          "capabilities": [
            { "key": "laboratory.results.view", "type": "boolean" },
            { "key": "laboratory.results.export", "type": "boolean" }
          ]
        }
      ]
    }
  ],
  "plans": [
    {
      "key": "professional",
      "grants": {
        "laboratory.results.view": { "enabled": true },
        "laboratory.results.export": { "enabled": true },
        "branches.max": { "value": 5, "unit": "branches", "scope": "business" }
      }
    }
  ]
}
```

Example effective grant snapshot:

```json
{
  "schema_version": 1,
  "product_code": "MEDIFLOW",
  "license_uuid": "public-license-uuid",
  "source": "product_platform",
  "source_revision": 18,
  "catalog_revision": 12,
  "entitlement_revision": 48,
  "grants": {
    "laboratory.results.view": { "enabled": true },
    "laboratory.results.export": { "enabled": true },
    "branches.max": { "value": 5, "unit": "branches", "scope": "business" }
  }
}
```

Examples are illustrative only. Before implementation, agree on the final wire format and whether `license_uuid` is needed in each client-facing snapshot. Prefer the least identifying data that still permits safe binding and diagnostics.

### Required contract semantics

- Capabilities are explicitly granted booleans. Missing or unknown capabilities are denied.
- Limits have explicit types and semantics. Missing limits are not unlimited unless explicitly represented by an agreed sentinel or type.
- A limit must define its unit and scope; define period and reset semantics where relevant. Examples include branches per business and invoices per calendar month.
- Define behavior when a customer is above a newly lowered limit. The usual safe default is to prevent additional usage and provide a product-defined remediation path; do not silently delete customer data.
- Capability keys are stable and never reused for a different meaning. A semantic change requires a new key or an explicit migration.
- Separate schema version, catalog revision, source revision, and effective entitlement revision.
- Define behavior for unsupported schema versions, unknown grant types, duplicate keys, malformed values, invalid units, and stale snapshots. Do not guess or silently reinterpret them.
- Product server usage checks must be atomic where concurrent requests could exceed a limit.
- Role and permission definitions remain outside the entitlement grant schema. Products may standardize permission-key conventions separately.

## Required implementation workflow

At the start of every phase:

1. Inspect both repository worktrees and identify existing modifications and untracked files.
2. Read the relevant implementation and tests before editing.
3. Record the exact files and behaviors in scope for the phase.
4. Confirm no pre-existing modifications will be overwritten.
5. Add or update a focused test plan before implementation.

At the end of every phase:

1. Run the smallest relevant test set, then expand only if needed.
2. Compare existing behavior against the Phase 0 baseline.
3. Review the diff for accidental edits to unrelated files.
4. Verify rollback/reversibility for any schema or configuration changes.
5. Report changed files, tests and results, known failures, and the next checkpoint.
6. Stop and wait for approval before beginning the next phase.

Never report a phase as passed based only on compilation. Meet the measurable acceptance criteria in that phase.

## Phases and approval checkpoints

### Phase 0 — Baseline and preservation inventory

**Objective:** Establish the behavior and worktree state that must be preserved.

**Tasks:**

- Identify the KernelBridge repository and SDK/package repositories involved.
- Record branch, status, and existing tracked and untracked changes in each repository. Do not clean or stage them.
- Trace current plan, package, module, feature, limit, entitlement snapshot, signature, activation-mode, renewal, expiry, and revocation behavior.
- Inventory relevant routes, tables, package public APIs, migrations, tests, and docs.
- Run existing focused tests without modifying code. Record any pre-existing failures with exact commands and summaries.
- Identify how Direct, Platform Account, and Hybrid are currently represented and enforced.

**Checkpoint 0 — Baseline approved**

Proceed only when:

- Existing behaviors and public contracts are listed.
- Pre-existing modifications are distinguished from work for this upgrade.
- Test baseline, including pre-existing failures, is recorded.
- No implementation files have been changed.

### Phase 1 — Contract and threat-model approval

**Objective:** Approve the shared schema and authority boundaries before coding.

**Tasks:**

- Finalize catalog, plan mapping, source grant update, and effective snapshot schemas.
- Define capability and limit types, units, scopes, period semantics, and over-limit downgrade behavior.
- Define source selection and transition rules for Hybrid per installation.
- Define monotonic revision and idempotency behavior, including stale, duplicate, and retry cases.
- Define grant update authorization and license/account-link association checks.
- Define snapshot freshness, offline/grace behavior, signing and key rotation requirements.
- Define privacy minimization and error semantics.
- Decide whether the shared schema belongs in the existing protocol/SDK repository first. Do not create a new installable package unless there is demonstrated code reuse that cannot reasonably be maintained through shared contract fixtures.

**Checkpoint 1 — Contract approved**

Proceed only after product and KernelBridge owners approve the schema, source-of-truth rules, failure behavior, and threat model. No database or API implementation before approval.

### Phase 2 — Shared schemas, validators, and conformance fixtures

**Objective:** Prove that KernelBridge and product runtimes interpret the same payload consistently.

**Tasks:**

- Add versioned schema documentation and fixtures in the existing protocol repository.
- Add positive and negative fixtures for each agreed grant type.
- Implement validators without changing current runtime authority.
- Validate wrong product, wrong license association, unsupported schema, unknown grant type, duplicate key, malformed grant, invalid units, stale revision, and invalid source.
- Ensure Laravel and TypeScript validators produce equivalent accept/reject outcomes.
- Keep any validator helpers separate from current activation and entitlement resolvers.

**Checkpoint 2 — Contract conformance passes**

Proceed only when both runtimes pass the same fixture suite, all negative cases fail safely, and current entitlement behavior is unchanged.

### Phase 3 — Additive KernelBridge persistence

**Objective:** Add storage for catalogs, source grants, revision state, and audit history without changing current tables' meanings.

**Tasks:**

- Add new migrations; never edit historical migrations.
- Make migrations additive and reversible.
- Add indexes for product, license, source, and revision lookups.
- Keep catalog definitions separate from plan mappings and customer-specific source grants.
- Add audit history for accepted and rejected updates while redacting secrets and activation codes.
- Test migrations on a copy of representative data and test rollback.
- Do not backfill or mutate existing plan/entitlement records unless explicitly approved as a separately reviewed migration.

**Checkpoint 3 — Persistence safe**

Proceed only when fresh migration, upgrade migration, rollback, and existing data integrity tests pass; existing licensing tests remain at baseline or better.

### Phase 4 — Scoped platform grant-update API

**Objective:** Allow authorized product-platform servers to submit versioned grants to KernelBridge.

**Tasks:**

- Add a new API route; do not repurpose existing licensing or account-link endpoints.
- Require a narrowly scoped server credential, scoped to product and grant-update operation.
- Verify that the authenticated product and account link are authorized for the target license. A license UUID supplied by a caller is not proof of authority.
- Validate schema, product, source, license state, catalog compatibility, and monotonic source revision.
- Require an idempotency key and make safe retries deterministic.
- Reject stale revisions; return stable, non-secret error codes.
- Rate limit and audit updates. Do not log credentials, activation codes, full sensitive account payloads, or secrets.
- Ensure grant updates cannot modify license validity, renewal dates, activation state, or revocation status.

**Checkpoint 4 — API security verified**

Proceed only after tests prove:

- Unauthenticated, wrong-scope, wrong-product, unauthorized-license, malformed, stale, and invalid-source requests are rejected.
- Duplicate idempotent retries do not duplicate or regress state.
- Valid updates are durably stored and auditable.
- License lifecycle authority cannot be changed through the grant-update API.

### Phase 5 — Source-aware grant resolution in shadow mode

**Objective:** Calculate new effective grants without changing customer access.

**Tasks:**

- Implement a new resolver next to the existing resolver.
- Direct mode: derive proposed grants from KernelBridge-managed plan mapping.
- Platform Account mode: use only the latest accepted platform grant revision.
- Hybrid mode: use the source explicitly bound to the installation; no implicit merge.
- Record source and revisions in the proposed result.
- Compare proposed grants to existing entitlements in shadow mode and emit safe structured diagnostics.
- Do not persist the shadow result as authoritative or use it to deny/grant product access.
- Define and test explicit, authorized source-transition handling for Hybrid.

Shadow comparison is disabled by default and can be enabled with `ENTITLEMENT_FRAMEWORK_SHADOW_ENABLED=true`. When enabled, each signed-entitlement refresh resolves the bound source and writes safe structured differences (capability keys, counts, revisions, and a digest; never grant values or installation identifiers) to the application log. It does not change the signed payload or persist the proposed grants. Activation codes and installations now retain an additive `entitlement_source` binding. For Hybrid, a linked activation uses only the approved platform source; a direct activation uses only the Direct plan mapping. V1 does not perform source transitions in place; a source change requires a newly authorized activation flow. Historical rows with ambiguous source history resolve to no source rather than silently switching.

**Checkpoint 5 — Shadow parity approved**

Proceed only after representative active, trial, grace, expired, renewed, downgraded, revoked, direct, platform-linked, and Hybrid cases have been compared and differences are explained and approved. Any unexplained access change blocks rollout.

### Phase 6 — Versioned signed snapshot support

**Objective:** Add grants to a versioned signed response while preserving verification of existing snapshots.

**Tasks:**

- Introduce a versioned snapshot format; preserve existing fields and old verification paths.
- Include only necessary grants, source/revision metadata, and stable binding claims.
- Keep KernelBridge signing keys private and server-side.
- Verify signatures over the exact serialized payload bytes.
- Test wrong product, installation, license binding, unknown key ID, malformed signature, expiry, revocation, and schema version.
- Test key rotation and rollback compatibility.
- Do not trust a signing public key delivered by the same untrusted response it is meant to verify.
- Do not require old clients to understand the new grant shape before they are upgraded.

The optional extension is gated by `ENTITLEMENT_FRAMEWORK_SNAPSHOT_ENABLED=true` and remains disabled by default. It is attached only when the bound source resolves a valid catalog and grant set and the KernelBridge license remains lifecycle-eligible. Existing version-1 claims and flat `entitlements` remain intact. Updated Laravel and TypeScript SDKs verify the signature over exact payload bytes first, then validate any present extension against the outer source binding; absence remains backward compatible. The SDKs keep the signed payload intact and do not yet authorize product features from the extension.

**Checkpoint 6 — Snapshot compatibility approved**

Proceed only when current clients still verify their supported snapshots, upgraded clients verify old and new supported formats, and tampering or untrusted keys fail closed.

### Phase 7 — SDK consumption and cache behavior

**Objective:** Add safe, nonbreaking helpers for product-server and client code.

**Tasks:**

- Add methods such as `hasFeature(key)` and typed `limit(key)` without removing existing SDK APIs.
- Return explicit results for absent, disabled, malformed, or unsupported grants.
- Verify snapshots before caching or returning grants.
- Cache verified snapshots; do not make a KernelBridge request for every product request.
- Define bounded freshness and outage behavior that cannot extend license validity.
- Avoid client-clock-only enforcement; server validation and signed timestamps remain authoritative within the approved offline policy.
- Keep platform credentials and account-link calls server-only.

The Laravel and TypeScript SDKs consume structured grants only after validating their signed snapshot. If the extension is present it is authoritative (no fallback to flat entitlements for missing keys); extension absence preserves legacy behavior. Both SDKs cache and expose structured decisions/typed limits locally, and bound offline access from signed `issued_at` as well as license/grace expiry. Old cache formats continue to load without a schema migration.

**Checkpoint 7 — SDK compatibility approved**

Proceed only when Laravel and TypeScript tests cover old cache migration, fresh snapshots, stale snapshots, outage, expiry, revocation, renewal, malformed grants, and default-deny behavior. Existing SDK public methods continue to work. Keep platform credentials and account-link calls server-only; the SDK decision helpers never make per-request KernelBridge calls.

### Phase 8 — Product-server enforcement

**Objective:** Enforce grants at the server boundary using product-owned usage and authorization data.

**Tasks:**

- Integrate one pilot product at a time.
- Define the product's capability catalog with stable keys, types, units, scopes, and descriptions. Group capabilities into packages/modules for administration and UX, but do not make runtime decisions depend on plan display names.
- Add one product-side entitlement service that consumes only verified, cached KernelBridge snapshots and exposes typed operations such as `hasFeature(key)` and `limit(key)`. Do not duplicate raw grant parsing across controllers.
- Treat absent or unknown grants as denied and absent limits as not unlimited unless explicitly specified by the contract.
- Check entitlement, user role/permission, tenant membership, and resource authorization independently. A plan grant must not implicitly grant a user permission.
- Enforce grants at every relevant server boundary: web and API routes, domain services, resource creation/update, exports and reports, integrations, bulk operations, queued jobs, scheduled tasks, and endpoints used by desktop/mobile clients.
- Count usage using product-owned data; KernelBridge does not own the product's live usage counters.
- Make limit checks atomic or transactionally safe under concurrency.
- Define product behavior for lowered limits and customers already above limits without deleting data.
- Treat UI enablement as presentation only; never rely on hidden controls or client-side checks to authorize an operation.
- Return stable, distinguishable denial reasons for missing capability, reached limit, unusable license, stale snapshot, and temporary licensing-service outage. Map them to safe, actionable product messages.
- Record safe diagnostics such as grant key, entitlement revision, source, snapshot age, and decision reason. Never log credentials, activation codes, or sensitive account payloads.
- Provide product-admin diagnostics for the current grant source and revisions, last successful refresh, snapshot freshness, and rejected or pending platform updates. Do not expose secrets.
- For Platform Account products, publish grant changes reliably using retry-safe, idempotent delivery. Prefer a transactional outbox so a committed platform plan change cannot silently lose its KernelBridge update; track acceptance and reconcile pending revisions.
- Do not migrate roles or per-user permission assignments into KernelBridge entitlement records.

**Checkpoint 8 — Server enforcement proven**

Proceed only when:

- Tests prove unauthorized users remain denied even when the subscription grants the capability, and entitlement denials remain effective even for otherwise authorized users.
- Tests cover each applicable route, domain service, job, export, and integration boundary; UI-only enforcement is not accepted.
- Missing and unknown grants fail closed, and plan display names do not affect runtime decisions.
- Concurrent requests cannot exceed tested limits; downgrade/over-limit behavior preserves existing customer data.
- Offline/stale-snapshot behavior follows the approved bounded policy and emits safe, useful diagnostics.
- Platform grant updates survive retries, duplicates, temporary outages, and worker restarts; pending/rejected updates are visible for reconciliation.
- Existing product roles, permissions, and licensing behavior remain unchanged outside the explicitly approved integration points.

### Phase 9 — Controlled pilot and operational readiness

**Objective:** Validate one Direct and one Platform Account product before broad enablement.

**Tasks:**

- Start with shadow evaluation and compare decisions.
- Enable a small, noncritical capability set behind a server-side feature flag.
- Monitor update validation failures, stale revisions, snapshot age, signature failures, entitlement denials, and limit enforcement.
- Add safe dashboards and operator diagnostics without exposing secrets or sensitive customer payloads.
- Document support, retry, key rotation, outage, source-transition, and recovery procedures.
- Test rollback by disabling the new resolver and returning to the existing path; retain audit/history data.

**Checkpoint 9 — Pilot accepted**

Proceed only after product owners approve observed parity, metrics, support readiness, and a tested rollback. An increase in rollout scope requires separate approval.

### Phase 10 — Immutable release and deprecation decisions

**Objective:** Ship only after compatibility and operational gates pass.

**Tasks:**

- Build immutable Laravel, TypeScript, and UI artifacts as applicable.
- Test clean install, upgrade from supported prior versions, rollback, and supported runtime matrix.
- Publish package configuration and key provisioning guidance.
- Keep existing entitlement paths available during the adoption window.
- Do not remove or deprecate existing behavior in this upgrade. Any deprecation requires a separate proposal, migration plan, compatibility window, and explicit approval.
- Do not publish or deploy without explicit authorization.

**Checkpoint 10 — Release approved**

Release only after clean artifact installation, upgrade and rollback tests, product staging validation, security review of the update API, signing-key rotation validation, and all prior checkpoints are approved.

## Minimum test matrix

### Contract and validators

- Valid boolean capability and each supported limit type.
- Missing, disabled, unknown, and unsupported grants.
- Invalid schema version, product code, source, key, value, unit, scope, or period.
- Duplicate grant keys and malformed payload.
- Wrong product/license/account-link association.
- Stale, duplicate, and out-of-order revisions.

### Lifecycle and source handling

- Active, trial, grace, expired, suspended, renewed, downgraded, and revoked license.
- Direct, Platform Account, and Hybrid with both direct and platform-linked installations.
- Explicit Hybrid source transition, including unauthorized and stale transition attempts.
- Platform grant update cannot restore or extend an invalid KernelBridge license.

### Reliability and security

- Timeouts, retry, idempotency, duplicate delivery, and temporary outage.
- Signature tampering, unknown key, key rotation, and supported rollback window.
- Bounded offline policy and stale-snapshot rejection.
- No secret leakage in logs, errors, traces, or client bundles.
- Atomic usage enforcement under concurrent requests.

### Backward compatibility

- Existing activation and entitlement APIs retain their existing contract.
- Existing snapshots and stored state remain readable.
- Existing roles and permissions are unchanged.
- Upgrade and rollback preserve existing licensing data.

## Required phase report

At every checkpoint, report:

1. Phase name and whether its checkpoint passed.
2. Files changed, grouped by repository.
3. Tests run with exact commands and pass/fail counts.
4. Baseline failures that remain, distinguished from regressions.
5. Any behavior or data changes observed in shadow comparison.
6. Security, compatibility, migration, or operational risks still open.
7. Confirmation that unrelated worktree changes were preserved.
8. The next phase proposed, without starting it before approval.

## Completion criteria

The framework is not complete merely because the schemas, endpoints, or SDK helpers compile. Completion requires all approved phases to pass, effective grants to match their declared source, products to enforce grants server-side, lifecycle and rollback to be proven, and existing implementations to remain compatible.

Until release gates are approved, the existing entitlement implementation remains authoritative.
