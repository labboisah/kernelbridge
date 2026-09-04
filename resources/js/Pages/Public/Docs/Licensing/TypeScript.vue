<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const install = `npm install @kernelbridge/licensing-client-typescript better-sqlite3`;
const env = `KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-revocable-installation-token
KERNELBRIDGE_API_TIMEOUT_MS=10000
KERNELBRIDGE_VERIFICATION_INTERVAL_MS=900000
KERNELBRIDGE_OFFLINE_GRACE_MS=259200000
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-random-secret
KERNELBRIDGE_APP_KEY=replace-with-stable-app-installation-scope
KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true
KERNELBRIDGE_RENEWAL_NOTICE_MONTHS=3,2,1`;
const services = `import Database from 'better-sqlite3';
import { app, safeStorage } from 'electron';
import {
  ElectronSafeStorageVault,
  KernelBridgeLicenseClient,
  LicenseActivationService,
  LicenseCacheService,
  LicenseGuard,
  LicenseVerificationService,
  PeriodicLicenseVerifier,
  SqliteLicenseStore,
  licensingOptionsFromEnv,
} from '@kernelbridge/licensing-client-typescript';

const options = { ...licensingOptionsFromEnv(process.env), appKey: app.getPath('userData') };
const store = new SqliteLicenseStore(new Database(licenseDatabasePath));
const vault = new ElectronSafeStorageVault(safeStorage);
const cache = new LicenseCacheService(store, vault, options);
const api = new KernelBridgeLicenseClient(options);

export const activation = new LicenseActivationService(api, cache, options);
export const verification = new LicenseVerificationService(api, cache, options);
export const license = new LicenseGuard(cache);

new PeriodicLicenseVerifier(() => verification.verify(true), options.verificationIntervalMs ?? 900000, console.error).start();`;
const gate = `const decision = await license.checkActive();
if (!decision.allowed) {
  showActivationWindow(decision.message);
  return;
}

await openMainApplicationWindow();`;
const express = `export async function requireKernelBridgeLicense(_request, response, next) {
  const decision = await license.checkActive();
  if (!decision.allowed) {
    return response.status(402).json({ error: decision.reason, message: decision.message });
  }
  return next();
}`;
</script>

<template>
  <SeoHead title="TypeScript Licensing Client | KernelBridge" description="Install and configure the KernelBridge TypeScript licensing client for Electron or Node." canonical="https://kernelbridge.com/docs/licensing/typescript" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">TypeScript install guide</p><h1 class="page-title">@kernelbridge/licensing-client-typescript</h1><p class="page-intro">NPM client for Electron main processes and trusted Node services with encrypted SQLite state, activation decisions, feature guards, and renewal notices.</p></div></section>
    <article class="page-shell max-w-5xl py-16 lg:py-20">
      <a href="/docs/licensing" class="font-bold text-cyan-700">Back to client SDKs</a>
      <section class="mt-10"><p class="section-label">Install</p><h2 class="section-title">Install the package in trusted runtime code.</h2><p class="mt-5 leading-8 text-slate-700">Use this in Electron main process code or server-side Node. Do not expose tokens, license keys, or raw entitlements to browser renderers.</p><CodeBlock title="Terminal" :code="install" /></section>
      <section class="mt-14"><p class="section-label">Environment</p><h2 class="section-title">Configure product credentials.</h2><CodeBlock title=".env" :code="env" /></section>
      <section class="mt-14"><p class="section-label">Services</p><h2 class="section-title">Create one licensing module.</h2><CodeBlock title="main/licensing.ts" :code="services" /></section>
      <section class="mt-14"><p class="section-label">Protection</p><h2 class="section-title">Gate windows or server routes.</h2><CodeBlock title="main/open-app.ts" :code="gate" /><CodeBlock title="server/middleware.ts" :code="express" /></section>
    </article>
  </PublicLayout>
</template>
