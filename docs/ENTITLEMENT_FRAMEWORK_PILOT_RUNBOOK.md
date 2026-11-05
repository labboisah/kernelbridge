# Entitlement Framework Pilot Runbook

## Status and scope

This runbook supports Phase 9 of the entitlement-framework implementation guide. The current work is preparation and shadow validation only; it is not approval to enable structured snapshots or change a production installation.

The proposed Direct pilot is MediFlow. The proposed Platform Account pilot is MarketLens, with the MarketLens Business Server as the product-side server. MarketLens activation-code exchange and KernelBridge's platform grant API are present, but reliable plan-change delivery from the platform (including a transactional outbox, retry/reconciliation, and restart recovery) is not yet demonstrated. Neither pilot is accepted until the Phase 8 gates and this runbook's go/no-go checklist pass.

Local validation currently covers the resolver, Direct and Platform Account signed snapshots, platform catalog/update API validation, idempotency, and source binding. The focused KernelBridge suites pass (33 tests, 217 assertions), and the MediFlow licensing/role suites pass (24 tests, 139 assertions). These are automated local tests, not staging parity, load/concurrency evidence, product-owner approval, or a production rollout.

## Feature-flag behavior

Both flags default to `false` in `config/entitlement_framework.php`.

| Flag | Behavior | Pilot policy |
|---|---|---|
| `ENTITLEMENT_FRAMEWORK_SHADOW_ENABLED` | Resolves the proposed source grant and logs a comparison; it does not add the framework extension to the signed snapshot. | May be enabled only in an isolated staging environment after owner approval. |
| `ENTITLEMENT_FRAMEWORK_SNAPSHOT_ENABLED` | Adds the structured grant extension to signed installation snapshots when source resolution and license lifecycle checks pass. | Keep disabled until Phase 8 is accepted and a separate, product-owner-approved pilot change is authorized. |

After changing a flag, rebuild Laravel's config cache and restart long-lived application and queue processes. Verify the effective configuration from the deployed runtime without printing credentials. Test rollback by disabling the relevant flag, clearing/rebuilding the config cache, restarting processes, and confirming that newly signed snapshots omit the extension while legacy entitlements remain present.

## Shadow comparison and monitoring

KernelBridge emits `entitlement_framework.shadow_comparison` when shadow evaluation runs. Monitor:

- `status`, `source`, `licensing_mode`, `lifecycle_eligible`
- `catalog_revision` and `source_revision`
- `legacy_grant_count`, `proposed_grant_count`, and added/removed/changed counts
- `added_keys`, `removed_keys`, `changed_keys`, and `proposed_digest`

The event intentionally does not include installation UUIDs, credentials, grant payloads, or customer account data. Treat capability keys and digests as operational metadata; do not add customer-identifying values to log context.

When snapshot inclusion is requested but resolution is unsafe or incomplete, monitor `entitlement_framework.snapshot_not_attached`. The extension is omitted; do not suppress this warning or reinterpret it as a successful grant.

Platform catalog and grant submissions are recorded in the immutable `entitlement_framework_audits` table. Operator reporting should aggregate by product, source, operation, outcome, error code, and time window. Do not select or export license/account identifiers or payload digests for routine dashboards. Alert on rejected updates, revision conflicts, missing source bindings, catalog mismatches, and sustained gaps between expected and accepted source revisions.

MediFlow installation administrators can see safe license and snapshot freshness diagnostics under Installation Setup. Those diagnostics are product-side status, not proof that a Platform Account producer has delivered its latest plan revision.

## Operational procedures

### Platform update retry and reconciliation

