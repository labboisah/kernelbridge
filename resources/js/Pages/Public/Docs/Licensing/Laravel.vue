<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const install = `composer config repositories.kernelbridge vcs https://github.com/labboisah/laravel-licensing-package.git
composer require kernelbridge/licensing-client-laravel:^1.2.1
php artisan vendor:publish --tag=kernelbridge-licensing-config
php artisan vendor:publish --tag=kernelbridge-licensing-migrations
php artisan vendor:publish --tag=kernelbridge-licensing-assets
php artisan migrate`;

const localInstall = `composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel
composer require kernelbridge/licensing-client-laravel:^1.2.1`;

const env = `KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-product-machine-token`;

const optionalEnv = `# Only set these when overriding package defaults:
# KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
# KERNELBRIDGE_API_CONNECT_TIMEOUT=3
# KERNELBRIDGE_API_TIMEOUT=10
# KERNELBRIDGE_VERIFICATION_INTERVAL=15
# KERNELBRIDGE_OFFLINE_GRACE_HOURS=72
# KERNELBRIDGE_CACHE_SIGNING_KEY=use-a-separate-long-random-secret
# KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE=true
# KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true

# Optional whole-app route protection:
# KERNELBRIDGE_PROTECT_WEB_ROUTES=true
# KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*`;

const routes = `Route::middleware('kernelbridge.subscription.active')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reports', ReportsController::class);
});

Route::middleware('kernelbridge.feature:finance')->group(function () {
    Route::resource('invoices', InvoiceController::class);
});

Route::middleware('kernelbridge.limit:users,25')->post('/users/import', ImportUsersController::class);`;

const entitlementGate = `use Illuminate\\Support\\Facades\\Gate;
use App\\Services\\ProductEntitlementService;

public function export()
{
    // Product-owned RBAC and resource policy remain independent checks.
    Gate::authorize('viewAny', Report::class);

    // This application service must use a compatible SDK's verified,
    // signed entitlement snapshot; it is not a plan-name check.
    abort_unless(
        app(ProductEntitlementService::class)->allows('mediflow.module.reports'),
        403,
    );

    return app(ReportExporter::class)->export();
}`;

const permissionAssignment = `foreach ($requestedPermissions as $permission) {
    $capability = $permissionCapabilityMap->forPermission($permission);

    if ($capability === null || ! $entitlements->allows($capability)) {
        throw ValidationException::withMessages([
            'permissions' => 'A selected permission is not included in this installation plan.',
        ]);
    }
}

$preserved = $role->permissions
    ->filter(function ($permission) use ($permissionCapabilityMap, $entitlements) {
        $capability = $permissionCapabilityMap->forPermission($permission);

        return $capability === null || ! $entitlements->allows($capability);
    })
    ->modelKeys();

DB::transaction(fn () => $role->permissions()->sync(
    array_unique([...$requestedPermissions, ...$preserved]),
));`;

const schedule = `// routes/console.php
Schedule::job(new KernelBridge\\LicensingClient\\Jobs\\VerifyLicenseJob)->everyFifteenMinutes();`;

const reprovision = `php artisan kernelbridge:reprovision-profile`;
</script>

