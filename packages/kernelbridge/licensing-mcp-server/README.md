# KernelBridge Licensing MCP Server

MCP server foundation for KernelBridge licensing support, customer onboarding, license operations, and developer documentation.

## Package

```bash
npm install @kernelbridge/licensing-mcp-server
```

Local development from this monorepo:

```bash
cd packages/kernelbridge/licensing-mcp-server
npm install
npm run build
```

## Configuration

```env
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_API_TOKEN=replace-with-admin-or-support-scoped-token
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_DOCS_ROOT=C:/laragon/www/kernelbridge
KERNELBRIDGE_MCP_ALLOW_MUTATIONS=false
```

Mutation tools are disabled unless `KERNELBRIDGE_MCP_ALLOW_MUTATIONS=true`. Keep production tokens scoped to the smallest support/admin role that can perform the needed operation.

## Run

```bash
kernelbridge-licensing-mcp
```

Example MCP client config:

```json
{
  "mcpServers": {
    "kernelbridge-licensing": {
      "command": "node",
      "args": ["C:/laragon/www/kernelbridge/packages/kernelbridge/licensing-mcp-server/dist/server.js"],
      "env": {
        "KERNELBRIDGE_API_URL": "https://kernelbridge.com/api/v1",
        "KERNELBRIDGE_API_TOKEN": "...",
        "KERNELBRIDGE_DOCS_ROOT": "C:/laragon/www/kernelbridge",
        "KERNELBRIDGE_MCP_ALLOW_MUTATIONS": "false"
      }
    }
  }
}
```

## Use Cases And Tools

### 1. Admin/support assistant

- `kernelbridge_find_customer`
- `kernelbridge_list_customer_services`
- `kernelbridge_explain_activation_issue`

These tools call KernelBridge support/admin API endpoints and should return customer-safe summaries for staff triage.

### 2. Developer/customer onboarding helper

- `kernelbridge_generate_setup_template`
- `kernelbridge_get_service_setup`

Use these to produce Laravel, TypeScript, Spring Boot, or Django install commands and environment/configuration templates. The generic template tool works without customer data.

### 3. License operations

- `kernelbridge_list_license_activations`
- `kernelbridge_issue_license`
- `kernelbridge_deactivate_license_activation`

Write tools are guarded by `KERNELBRIDGE_MCP_ALLOW_MUTATIONS`. The platform endpoints should also enforce authorization and audit logging.

### 4. Documentation assistant

- `kernelbridge_list_docs`
- `kernelbridge_read_doc`

Documentation is read from `KERNELBRIDGE_DOCS_ROOT` and includes the shared client spec, package docs, OpenAPI file, and multi-product infrastructure guide.

## Platform API Endpoints

The server is intentionally API-backed rather than database-backed. These KernelBridge endpoints back the data tools:

```text
GET  /api/v1/mcp/customers/search?query=&limit=
GET  /api/v1/mcp/customers/{customer_uuid}/services
GET  /api/v1/mcp/licenses/explain-activation-issue
GET  /api/v1/mcp/services/{subscription_uuid}/setup?runtime=laravel|typescript|spring-boot|django
GET  /api/v1/mcp/licenses/{license_uuid}/activations
POST /api/v1/mcp/licenses/issue
POST /api/v1/mcp/license-activations/{activation_uuid}/deactivate
```

Each endpoint should return public UUIDs only, redact raw tokens by default, and write an audit event for every mutation.

## Distribution

Release and deployment guidance lives in [docs/MCP_DISTRIBUTION.md](../../../docs/MCP_DISTRIBUTION.md). This package also includes environment templates in `deployment/env` and MCP client snippets in `deployment/mcp-client`.

Useful release checks:

```bash
npm run typecheck
npm test
npm run build
npm run pack:dry-run
npm pack
npm run release:checksums
```

## Development

```bash
npm run typecheck
npm test
npm run build
```
