# Electron and TypeScript licensing client

The alternative Electron/Node implementation is packaged at [`packages/kernelbridge/licensing-client-typescript`](../packages/kernelbridge/licensing-client-typescript). See its [README](../packages/kernelbridge/licensing-client-typescript/README.md) for installation, SQLite, Electron `safeStorage`, IPC, periodic verification, offline grace, activation gating, configuration tamper detection, signature verification, and credential-security guidance.

It shares KernelBridge's `/api/v1` protocol and public identifiers with the Laravel client. Electron operational data remains in the product's own SQLite database; no central application tables are copied or shared.

The TypeScript package now mirrors the Laravel client contract: encrypted local state, fail-closed activation decisions, device activation enforcement through the central API, offline grace, entitlement checks, and re-activation when sensitive configuration changes. Use `LicenseGuard.checkActive()` or `assertActive()` in the Electron main process or server-side Node middleware before opening protected pages or running protected operations. Use `renewalNotice()` to show non-blocking 3, 2, and 1 month renewal reminders.



