# TypeScript Licensing SDK Hardening Pipeline

This document sequences the work needed to bring `@kernelbridge/licensing-client-typescript` in line with the recent Laravel licensing hardening. It is an implementation plan, not a claim that the planned behavior is already shipped. The shared behavior contract remains in [LICENSING_CLIENT_SPEC.md](LICENSING_CLIENT_SPEC.md).

## Goals

- Support licenses that require machine binding.
- Make entitlement and local-state integrity checks consistent and fail closed.
- Detect licensing configuration drift and provide an explicit deployment-profile reprovision workflow.
- Preserve the Electron main-process security boundary and avoid presenting client-side checks as hardware attestation.
- Keep the existing activation, verification, offline grace, entitlement, and renewal APIs stable where possible.

## Phase 0: Agree the API and threat model

Before changing the SDK, confirm the server contract for each supported credential and license type.

- Confirm whether `machine_fingerprint` is optional for ordinary licenses and mandatory for device-bound licenses. Preserve server-side enforcement as the authority.
- Define how the SDK learns whether a license is device-bound. Prefer a field in the signed activation/verification response rather than guessing from a failed request.
- Decide whether central entitlements will include a server-signed payload. Define its canonical bytes, covered claims, algorithm, `key_id`, key rotation, and error response before making signed entitlements mandatory.
- Bind signed claims to at least product, license UUID, subscription UUID, entitlement values, status, expiry, and verification/effective time. The SDK must derive decisions from those verified claims, not from a separate unsigned entitlement map.
- Confirm that `tokenProvider` credentials can be fingerprinted without exposing their plaintext. If not, fingerprint a stable credential identifier or explicit provider-supplied credential version.
- Document the threat model: Electron `safeStorage` protects local data at rest for the OS user, but a customer controlling the machine or application can inspect or patch the client. Machine identity and deployment profiles are drift controls, not remote attestation.

**Exit criteria:** API payload examples and contract tests exist for bound and unbound licenses, signed and unsigned entitlements, and credential rotation. No client phase silently requires a signature the API does not yet return.

## Phase 1: Add stable machine identity

Introduce an injectable `MachineIdentityProvider` so the package can obtain a stable per-machine identifier without hard-coding operating-system commands into licensing services.

- Use a maintained platform implementation or a host-provided provider for supported Windows, Linux, and macOS deployments; do not invent a random identifier on each run.
- Hash and domain-separate the raw identifier before sending it. Never persist or log the raw OS identifier.
- Send `machine_fingerprint` on activation, verification, and deactivation. Keep `device_id` as the stable per-installation identifier; the machine fingerprint is an additional binding, not a replacement.
- Persist the fingerprint used for activation in the encrypted local state and compare it before allowing cached access.
- Fail activation closed with a specific `device_identity_unavailable` reason when a required identity cannot be obtained. Document supported containers and cloned-machine limitations.
- Allow deterministic test providers and ensure the host can inject a supported identity source where OS access is restricted.

**Exit criteria:** API integration tests show that a device-bound license activates and verifies on the original machine, rejects a different fingerprint, and that the SDK never sends the raw machine identifier.

## Phase 2: Make local integrity checks authoritative

Harden the local state path in `src/storage.ts` and the activation/verification services.

- Validate decrypted JSON against a versioned schema. Treat malformed data, unsupported versions, and decryption failure as untrusted local state.
- Preserve the public `LicenseState` shape where practical; add a state migration for any new device, signature, or profile fields.
- Verify the server entitlement signature whenever a snapshot is loaded or used, not only when it is first received. Require it in strict mode after the API contract from Phase 0 is deployed.
- Parse the verified signed payload and derive the feature/limit map from it, or prove canonical equality between the signed claims and the stored map before any decision.
- Ensure `hasUsableLicense`, `hasFeature`, `limit`, and all guards share the same validated snapshot path. No helper may read unverified entitlement data.
- Normalize trust failures to documented reason codes, especially `local_license_tampered`, `configuration_tampered`, `device_mismatch`, and `offline_grace_expired`.
- Ensure `activationRequirementReason()` distinguishes unreadable/corrupt state from a never-activated installation; keep protected access denied in both cases.
- Do not fall back to `productCode` as a cache signing secret. Require a dedicated cache key or a key derived from a protected OS secret; clearly distinguish local integrity detection from server authenticity.

**Exit criteria:** Mutating any persisted status, expiry, grace deadline, machine fingerprint, or entitlement value cannot grant access. Missing/invalid required signatures fail closed, and all guards report stable reasons.

## Phase 3: Implement the deployment-profile lifecycle

Add a separate `DeploymentProfileService` and profile store; do not silently refresh the trusted baseline during normal activation or verification.

### Profile contents

Store only the normalized profile and hashes/identifiers, never raw credentials. The profile fingerprint should cover:

