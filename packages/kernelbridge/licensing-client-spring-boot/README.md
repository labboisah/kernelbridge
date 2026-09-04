# KernelBridge Spring Boot Licensing Starter

Spring Boot starter for KernelBridge license activation, verification, local entitlement checks, route protection, and renewal notices.

Reference spec: [`docs/LICENSING_CLIENT_SPEC.md`](../../../docs/LICENSING_CLIENT_SPEC.md)

## Package

```xml
<dependency>
  <groupId>com.kernelbridge</groupId>
  <artifactId>kernelbridge-licensing-spring-boot-starter</artifactId>
  <version>1.0.0</version>
</dependency>
```

For local development from this monorepo:

```bash
cd packages/kernelbridge/licensing-client-spring-boot
mvn install
```

Then add the dependency above to the client Spring Boot application.

## Configuration

```yaml
kernelbridge:
  licensing:
    api-url: https://kernelbridge.com/api/v1
    product-code: ALIGNEX
    api-token: ${KERNELBRIDGE_API_TOKEN}
    cache-signing-key: ${KERNELBRIDGE_CACHE_SIGNING_KEY}
    encryption-key: ${KERNELBRIDGE_LICENSE_ENCRYPTION_KEY:${KERNELBRIDGE_CACHE_SIGNING_KEY}}
    verification-interval: PT15M
    offline-grace: PT72H
    state-path: ${user.home}/.kernelbridge/licensing-state.json
    activation-path: /license
    protect-web-routes: true
    protect-except:
      - /license/**
      - /login
      - /register
      - /password/**
      - /assets/**
      - /error
    renewal-notice:
      enabled: true
      months: [3, 2, 1]
```

Required environment values for customer apps:

```env
KERNELBRIDGE_API_TOKEN=service_installation_token
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_CACHE_SIGNING_KEY=change-me-to-a-long-random-secret
KERNELBRIDGE_LICENSE_ENCRYPTION_KEY=change-me-to-another-long-random-secret
```

## What the starter provides

- `KernelBridgeLicensingProperties` using `kernelbridge.licensing.*`.
- `KernelBridgeLicenseClient` for `/licenses/activate`, `/licenses/verify`, `/licenses/deactivate`, `/subscriptions/status`, and `/entitlements`.
- AES-GCM encrypted local state persisted to `state-path`.
- HMAC signatures for local state, entitlement snapshots, and sensitive config fingerprints.
- Fail-closed `KernelBridgeLicenseService` guard methods.
- Built-in `/license` activation page and JSON activation/verify/deactivate endpoints.
- Optional global servlet route protection with browser redirects and API `402` JSON responses.
- MVC annotations for protected controllers or methods.
- Optional `KernelBridgeAuthorizationManager` for Spring Security `SecurityFilterChain` rules.
- Scheduled verification using `verification-interval`.
- Renewal notices for 3, 2, and 1 month windows.

## Activation UI and API

The built-in activation page is available at `/license` unless `activation-path` is changed.

JSON activation:

```http
POST /license/activate
Content-Type: application/json

{
  "licenseKey": "KB-...",
  "deviceName": "Office Server"
}
```

Manual verification:

```http
POST /license/verify
```

Deactivate this installation:

```http
POST /license/deactivate
```

## Protecting routes

Turn on automatic route protection:

```yaml
kernelbridge:
  licensing:
    protect-web-routes: true
```

Browser requests are redirected to `/license`. API requests return `402 Payment Required`:

```json
{
  "error": "license_required",
  "reason": "license_not_activated",
  "message": "Activate this application before continuing."
}
```

Leave `protect-web-routes: false` if the host app wants manual control with annotations or direct service calls.

## Controller annotations

```java
@RestController
@RequiresKernelBridgeLicense
class ReportsController {
    @GetMapping("/reports")
    List<Report> index() {
        return reports.all();
    }
}
```

Feature gate:

```java
@GetMapping("/finance")
@RequiresKernelBridgeFeature("finance")
FinanceDashboard finance() {
    return dashboard.load();
}
```

Limit gate:

```java
@PostMapping("/users/bulk")
@RequiresKernelBridgeLimit(key = "users", required = 25)
void importUsers() {
    importer.run();
}
```

## Spring Security

```java
@Bean
SecurityFilterChain security(HttpSecurity http, KernelBridgeLicenseService licenses) throws Exception {
    return http.authorizeHttpRequests(registry -> registry
        .requestMatchers("/license/**", "/login", "/assets/**").permitAll()
        .anyRequest().access(new KernelBridgeAuthorizationManager(licenses))
    ).build();
}
```

Use this when the host app already centralizes access rules in Spring Security. Keep `protect-web-routes: false` to avoid double gating the same request.

## Programmatic checks

```java
@Service
class BillingWorkflow {
    private final KernelBridgeLicenseService licenses;

    BillingWorkflow(KernelBridgeLicenseService licenses) {
        this.licenses = licenses;
    }

    void runInvoiceBatch() {
        licenses.assertActive();
        licenses.assertFeature("billing");
        licenses.assertLimit("monthly_invoices", 100);
    }
}
```

Renewal notice:

```java
licenses.renewalNotice().ifPresent(notice -> {
    // Show notice.level(), notice.message(), and notice.expiresAt() in the client app.
});
```

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
mvn test
```

The tests use a fake KernelBridge client and verify encrypted state, config tamper detection, renewal notices, feature/limit checks, and device-limit messaging.

