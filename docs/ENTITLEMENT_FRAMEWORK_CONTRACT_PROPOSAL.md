# Entitlement Framework Contract Proposal

**Status:** Contract baseline; hierarchy authoring and catalog validation are implemented additively
**Scope:** The contract remains an incremental extension of the existing licensing system. Structured entitlement snapshots remain independently gated and are not enabled by this document.

This contract follows the preservation rules in the [agent implementation guide](./ENTITLEMENT_FRAMEWORK_AGENT_IMPLEMENTATION_GUIDE.md). Existing licensing behavior remains authoritative; hierarchy authoring and validation add capabilities without replacing existing activation or licensing flows.

## 1. Goals

- Give KernelBridge and product servers one versioned interpretation of product capabilities and limits.
- Allow KernelBridge-managed plan grants for Direct installations.
- Allow a product platform to manage grants for Platform Account installations and submit them to KernelBridge.
- For Hybrid products, choose one grant source per installation based on its authorized activation/link path. Do not silently combine sources.
- Let KernelBridge validate and sign the effective installation entitlement while retaining authority over license lifecycle.
- Let product servers enforce grants using product-owned data and user authorization.
- Preserve existing endpoints, signed claims, local state, and SDK APIs during adoption.

## 2. Authority and source selection

| Installation source | Grant authority | KernelBridge responsibility |
|---|---|---|
| Direct | KernelBridge plan mapping | Validate the active subscription and plan, resolve grants, track revisions, and sign the effective snapshot. |
| Platform Account | Product platform | Authenticate the scoped platform update, validate and store grants, track revisions, and sign the effective snapshot. |
| Hybrid, direct activation | KernelBridge plan mapping | Bind this installation to KernelBridge-managed grants. |
| Hybrid, platform-linked activation | Product platform | Bind this installation to the accepted platform grant source and sign that source's effective grants. |

KernelBridge remains authoritative for activation, installation records and credentials, seat accounting, subscription state, renewal, expiry, revocation, and signed entitlement delivery in every mode. A grant update cannot renew, reactivate, or override a KernelBridge-invalid license.

The product defines stable capability names and meanings. KernelBridge can validate those names against a registered product catalog. A catalog describes available keys and their types; it does not itself grant features to a customer. In Direct mode, KernelBridge owns the plan-to-grant mapping. In Platform Account mode, the product platform owns the customer's effective plan grants and sends updates to KernelBridge.

The installation's grant source must be explicit, auditable, and bound to the activation flow. A source change is an authorized transition, not an implicit fallback. No product-supplied field may select or change a source without server-side verification.

## 3. Catalog and plan mapping

The proposed product catalog has a versioned schema and stable product-specific keys. The UI/admin hierarchy may be `product -> plan -> package -> module -> capability`; runtime checks consume stable grant keys rather than plan names or display hierarchy.