- normalized API URL;
- uppercase product code;
- credential type and stable credential identifier/version or credential hash when available;
- application data scope and cache-signing-key identifier/hash;
- configured extra fingerprint values;
- profile schema version and creation/reprovision timestamp.

For Electron, protect the profile record with the same OS-backed vault as the license state and store it separately from the entitlement payload. The implementation must explain that this protects against accidental drift and casual edits, not a hostile local administrator.

### Lifecycle and enforcement

Expose explicit operations equivalent to:

- `initializeProfile()`: create the baseline during controlled installation/provisioning; refuse to overwrite an existing profile.
- `assertProfileReady()`: compare runtime configuration to the persisted baseline and throw/report `configuration_tampered` on mismatch.
- `profileStatus()`: return a redacted status and mismatch reason, never secrets or raw profile values.
- `reprovisionProfile({ confirmed: true })`: replace the baseline only through an explicit operator action; do not call this from activation, verification, startup, or normal cache reads.

Run `assertProfileReady()` before activation and verification, and before returning cached authorization decisions in strict mode. Preserve the configured offline grace only while the profile still matches. A configuration mismatch is not an outage and must not be bypassed by offline grace.

Provide a small CLI wrapper for initialization, status, and reprovisioning where practical. Reprovisioning must require an explicit confirmation flag for non-interactive use, default to no in interactive use, and must not be exposed to renderer IPC or an unauthenticated web route. Hosts that cannot use the CLI may call the explicit service API from their trusted main process or deployment tooling.

### Rollout behavior

- Support an explicit compatibility mode for existing installs with no profile. It may allow status/verification with a warning during migration, but must not silently persist a baseline on a routine request.
- Provide an operator migration step that initializes the profile after reviewing the active configuration.
- Make strict mode the documented production setting after migration; decide whether it becomes the default only in a versioned breaking release.
- On reprovision, record the profile version and timestamp, return a redacted audit result, and require re-activation if the changed values affect license identity or credential scope.

**Exit criteria:** Runtime configuration drift blocks protected decisions in strict mode; initialization and reprovisioning are explicit, testable, and cannot be triggered by ordinary SDK calls.

## Phase 4: Align public behavior and host integration

- Keep programmatic `checkActive()` and `assertActive()` as the framework-neutral interface.
- Add documented adapters/examples for Electron main-process startup and shutdown, IPC decisions, and server-side Node middleware. Do not add renderer access to the SDK or raw state.
- Expose redacted status fields needed by host UIs: active/status/reason, product code, expiry, last verification, offline-grace deadline, profile status, and entitlement revision where available.
- Keep renewal notices non-blocking and feature/limit checks local to the verified snapshot.
- Update the shared spec only where the API contract is genuinely common to Laravel, TypeScript, and other clients; keep Electron-only profile storage details in TypeScript documentation.

**Exit criteria:** Activation pages remain reachable, protected host workflows fail closed, API error reasons map to documented messages, and no token, license key, decrypted state, or raw entitlement payload crosses IPC or enters logs.

## Phase 5: Verification and release

Add or extend focused tests before changing release metadata.

- Unit tests for identity provider behavior, profile normalization, profile initialization, mismatch, and confirmed reprovisioning.
- Storage tests for corrupted ciphertext, malformed JSON, old state migration, tampered status/expiry/grace fields, and profile separation.
- Signature tests for missing, invalid, rotated-key, wrong-product, wrong-license, and entitlement-map mismatch cases.
- Client tests for required machine-fingerprint headers/body fields and server rejection on machine mismatch.
- Behavior tests for offline grace, explicit central rejection, deactivation, config drift, profile drift, feature/limit guards, and renewal notices.
- Platform integration tests on supported operating systems, including Electron `safeStorage` unavailable behavior and packaged-app startup.
- Run package typecheck, tests, build, package audit, and API contract tests. Then run the host application's licensing tests against the candidate package.
- Update package README, shared specification, changelog, setup guidance, and version together. Release only after migration and compatibility behavior are documented.

**Release gate:** no unverified entitlement path can grant access; bound-license activation works on supported platforms; config/profile drift fails closed in strict mode; existing customers have an explicit profile migration path; and all published API/signature requirements are met by the server.

## Recommended delivery order

1. Lock the API and threat-model decisions in Phase 0.
2. Ship server support for signed entitlement claims if strict server-authenticated offline entitlements are required.
3. Deliver machine identity and request compatibility in Phase 1.
4. Deliver validated local state and signature enforcement in Phase 2, initially behind explicit strict-signature configuration if the API rollout is staged.
5. Deliver profile storage, explicit initialization, drift enforcement, and reprovisioning in Phase 3.
6. Finish host integration, documentation, migration, and release gates in Phases 4 and 5.

Do not combine this work with the MarketLens installation-grant authentication follow-up. That is a separate API credential flow and should have its own contract and rollout plan.