<template>
  <SeoHead title="Laravel Licensing Client | KernelBridge" description="Install and configure the KernelBridge Laravel licensing client." canonical="https://kernelbridge.com/docs/licensing/laravel" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Laravel install guide</p><h1 class="page-title">kernelbridge/licensing-client-laravel</h1><p class="page-intro">Composer package for Laravel products with signed local state, deployment fingerprint checks, fail-closed activation validation, and an explicit deployment re-provision path.</p></div></section>
    <article class="page-shell max-w-5xl py-16 lg:py-20">
      <a href="/docs/licensing" class="font-bold text-cyan-700">Back to client SDKs</a>
      <section class="mt-10">
        <p class="section-label">Install</p>
        <h2 class="section-title">Install the Laravel client.</h2>
        <p class="mt-5 leading-8 text-slate-700">Run these commands from the Laravel client application. The package is hosted in the KernelBridge licensing-client GitHub repository and is not published to Packagist. Commit the resulting composer.json and composer.lock so deployments use the same SDK version.</p>
        <CodeBlock title="Terminal (GitHub install)" :code="install" />
        <p class="mt-5 leading-8 text-slate-700">For local development with a sibling KernelBridge checkout, you may use a Composer path repository instead:</p>
        <CodeBlock title="Terminal (local sibling checkout)" :code="localInstall" />
        <p class="mt-5 leading-8 text-slate-700">The current package requires PHP 8.3+ and Laravel 12. Publish its config, migrations, and assets, then run migrations. Protect the product database and Laravel <code>APP_KEY</code>; the local license state is encrypted and signed.</p>
      </section>
      <section class="mt-14">
        <p class="section-label">Modes</p>
        <h2 class="section-title">Separate deployment mode from licensing mode.</h2>
        <p class="mt-5 leading-8 text-slate-700">There are two different meanings of “mode”. The package's <code>KERNELBRIDGE_DEPLOYMENT_MODE</code> (defaulting to <code>APP_MODE</code>, then <code>client</code>) controls deployment-profile validation; it does not select the customer's plan-entitlement source.</p>
        <div class="mt-5 overflow-x-auto">
          <table class="w-full border-collapse text-left text-sm">
            <thead><tr class="border-b border-slate-200"><th class="py-3 pr-5">Deployment mode</th><th class="py-3">Package behavior</th></tr></thead>
            <tbody class="text-slate-700">
              <tr class="border-b border-slate-100"><td class="py-3 pr-5"><code>client</code></td><td class="py-3">Normal licensed product installation. Requires complete KernelBridge configuration and validates its persisted deployment profile.</td></tr>
              <tr class="border-b border-slate-100"><td class="py-3 pr-5"><code>central</code></td><td class="py-3">Also treated as a KernelBridge-connected deployment by this package; it requires the same complete profile validation.</td></tr>
              <tr><td class="py-3 pr-5"><code>standalone</code></td><td class="py-3">Skips the remote deployment-profile completeness check. It is not a customer licensing or entitlement mode and must not be used to bypass activation in a licensed product.</td></tr>
            </tbody>
          </table>
        </div>
        <p class="mt-5 leading-8 text-slate-700">Separately, a product's licensing policy may be <strong>Direct</strong> (KernelBridge owns plan-to-grant mapping), <strong>Platform Account</strong> (the product platform owns account grants and submits authorized updates), or <strong>Hybrid</strong> (the source is explicitly bound per installation). This source is established by the authorized licensing flow, not by changing a client environment variable. Hybrid does not mean merging both sources.</p>
      </section>
      <section class="mt-14">
        <p class="section-label">Environment</p>
        <h2 class="section-title">Set the required service values.</h2>
        <p class="mt-5 leading-8 text-slate-700">Set the product code and product machine token issued for this product and environment. The API URL defaults to the KernelBridge production endpoint, and the cache-signing key defaults to Laravel's <code>APP_KEY</code>, so those do not need to be repeated for a standard installation. Keep credentials secret and never commit them.</p>
        <CodeBlock title="Required .env values" :code="env" />
        <p class="mt-5 leading-8 text-slate-700">The package supplies defaults for verification timing, offline grace, strict deployment-profile validation, and renewal notices. Override these only for an approved deployment policy. Whole-app route protection is optional; when enabled, configure its exceptions for public routes such as login and password reset.</p>
        <CodeBlock title="Optional overrides" :code="optionalEnv" />
      </section>
      <section class="mt-14">
        <p class="section-label">Activation</p>
        <h2 class="section-title">Activate this installation through its supported flow.</h2>
        <ol class="mt-5 list-decimal space-y-3 pl-6 leading-8 text-slate-700">
          <li>Complete installation, configure the product code and protected server-side machine token, and make sure the product server can reach the configured KernelBridge API URL over HTTPS.</li>
          <li>Open the package's <code>/license</code> page and enter the license or activation code issued for this product. The API machine token is server configuration; it is not the activation code and must never be pasted into the page.</li>
          <li>The Laravel client registers this server installation, verifies its machine binding, fetches subscription and entitlement data, and stores the verified local state. Use <strong>Verify this installation</strong> on the license page to request a fresh verification.</li>
        </ol>
        <p class="mt-5 leading-8 text-slate-700">The current Laravel package activation screen implements the license-code/device activation flow. For Platform Account or Hybrid products, the product platform must perform its authorized account/link workflow and use a client integration that supports that flow; do not send a reusable platform credential or a customer's account token through the default activation form. Confirm support for the exact flow with the product publisher before deployment.</p>
        <p class="mt-5 leading-8 text-slate-700">The current <code>1.2.1</code> Laravel package consumes the legacy flat feature/limit response. It does not yet expose the structured signed <code>entitlement_framework</code> extension in this release. Keep structured-snapshot and strict plan enforcement disabled until a compatible SDK release and product adapter are available, and validate the complete integration before enabling either.</p>
      </section>
      <section class="mt-14"><p class="section-label">Security</p><h2 class="section-title">Fail closed when the deployment no longer matches.</h2><p class="mt-5 leading-8 text-slate-700">The package records a deployment fingerprint and compares it against active runtime config. If product configuration changes, an operator with shell access must intentionally re-provision it. The CLI action prompts for confirmation, defaults to no, and is not exposed through the public license UI. This detects configuration drift but is not hardware attestation or protection against an administrator who controls the client files and database.</p><CodeBlock title="Terminal" :code="reprovision" /></section>
      <section class="mt-14"><p class="section-label">Protection</p><h2 class="section-title">Protect all routes or selected routes.</h2><p class="mt-5 leading-8 text-slate-700">Set <code>KERNELBRIDGE_PROTECT_WEB_ROUTES=true</code> to protect the whole app. Leave it false when you only want selected middleware groups. Using both is safe but usually redundant except for feature and limit gates.</p><CodeBlock title="routes/web.php" :code="routes" /></section>
      <section class="mt-14"><p class="section-label">Verification</p><h2 class="section-title">Verify away from page requests.</h2><p class="mt-5 leading-8 text-slate-700">The package uses local signed state for normal requests. Schedule verification on startup or a recurring background task to refresh entitlements and detect drift.</p><CodeBlock title="routes/console.php" :code="schedule" /></section>
      <section class="mt-14">
        <p class="section-label">Renewal</p>
        <h2 class="section-title">Renew with KernelBridge, then refresh the installation.</h2>
        <p class="mt-5 leading-8 text-slate-700">The Laravel package does not sell plans, issue invoices, or process renewal payments. The customer renews through the KernelBridge customer/account workflow, or through an approved product-platform workflow that completes with KernelBridge. KernelBridge remains authoritative for payment-linked subscription status, expiry, and renewed entitlements.</p>
        <p class="mt-5 leading-8 text-slate-700">After renewal is accepted, the scheduled verification job refreshes the product's local snapshot (normally every 15 minutes). An administrator can also open <code>/license</code> and choose <strong>Verify this installation</strong>. The package may emit non-blocking renewal notice response headers at about 3, 2, and 1 month before expiry when license middleware runs; these notices do not renew the subscription or block a still-valid license.</p>
        <p class="mt-5 leading-8 text-slate-700">If the plan was changed or the renewed access is not visible after verification, confirm the subscription and plan in KernelBridge. For Platform Account products, also confirm that the product platform delivered the latest authorized grant revision to the correct license/installation. Do not edit the local cache or extend offline grace to force a renewal.</p>
      </section>
      <section class="mt-14">
        <p class="section-label">Plan entitlements</p>
        <h2 class="section-title">Use stable capability keys, not plan names.</h2>
        <div class="mt-5 space-y-4 leading-8 text-slate-700">
          <p>For structured plan entitlements, the product defines and publishes a versioned capability catalog before plan grants are assigned. Use stable product-scoped keys such as <code>mediflow.module.reports</code>; plan labels, package names, and UI visibility are not authorization decisions.</p>
          <p>In Direct mode, an authorized KernelBridge administrator maps the plan to catalog grants. In Platform Account mode, the product platform owns the account's plan grants and sends authorized, revisioned updates to KernelBridge for the linked license/installations. Hybrid installations use only their explicitly bound source. Do not assume a grant update to one license automatically propagates to every account installation.</p>
          <p>Structured snapshot support is optional and rollout-gated. Before enabling it, use an SDK release that verifies and exposes the structured signed extension, implement the product adapter, and test missing, disabled, malformed, stale, and source-mismatched grants. Do not parse unsigned payloads or treat legacy flat feature helpers as proof that a structured grant was verified.</p>
        </div>
      </section>
      <section class="mt-14">
        <p class="section-label">Authorization and RBAC</p>
        <h2 class="section-title">A plan grant is not a user permission.</h2>
        <p class="mt-5 leading-8 text-slate-700">For each protected operation, require a usable KernelBridge license, the required plan capability, the user's own role or permission, and any tenant, branch, or resource policy. Keep the entitlement check in a product-owned service and enforce it on the server for web, API, jobs, exports, and other operation paths—not only in the UI.</p>
        <CodeBlock title="Laravel action (application adapter example)" :code="entitlementGate" />
        <p class="mt-5 leading-8 text-slate-700">If the product has permission-based RBAC, maintain an explicit permission-to-capability map. Validate every selected permission against the active plan before saving role changes, and use a database transaction so rejected assignments cannot partially apply. A downgrade should stop uncovered permissions from authorizing actions without silently deleting the product's role assignments.</p>
        <CodeBlock title="Role permission assignment (application adapter example)" :code="permissionAssignment" />
      </section>
    </article>
  </PublicLayout>
</template>