Example for discussion:

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
            {
              "key": "laboratory.results.view",
              "type": "capability",
              "description": "View laboratory results."
            },
            {
              "key": "laboratory.results.export",
              "type": "capability",
              "description": "Export laboratory results."
            },
            {
              "key": "branches.max",
              "type": "limit",
              "unit": "branches",
              "scope": "business",
              "description": "Maximum active branches for one business."
            }
          ]
        }
      ]
    }
  ],
  "plans": [
    {
      "key": "professional",
      "grants": {
        "laboratory.results.view": { "type": "capability", "enabled": true },
        "laboratory.results.export": { "type": "capability", "enabled": true },
        "branches.max": {
          "type": "limit",
          "state": "finite",
          "value": 5,
          "unit": "branches",
          "scope": "business"
        }
      }
    }
  ]
}
```

The catalog is the shared authoring and validation structure. KernelBridge administrators can map a Direct or Hybrid plan to grants from the latest published product catalog through the plan entitlement editor. The catalog hierarchy is displayed as package → module → capability, while the plan mapping stores stable grant keys and typed values. Publishing a mapping records its catalog revision and canonical digest. SDK catalog validators flatten the hierarchy into stable-key definitions and verify plan grants against those definitions.

The catalog publisher must be a trusted product server; a desktop/client app must not publish catalogs or plan mappings. Platform Account plan grants remain owned by the product platform; KernelBridge's Direct plan editor does not change that authority.

### Key and type rules

- Keys are stable, product-namespaced identifiers. Never reuse a key with a different meaning.
- A capability is an explicit boolean. Missing or unknown capabilities are denied.
- A limit is a typed grant, not an arbitrary number. It defines a unit and scope; period and reset semantics are required for usage over time.
- Time-windowed limits in version 1 use calendar windows with an explicit IANA time zone: `period: { "unit": "day" | "week" | "month" | "year", "reset": "calendar", "time_zone": "Africa/Accra" }`. Non-periodic limits omit `period`.
- A finite limit carries a positive/non-negative numeric value according to the agreed capability definition. Unlimited is explicit (`state: "unlimited"`), never inferred from a missing value or `null`.
- Unknown grant types, duplicate keys, invalid values, unsupported schema versions, and mismatched units are rejected by validators.
- Display labels, descriptions, package names, and plan names are metadata. Runtime authorization uses stable keys and typed grant values.

## 4. Effective grant representation

For compatibility, the proposed first rollout does **not** replace the current `entitlements` array in signed installation claims. It adds a namespaced, optional framework extension generated from the same authoritative resolution:

```json
{
  "version": 1,
  "product_code": "MEDIFLOW",
  "installation_uuid": "installation-public-uuid",
  "license_uuid": "license-public-uuid",
  "license_status": "active",
  "entitlement_revision": 48,
  "entitlements": [
    {
      "key": "laboratory.results.export",
      "name": "Export laboratory results",
      "enabled": true,
      "limit": null
    }
  ],
  "entitlement_framework": {
    "schema_version": 1,
    "source": "product_platform",
    "catalog_revision": 12,
    "source_revision": 18,
    "grants": {
      "laboratory.results.export": {
        "type": "capability",
        "enabled": true
      },
      "branches.max": {
        "type": "limit",
        "state": "finite",
        "value": 5,
        "unit": "branches",
        "scope": "business"
      }
    }
  }
}
```

The sample keeps the established signed `version` and `entitlements` fields intact and adds `entitlement_framework`. Existing clients continue using the established fields; upgraded clients prefer the new extension when present and may adapt the old flat list for compatibility. KernelBridge must construct both representations from one resolver and test their intended parity. Do not dual-write independently sourced values.

The framework extension should contain only grants and identifiers needed by the installation. Do not include user records, role assignments, secrets, product usage counts, or unnecessary account data. Keep KernelBridge license status and expiry claims authoritative and outside the platform grant payload.

The extension is optional within the existing version-1 signed payload and must not replace the flat `entitlements` during rollout. KernelBridge may emit it only after a successful source-aware resolution and only while the license lifecycle is usable. Product SDKs verify the exact signed payload bytes using an out-of-band trusted key before validating the extension. A missing extension is compatible with legacy snapshots; a present but malformed or source-mismatched extension must be rejected.

## 5. Revisions and update semantics

The proposal distinguishes:

- `catalog_revision`: monotonically increasing revision of the product's published capability catalog.
- `source_revision`: monotonically increasing revision from the grant authority. KernelBridge maintains the plan-mapping revision for Direct; the platform supplies it for Platform Account.
- `entitlement_revision`: KernelBridge's monotonic revision of the effective installation/subscription entitlement snapshot, including accepted grant changes and relevant effective-time/lifecycle boundaries.

For platform updates:

- Every update is bound to the authenticated product and the server-verified license/account-link association.
- Include a stable idempotency key and `source_revision`.
- An identical retry is idempotent and returns the original accepted outcome.
- A lower revision is rejected as stale. Reuse of the same revision with different content is a conflict.
- KernelBridge records the accepted source revision and a digest/audit event; it does not treat an unaccepted update as effective.
- Grant updates cannot change `license_status`, validity dates, renewal, activation, seats, or revocation.

Exact conflict codes, retention periods, idempotency lifetime, and revision reset/recovery rules require agreement before implementation.

## 6. Product-side enforcement

The product server must verify/cache the signed snapshot and use one entitlement service rather than parse grant JSON independently in controllers.

An allowed operation generally requires all of:

```text
usable KernelBridge license
AND subscription grant for the capability
AND user permission for the operation
AND tenant/resource authorization
```

Product roles, user permissions, tenant membership, and resource authorization remain product-owned. Subscription grants do not grant actions to every user.

Product servers own live usage counts. They enforce limits against product data at the operation boundary, with atomic or transactional checks where concurrency can exceed a limit. A lower grant must not silently delete existing customer data; it should prevent additional usage as defined by product policy and offer remediation.

UI visibility is presentation only. APIs, domain services, jobs, reports/exports, integrations, bulk operations, and desktop/mobile server endpoints need appropriate enforcement. Denials should distinguish missing capability, reached limit, invalid/expired license, stale snapshot, and temporary service outage without exposing secrets.

## 7. Proposed update protocol

The platform-to-KernelBridge update is a new, server-to-server operation, separate from account-link and installation/device-grant endpoints. It must not be added by repurposing existing routes.

Conceptual request:

```json
{
  "schema_version": 1,
  "product_code": "MEDIFLOW",
  "license_uuid": "license-public-uuid",
  "account_link_uuid": "platform-link-public-uuid",
  "catalog_revision": 12,
  "source_revision": 18,
  "idempotency_key": "stable-operation-id",
  "grants": {
    "laboratory.results.export": { "type": "capability", "enabled": true },
    "branches.max": {
      "type": "limit",
      "state": "finite",
      "value": 5,
      "unit": "branches",
      "scope": "business"
    }
  }
}
```

This shape is illustrative and must be approved before endpoint design. The caller's product and scope come from its authenticated credential and must match the request. The license/account-link association is checked against KernelBridge records; client-supplied UUIDs alone do not prove authority.

Use narrowly scoped credentials that can publish catalog data or update platform grants only for the associated product. Keep them in product server secret storage, never in the app SDK or client bundle. Apply rate limits, validation, audit, replay/idempotency handling, and safe error responses.

## 8. Threat model and mitigations

| Threat | Required mitigation |
|---|---|
| Client alters its local entitlement cache or grant payload | Verify KernelBridge signature over exact payload bytes; protect local cache separately; never accept client-reported grants as authority. |
| Product platform tries to update another product's license or account | Product-scoped credential, server-side license/account-link association validation, scope checks, and audit trail. |
| Replayed, duplicated, stale, or reordered plan updates | Idempotency key, monotonic source revisions, same-revision digest conflict detection, and retry-safe responses. |
| Platform attempts to renew, reactivate, or un-revoke a license via grants | Grant update schema cannot write lifecycle fields; KernelBridge rechecks its lifecycle state when resolving and signing snapshots. |
| Hybrid source confusion or fallback to a more permissive source | Bind one authorized grant source per installation; require an audited source transition; never merge or fall back implicitly. |
| Unknown/malformed grant accidentally grants access | Strict schema validation; unknown capability denied; malformed/unsupported updates rejected; absent limit is not unlimited. |
| Downgrade races or concurrent usage exceeds a limit | Product-side atomic usage checks; monotonic effective revisions; defined over-limit behavior without data deletion. |
| Temporary network outage causes unbounded access | Cache verified snapshots with explicit freshness and bounded offline/grace rules; do not extend signed license validity. |
| Secret leakage through app bundles, logs, or diagnostics | Server-side credentials only; never log tokens, activation codes, private keys, or sensitive account payloads; redact update bodies. |
| Signing-key compromise or rotation mismatch | Private keys isolated server-side; public keys provisioned through trusted deployment; overlap rotation window; reject unknown keys. |
| Role/entitlement conflation | Keep user roles and permissions outside subscription grants; require both subscription capability and product authorization. |

Trust assumptions: KernelBridge signing keys and product server secret stores are protected; the product server enforces its own authorization and usage rules; TLS is correctly configured. This framework does not make a customer-controlled host or modified client tamper-proof.

## 9. Reliability and performance expectations

- Do not fetch KernelBridge on every product request. Verify and cache signed snapshots in the product server/SDK.
- Refresh by a bounded schedule and/or event-driven revision notification, with reconciliation polling for missed updates.
- Platform plan changes should be committed with a reliable outbox/queue so a local transaction cannot silently lose its KernelBridge update.
- Retry transient errors with bounded backoff and stable idempotency keys; surface pending/rejected updates for operator reconciliation.
- Index lookups by product, license, source, and revision as measured by actual query plans; avoid loading catalog trees for each authorization decision.
- Keep snapshots and grant payloads small. Do not duplicate display metadata unnecessarily.
- Monitor snapshot age, rejected update counts, revision lag, signature failures, and product-side entitlement denials without logging secrets.

Offline freshness duration, retry schedule, notification mechanism, retention, and operational SLOs need explicit agreement before rollout.

## 10. Compatibility and rollout constraints

- Keep all current tables, APIs, snapshots, and package methods usable.
- Do not edit historical migrations. Add new reversible migrations only after contract approval.
- Do not change the existing entitlement resolver to be authoritative in the initial rollout.
- Start with validators and shared fixtures, then shadow evaluation against current results.
- During transition, if both old and new representations are present, compute them from the same source and test parity.
- New clients may consume the extension; old clients continue consuming existing fields.
- Enable enforcement through an explicit server-side flag only after parity and rollback tests.
- Preserve existing roles, permissions, activation flows, and customer behavior unless a separate, approved compatibility change says otherwise.

## 11. Decisions required for Checkpoint 1

Review and approve or amend:

1. The source rules for Direct, Platform Account, and per-installation Hybrid.
2. The catalog hierarchy and whether catalog and plan mapping publish as one or separate resources.
3. The grant union and exact limit semantics, including unit, scope, period, unlimited, and downgrade behavior.
4. The additive `entitlement_framework` signed-claim extension while retaining current `version` and `entitlements`.
5. Catalog/source/effective revision ownership and stale/same-revision conflict behavior.
6. Credential scopes and license/account-link validation required for catalog and grant updates.
7. Snapshot freshness/offline policy, update retry/reconciliation expectations, and audit retention.
8. Whether this existing protocol repository is the initial home for schema fixtures/validators, with no separate package until duplication is demonstrated.

Checkpoint 1 was approved before Phase 2 began. The approval includes calendar time windows with an explicit IANA time zone for period-based limits. This authorizes only the Phase 2 conformance work described above; persistence, API, and activation/runtime integration remain gated by their later checkpoints.
