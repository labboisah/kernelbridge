<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const install = `composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel
composer require kernelbridge/licensing-client-laravel:^1.0
php artisan vendor:publish --tag=kernelbridge-licensing-config
php artisan vendor:publish --tag=kernelbridge-licensing-migrations
php artisan vendor:publish --tag=kernelbridge-licensing-assets
php artisan migrate`;

const env = `KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-product-machine-token
KERNELBRIDGE_VERIFICATION_INTERVAL=15
KERNELBRIDGE_OFFLINE_GRACE_HOURS=72
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-random-secret
KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE=true
KERNELBRIDGE_PROTECT_WEB_ROUTES=true
KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*
KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true`;

const routes = `Route::middleware('kernelbridge.subscription.active')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reports', ReportsController::class);
});

Route::middleware('kernelbridge.feature:finance')->group(function () {
    Route::resource('invoices', InvoiceController::class);
});

Route::middleware('kernelbridge.limit:users,25')->post('/users/import', ImportUsersController::class);`;

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
      <section class="mt-10"><p class="section-label">Install</p><h2 class="section-title">Register the private package.</h2><p class="mt-5 leading-8 text-slate-700">Run these commands from the Laravel client application. Use an absolute path if the apps are not sibling folders.</p><CodeBlock title="Terminal" :code="install" /></section>
      <section class="mt-14"><p class="section-label">Environment</p><h2 class="section-title">Copy service-specific values.</h2><p class="mt-5 leading-8 text-slate-700">Use the generated values from the customer's KernelBridge service page. Keep product tokens isolated per product and environment, and do not commit tokens or signing keys.</p><CodeBlock title=".env" :code="env" /></section>
      <section class="mt-14"><p class="section-label">Security</p><h2 class="section-title">Fail closed when the deployment no longer matches.</h2><p class="mt-5 leading-8 text-slate-700">The package records a deployment fingerprint and compares it against active runtime config. If product configuration changes, an operator with shell access must intentionally re-provision it. The CLI action prompts for confirmation, defaults to no, and is not exposed through the public license UI. This detects configuration drift but is not hardware attestation or protection against an administrator who controls the client files and database.</p><CodeBlock title="Terminal" :code="reprovision" /></section>
      <section class="mt-14"><p class="section-label">Protection</p><h2 class="section-title">Protect all routes or selected routes.</h2><p class="mt-5 leading-8 text-slate-700">Set <code>KERNELBRIDGE_PROTECT_WEB_ROUTES=true</code> to protect the whole app. Leave it false when you only want selected middleware groups. Using both is safe but usually redundant except for feature and limit gates.</p><CodeBlock title="routes/web.php" :code="routes" /></section>
      <section class="mt-14"><p class="section-label">Verification</p><h2 class="section-title">Verify away from page requests.</h2><p class="mt-5 leading-8 text-slate-700">The package uses local signed state for normal requests. Schedule verification on startup or a recurring background task to refresh entitlements and detect drift.</p><CodeBlock title="routes/console.php" :code="schedule" /></section>
    </article>
  </PublicLayout>
</template>
