# KernelBridge Licensing MCP Server

The MCP foundation lives in [`packages/kernelbridge/licensing-mcp-server`](../packages/kernelbridge/licensing-mcp-server). It exposes tools for support lookup, customer onboarding, license operations, and documentation assistance.

## Package

```bash
cd packages/kernelbridge/licensing-mcp-server
npm install
npm run build
```

Run the stdio server:

```bash
node dist/server.js
```

## Environment

The MCP server reads environment variables from the process that starts it. It does not automatically load `.env`. For most MCP clients, configure these values inside the client JSON `env` block. For manual runs, export them in the terminal first.

Example env templates live in:

```text
packages/kernelbridge/licensing-mcp-server/deployment/env/
```

```env
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_API_TOKEN=replace-with-mcp-scoped-token
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_DOCS_ROOT=C:/laragon/www/kernelbridge
KERNELBRIDGE_MCP_ALLOW_MUTATIONS=false
```

PowerShell:

```powershell
$env:KERNELBRIDGE_API_URL="https://kernelbridge.com/api/v1"
$env:KERNELBRIDGE_API_TOKEN="replace-with-mcp-token"
$env:KERNELBRIDGE_PRODUCT_CODE="ALIGNEX"
$env:KERNELBRIDGE_DOCS_ROOT="C:/laragon/www/kernelbridge"
$env:KERNELBRIDGE_MCP_ALLOW_MUTATIONS="false"
kernelbridge-licensing-mcp
```

Bash:

```bash
export KERNELBRIDGE_API_URL="https://kernelbridge.com/api/v1"
export KERNELBRIDGE_API_TOKEN="replace-with-mcp-token"
export KERNELBRIDGE_PRODUCT_CODE="ALIGNEX"
export KERNELBRIDGE_DOCS_ROOT="/srv/kernelbridge/docs"
export KERNELBRIDGE_MCP_ALLOW_MUTATIONS="false"
kernelbridge-licensing-mcp
```

Mutation tools stay disabled unless `KERNELBRIDGE_MCP_ALLOW_MUTATIONS=true`.

Write tools also require human confirmation fields in the tool input:

- `confirmed_by`: the operator or approver name/email.
- `confirmation_note`: the reason the write action was approved.

The platform stores these in `mcp_tool_confirmations`, returns a `confirmation_uuid`, links that UUID in the MCP audit metadata, and logs high-risk mutation alerts.

## Use Cases

### Admin/support assistant

Tools:

- `kernelbridge_find_customer`
- `kernelbridge_list_customer_services`
- `kernelbridge_explain_activation_issue`

Purpose: help support staff identify customers, services, activations, and activation failure reasons without touching the database directly.

### Developer/customer onboarding helper

Tools:

- `kernelbridge_generate_setup_template`
- `kernelbridge_get_service_setup`

Purpose: generate setup instructions for Laravel, TypeScript, Spring Boot, and Django client apps.

### Assistant workflows

Tools:

- `kernelbridge_activation_support_playbook`
- `kernelbridge_prepare_onboarding_workflow`
- `kernelbridge_analyze_customer_renewal_risk`
- `kernelbridge_generate_safe_ticket_summary`
- `kernelbridge_check_package_compatibility`

Purpose: compose the lower-level MCP API tools into support-ready workflows, onboarding checklists, renewal-risk views, ticket-safe summaries, and package compatibility guidance.

These tools are read-only. They do not expose raw license keys, API tokens, cache signing keys, or credential secrets in generated summaries.

### License operations

Tools:

- `kernelbridge_list_license_activations`
- `kernelbridge_issue_license`
- `kernelbridge_deactivate_license_activation`

Purpose: list devices, issue eligible licenses, and deactivate installations. Write tools must require explicit MCP mutation enablement and platform-side authorization.

### Documentation assistant

Tools:

- `kernelbridge_list_docs`
- `kernelbridge_read_doc`

Purpose: expose the licensing spec, package docs, OpenAPI file, and infrastructure guide to MCP clients.

## Platform API Layer

The MCP server is API-backed by design. Do not point MCP tools directly at the database. Live admin/support tools are available through scoped MCP API endpoints. They must keep all of these controls:

- dedicated `mcp:*` scopes on API clients or a separate MCP token model;
- product/customer authorization checks on every request;
- audit events for every read and write tool call;
- per-tool MCP rate limits;
- human confirmation records for write tools;
- no raw license keys or API tokens in read responses;
- raw license keys only on explicit issue/rotate operations;
- rate limits separate from product activation clients;
- request logging with tool name, actor/client, target UUID, and mutation flag.

Implemented endpoints:

```text
GET  /api/v1/mcp/customers/search?query=&limit=
GET  /api/v1/mcp/customers/{customer_uuid}/services
GET  /api/v1/mcp/licenses/explain-activation-issue
GET  /api/v1/mcp/services/{subscription_uuid}/setup?runtime=laravel|typescript|spring-boot|django
GET  /api/v1/mcp/licenses/{license_uuid}/activations
POST /api/v1/mcp/licenses/issue
POST /api/v1/mcp/license-activations/{activation_uuid}/deactivate
```

## Admin Operator UI

Use `/admin/mcp` to manage operator tokens for MCP clients.

Available presets:

- Read-only support: `mcp:read`.
- Onboarding helper: `mcp:read`.
- License operator: `mcp:read`, `mcp:write`.
- Documentation only: `mcp:docs`.

Tokens are only displayed once after issuing or rotation. Use the generated environment block or MCP client JSON snippet from the page. Normal product activation credentials, including legacy `*` credentials, are intentionally rejected by MCP API routes.

The same page includes rotation, revocation, and MCP audit filtering by tool, mutation flag, credential, and date range.

## Client Configuration Example

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

## Distribution

See [MCP distribution guide](MCP_DISTRIBUTION.md) for private registry publishing, checksums, deployment modes, token rules, and release checklists.

## Verification

```bash
npm run typecheck
npm test
npm run build
```

## Roadmap

See [MCP implementation phases](MCP_IMPLEMENTATION_PHASES.md).







