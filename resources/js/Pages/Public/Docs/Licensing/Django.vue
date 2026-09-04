<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const install = `pip install kernelbridge-licensing-django
python manage.py migrate`;
const localInstall = `cd packages/kernelbridge/licensing-client-django
pip install -e .`;
const setup = `INSTALLED_APPS = [
    # ...
    "kernelbridge_licensing",
]

urlpatterns = [
    path("license/", include("kernelbridge_licensing.urls")),
    # ...
]

MIDDLEWARE = [
    "kernelbridge_licensing.middleware.KernelBridgeLicenseMiddleware",
    # ...
]`;
const settings = `KERNELBRIDGE_LICENSING = {
    "API_URL": "https://kernelbridge.com/api/v1",
    "PRODUCT_CODE": "ALIGNEX",
    "API_TOKEN": env("KERNELBRIDGE_API_TOKEN"),
    "CACHE_SIGNING_KEY": env("KERNELBRIDGE_CACHE_SIGNING_KEY"),
    "ENCRYPTION_KEY": env("KERNELBRIDGE_LICENSE_ENCRYPTION_KEY"),
    "VERIFICATION_INTERVAL_SECONDS": 900,
    "OFFLINE_GRACE_SECONDS": 259200,
    "ACTIVATION_PATH": "/license/",
    "PROTECT_WEB_ROUTES": True,
    "PROTECT_EXCEPT": ["/license/", "/accounts/login/", "/static/"],
    "RENEWAL_NOTICE_ENABLED": True,
    "RENEWAL_NOTICE_MONTHS": [3, 2, 1],
}`;
const decorators = `from kernelbridge_licensing.decorators import (
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
    ...`;
const commands = `python manage.py kernelbridge_activate KB-... --device-name="Office Server"
python manage.py kernelbridge_verify --force
python manage.py kernelbridge_deactivate`;
const context = `TEMPLATES[0]["OPTIONS"]["context_processors"] += [
    "kernelbridge_licensing.context_processors.kernelbridge_renewal_notice",
]`;
</script>

<template>
  <SeoHead title="Django Licensing Client | KernelBridge" description="Install and configure the KernelBridge Django licensing client." canonical="https://kernelbridge.com/docs/licensing/django" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Django install guide</p><h1 class="page-title">kernelbridge-licensing-django</h1><p class="page-intro">Django app with encrypted DB state, activation URLs, route middleware, decorators, management commands, and renewal notice context.</p></div></section>
    <article class="page-shell max-w-5xl py-16 lg:py-20">
      <a href="/docs/licensing" class="font-bold text-cyan-700">Back to client SDKs</a>
      <section class="mt-10"><p class="section-label">Install</p><h2 class="section-title">Install the Django app.</h2><CodeBlock title="Production" :code="install" /><CodeBlock title="Local monorepo" :code="localInstall" /></section>
      <section class="mt-14"><p class="section-label">Project setup</p><h2 class="section-title">Register the app, URLs, and middleware.</h2><CodeBlock title="settings.py / urls.py" :code="setup" /></section>
      <section class="mt-14"><p class="section-label">Settings</p><h2 class="section-title">Copy service credentials.</h2><CodeBlock title="settings.py" :code="settings" /></section>
      <section class="mt-14"><p class="section-label">Protection</p><h2 class="section-title">Protect globally or with decorators.</h2><p class="mt-5 leading-8 text-slate-700">Set <code>PROTECT_WEB_ROUTES</code> true to protect the whole app. Leave it false when using decorators or direct service checks only.</p><CodeBlock title="views.py" :code="decorators" /></section>
      <section class="mt-14"><p class="section-label">Operations</p><h2 class="section-title">Activate, verify, deactivate.</h2><CodeBlock title="Management commands" :code="commands" /><CodeBlock title="Renewal context" :code="context" /></section>
    </article>
  </PublicLayout>
</template>
