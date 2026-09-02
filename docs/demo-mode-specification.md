# KernelBridge Demo Mode Specification

## Purpose

KernelBridge is the control plane for demo policy and analytics. Each product remains the data plane: it owns demo users, sample records, tenant boundaries, reset scripts, and its database. KernelBridge must never receive product database credentials or directly mutate a product database.

## Trust model

Products authenticate to `/api/v1` with their independent machine credential. They fetch `GET /demo/config`, cache it briefly, and fail closed for dangerous operations if configuration cannot be refreshed. A configuration response contains a monotonically increasing `config_version`.

The `hard_blocks` section is mandatory and cannot be weakened by local product settings. Products must always prevent changes to core demo credentials, API credential access, removal of protected administrators, production SMS/email, real payments, cross-organization access, and server configuration.

## Enforcement contract

Map every sensitive action to one capability: `create`, `edit`, `delete`, `export`, `email`, `sms`, `payment`, `configuration`, or `user_management`. Enforce capability checks in the product backend through middleware, policies, or domain services. UI hiding is supplementary and is never sufficient authorization.

When email or SMS is disabled, products may show a simulated preview but must not enqueue a production provider job. When payment is disabled, products may simulate an approved/declined result but must not initialize a gateway transaction. Demo tenants must be selected server-side and every query must remain tenant-scoped.

Protected roles and accounts cannot be deleted, demoted, have credentials changed, or receive API-management permissions. Products should keep demo authentication separate from production customer authentication where practical.

## Session protocol

1. Product obtains the current demo configuration.
2. Product starts a session with `POST /demo/sessions`, supplying a stable anonymous browser identifier. KernelBridge stores only its HMAC digest.
3. Product emits idempotent events to `POST /demo/events` using a unique `event_id`.
4. Product ends the session through `POST /demo/sessions/{session_uuid}/end`.

Supported event types are `session_start`, `page_view`, `feature_used`, `workflow_completed`, `cta_clicked`, `request_full_version`, and `session_end`. Metadata must not contain passwords, access tokens, patient data, student records, payment data, or other personal/sensitive operational records.

## Public access tokens

Profiles may require a limited demo access token. Tokens are issued centrally, displayed once, stored as hashes, and may have expiry and usage limits. The product passes the token only when starting the central demo session. It must never persist the raw token in logs or analytics.

## Reset protocol

KernelBridge publishes a cron expression, instruction text, instruction version, and optional next expected reset time. The product schedules and executes its own idempotent reset command locally, restoring an approved fixture snapshot while preserving protected demo administrators. After success it posts the current instruction version to `/demo/resets/acknowledge`.

A reset failure must alert the product operations team and must not trigger remote database access from KernelBridge. Reset scripts should use transactions, prevent execution outside the designated demo environment, and maintain backups appropriate to the product.

## Recommended product implementation

- `DemoModeConfigClient`: retrieves and caches versioned configuration.
- `EnsureDemoCapability` middleware: performs backend capability enforcement.
- `DemoTenantScope`: guarantees organization isolation.
- `DemoCommunicationGateway`: simulation/sink adapter for email and SMS.
- `DemoPaymentGateway`: simulation-only payment adapter.
- `DemoTelemetryClient`: queues non-sensitive, idempotent events.
- `demo:reset`: product-owned, environment-guarded reset command.

Cache configuration for no more than five minutes. If the cache is unavailable or expired, allow read-only navigation but deny export, communication, payment, configuration, user management, credential changes, and destructive actions.
