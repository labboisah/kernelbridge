# KernelBridge Django Licensing Client

Django app for KernelBridge license activation, verification, local entitlement checks, route protection, and renewal notices.

Reference spec: [`docs/LICENSING_CLIENT_SPEC.md`](../../../docs/LICENSING_CLIENT_SPEC.md)

## Package

```bash
pip install kernelbridge-licensing-django
```

For local development from this monorepo:

```bash
cd packages/kernelbridge/licensing-client-django
pip install -e .
```

## Django setup

Add the app, URLs, and optional middleware:

```python
INSTALLED_APPS = [
    # ...
    "kernelbridge_licensing",
]

urlpatterns = [
    path("license/", include("kernelbridge_licensing.urls")),
    # ...
]

MIDDLEWARE = [
    # place after session/auth middleware if your activation page uses them
    "kernelbridge_licensing.middleware.KernelBridgeLicenseMiddleware",
    # ...
]
```

Run migrations:

```bash
python manage.py migrate
```

## Settings

```python
KERNELBRIDGE_LICENSING = {
    "API_URL": "https://kernelbridge.com/api/v1",
    "PRODUCT_CODE": "ALIGNEX",
    "API_TOKEN": env("KERNELBRIDGE_API_TOKEN"),
    "CACHE_SIGNING_KEY": env("KERNELBRIDGE_CACHE_SIGNING_KEY"),
    "ENCRYPTION_KEY": env("KERNELBRIDGE_LICENSE_ENCRYPTION_KEY", default=env("KERNELBRIDGE_CACHE_SIGNING_KEY")),
    "VERIFICATION_INTERVAL_SECONDS": 900,
    "OFFLINE_GRACE_SECONDS": 259200,
    "ACTIVATION_PATH": "/license/",
    "ACTIVATION_URL_NAME": "kernelbridge_licensing:activation",
    "PROTECT_WEB_ROUTES": True,
    "PROTECT_EXCEPT": ["/license/", "/admin/login/", "/accounts/login/", "/static/", "/media/"],
    "RENEWAL_NOTICE_ENABLED": True,
    "RENEWAL_NOTICE_MONTHS": [3, 2, 1],
}
```

Required environment values for customer apps:

```env
KERNELBRIDGE_API_TOKEN=service_installation_token
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_CACHE_SIGNING_KEY=change-me-to-a-long-random-secret
KERNELBRIDGE_LICENSE_ENCRYPTION_KEY=change-me-to-another-long-random-secret
```

## What the package provides

- `kernelbridge_licensing` Django app config.
- `KernelBridgeLicenseClient` for `/licenses/activate`, `/licenses/verify`, `/licenses/deactivate`, `/subscriptions/status`, and `/entitlements`.
- Encrypted local DB state in `kernelbridge_license_states`.
- HMAC signatures for encrypted state, entitlement snapshots, and sensitive config fingerprints.
- Fail-closed `KernelBridgeLicenseService` guard methods.
- Built-in `/license/` activation page and JSON endpoints.
- Optional global middleware route protection with browser redirects and API `402` JSON responses.
- Decorators for view-level protection.
- Management commands for activate, verify, and deactivate.
- Renewal notice context helper.

## Activation UI and API

The built-in activation page is available at `/license/` when the URLs are included as shown above.

JSON activation:

```http
POST /license/activate/
Content-Type: application/json

{
  "license_key": "KB-...",
  "device_name": "Office Server"
}
```

Manual verification:

```http
POST /license/verify/
```

Deactivate this installation:

```http
POST /license/deactivate/
```

## Protecting routes

Turn on automatic route protection:

```python
KERNELBRIDGE_LICENSING = {
    "PROTECT_WEB_ROUTES": True,
    "PROTECT_EXCEPT": ["/license/", "/accounts/login/", "/static/"],
}
```

Browser requests are redirected to the activation UI. API requests return `402 Payment Required`:

```json
{
  "error": "license_required",
  "reason": "license_not_activated",
  "message": "Activate this application before continuing."
}
```

Leave `PROTECT_WEB_ROUTES` false if the host app wants manual control with decorators or direct service calls.

## View decorators

```python
from kernelbridge_licensing.decorators import (
    requires_kernelbridge_feature,
    requires_kernelbridge_license,
    requires_kernelbridge_limit,
)


@requires_kernelbridge_license
def reports(request):
    ...


@requires_kernelbridge_feature("finance")
def finance_dashboard(request):
    ...


@requires_kernelbridge_limit("users", 25)
def import_users(request):
    ...
```

## Programmatic checks

```python
from kernelbridge_licensing.services import KernelBridgeLicenseService

licenses = KernelBridgeLicenseService()
licenses.assert_active()
licenses.assert_feature("billing")
licenses.assert_limit("monthly_invoices", 100)
```

Renewal notice:

```python
notice = KernelBridgeLicenseService().renewal_notice()
if notice:
    # Show notice.level, notice.message, and notice.expires_at in the client app.
    pass
```

Template context processor:

```python
TEMPLATES[0]["OPTIONS"]["context_processors"] += [
    "kernelbridge_licensing.context_processors.kernelbridge_renewal_notice",
]
```

Then use `kernelbridge_renewal_notice` in templates.

## Management commands

```bash
python manage.py kernelbridge_activate KB-... --device-name="Office Server"
python manage.py kernelbridge_verify --force
python manage.py kernelbridge_deactivate
```

Run `kernelbridge_verify` from cron, Celery beat, or the host app scheduler using the configured interval.

## Fail-closed behavior

Protected access is denied when:

- the app has not been activated;
- KernelBridge explicitly rejects the license;
- local state cannot be decrypted;
- entitlement state was tampered;
- offline grace has expired;
- sensitive config changed after activation;
- status is expired, suspended, cancelled, invalid, or deactivated.

The device activation limit error is normalized to:

```text
This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.
```

## Development

```bash
pip install -e .[dev]
pytest
```

The tests use a fake KernelBridge client and verify encrypted state, cached verification, config tamper detection, renewal notices, route protection, decorators, and device-limit messaging.
