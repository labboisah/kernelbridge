# Electron and TypeScript licensing client

The alternative Electron/Node implementation is packaged at [`packages/kernelbridge/licensing-client-ts`](../packages/kernelbridge/licensing-client-ts). See its [README](../packages/kernelbridge/licensing-client-ts/README.md) for installation, SQLite, Electron `safeStorage`, IPC, periodic verification, offline grace, signature verification, and credential-security guidance.

It shares KernelBridge's `/api/v1` protocol and public identifiers with the Laravel client. Electron operational data remains in the product's own SQLite database; no central application tables are copied or shared.
