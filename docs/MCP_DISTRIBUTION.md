# KernelBridge MCP Distribution Guide

This guide prepares `@kernelbridge/licensing-mcp-server` for private distribution without committing registry credentials or raw MCP tokens.

## Release gate

Run from `packages/kernelbridge/licensing-mcp-server`:

```bash
npm ci
npm run typecheck
npm test
npm run build
npm run pack:dry-run
npm pack
npm run release:checksums
```

Keep the generated `.tgz` and `SHA256SUMS` together. Publish from CI or a release workstation that supports npm provenance/signing.

## Private npm registry

Recommended publish command:

```bash
npm publish --access restricted --provenance --registry https://registry.npmjs.org
```

For a private registry, replace the registry URL with the approved KernelBridge registry. Never store npm tokens in this repo. Use CI secrets or a short-lived local npm login.

## Artifact integrity

The package provides `scripts/write-checksums.mjs` and `npm run release:checksums` to generate `SHA256SUMS` for local `.tgz` artifacts. Store the checksum file with the release record.

When a release is downloaded manually, verify:

```bash
sha256sum -c SHA256SUMS
```

On Windows PowerShell, compare the package hash with:

```powershell
Get-FileHash .\kernelbridge-licensing-mcp-server-0.1.0.tgz -Algorithm SHA256
```

## Environment templates

The MCP server does not auto-load `.env` files. The files under `packages/kernelbridge/licensing-mcp-server/deployment/env` are copy/reference templates for shells, process managers, CI secrets, or MCP client `env` blocks.

Template files:

- `local.env.example`
- `internal-server.env.example`
- `managed-support.env.example`

MCP client JSON snippets live in `packages/kernelbridge/licensing-mcp-server/deployment/mcp-client` and can be pasted into your MCP client configuration after replacing the token and paths.

## Deployment modes

### Local developer

Use `deployment/env/local.env.example` and `deployment/mcp-client/local.json`.

Use this for local testing against a local KernelBridge API. Keep mutations off unless you are testing write tools intentionally.

### Internal server

Use `deployment/env/internal-server.env.example` and `deployment/mcp-client/internal-server.json`.

Install the package globally or in a controlled tools directory:

```bash
npm install -g @kernelbridge/licensing-mcp-server --registry https://registry.npmjs.org
```

Create the MCP API token from `/admin/mcp` with the narrowest preset. Store the token in the process manager secret store or environment file with owner-only permissions.

### Managed support

Use `deployment/env/managed-support.env.example` and `deployment/mcp-client/managed-support.json`.

Use read-only support or onboarding-helper tokens by default. License operator tokens should be time-limited, IP-limited where possible, and kept separate from support read tokens.

## Token rules

- Generate MCP tokens only from `/admin/mcp`.
- Use documentation-only tokens for docs-only clients.
- Use read-only support tokens for normal support triage.
- Use license-operator tokens only when write tools are required.
- Keep `KERNELBRIDGE_MCP_ALLOW_MUTATIONS=false` by default.
- Rotate tokens after staff changes, client compromise, or support-vendor offboarding.
- Revoke tokens immediately when exposed.

## Release checklist

1. Confirm Phase 1-5 verification commands are green.
2. Confirm package version and changelog/release notes.
3. Run `npm run pack:dry-run` and inspect included files.
4. Run `npm pack` and `npm run release:checksums`.
5. Publish with restricted access and provenance from CI/release workstation.
6. Record package version, git commit, artifact hash, publisher, and publish timestamp.
7. Install in a staging MCP client using a read-only support token.
8. Verify customer lookup, onboarding workflow, safe ticket summary, and docs tools.
9. Verify write tools remain disabled unless mutation env is enabled.
10. For production write-tool use, verify confirmation records appear in KernelBridge admin/database.