1. Retain the exact source revision and idempotency key for an update attempt.
2. Retry only transient transport/server failures with bounded exponential backoff and jitter; preserve both the payload and idempotency key.
3. Replaying the same key with the same payload is idempotent. Reusing the key with different content is a conflict and must be investigated, not retried with modified data.
4. For a stale revision or catalog mismatch, fetch the current accepted revision/catalog and produce a newly validated update with a new idempotency key.
5. Reconcile platform plan state against the latest accepted KernelBridge source revision. Keep failures visible as pending/rejected work until reconciled; never report delivery success based only on queue acceptance.
6. Do not enable the Platform Account pilot until a transactional outbox records the platform plan change atomically and tests prove retry, duplicate delivery, outage recovery, worker restart recovery, and pending-update visibility.

### Signing-key rotation

1. Provision the new public key to the product SDK trust map under its new key ID before KernelBridge starts signing with it.
2. Verify a test snapshot signed by the new key and confirm an unknown key still fails closed.
3. Switch the signer to the new key only after all pilot environments trust it. Keep the previous public key trusted for the maximum supported lifetime of snapshots and offline caches.
4. Monitor signature failures and snapshot age during overlap. Remove the old trust key only after the overlap window and recovery approval.
5. Never place private signing keys, API credentials, activation codes, or signed payloads in logs or support tickets.

### Licensing outage and stale snapshot

- Keep current license lifecycle rules authoritative. A structured grant never extends license, subscription, or offline-grace validity.
- Follow the SDK's bounded offline policy; do not manually refresh timestamps or expand grace to restore access.
- Distinguish missing/invalid grants from stale snapshots and temporary service outages in operator and product diagnostics.
- After recovery, force the normal verification/update path and confirm the signed revision and product-side cache age advance before closing the incident.
- Do not delete customer data or revoke user roles as an outage response.

### Source transition

- A change between Direct and Platform Account authority requires the authorized account-link/activation binding defined by the contract.
- Do not infer the source from a changed product policy, a plan display name, or the presence of a stale platform link.
- Keep the previous accepted state and audit history. Reconcile the platform link, source revision, catalog revision, and signed snapshot before treating the transition as complete.
- On conflict or ambiguous historical binding, fail closed for the new structured grant, retain the established legacy behavior, and escalate for explicit reconciliation.

## Rollback

1. Disable `ENTITLEMENT_FRAMEWORK_SNAPSHOT_ENABLED` first to stop attaching structured grants to newly signed snapshots.
2. If shadow evaluation itself is causing operational impact, also disable `ENTITLEMENT_FRAMEWORK_SHADOW_ENABLED`.
3. Rebuild config cache and restart application and queue processes.
4. Verify a newly generated snapshot still contains the legacy entitlement field and omits `entitlement_framework`; confirm product access follows the existing licensing path.
5. Keep catalog revisions, source grants, and immutable audit history. Do not roll back by deleting records or editing historical migrations.
6. Record the incident, last accepted revisions, affected environments, and recovery owner before any re-enable request.

## Go/no-go checklist

Do not expand rollout until all items are evidenced and approved:

- [ ] Phase 8 server-boundary tests pass for each participating product, including roles/permissions remaining independent and limits enforced atomically where limits are published.
- [ ] MediFlow's Direct catalog is published and mapped to its live Direct plans; any numeric limit has a product-owned usage counter and transactional creation boundary.
- [ ] MarketLens has a product-owned catalog and reliable transactional outbox/reconciliation for Platform Account grant updates.
- [ ] Staging shadow results show reviewed parity or documented, product-owner-approved differences over an agreed observation window.
- [ ] Monitoring and operator diagnostics cover validation failures, stale revisions, snapshot age, signature failures, denials, and limit enforcement without secrets or sensitive account payloads.
- [ ] Support owners have exercised retry, outage, key-rotation, source-transition, and recovery procedures.
- [ ] Each product owner has performed and signed off a rollback drill in staging.
- [ ] The exact capability set, environments, rollout window, and responsible operators are explicitly approved before snapshot inclusion is enabled.

The current checkout does not meet all of these conditions. Keep both flags disabled outside isolated tests and shadow-only staging approved by the product owners.
