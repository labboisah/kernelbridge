<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const clients = [
  {
    name: 'Laravel',
    package: 'kernelbridge/licensing-client-laravel',
    href: '/docs/licensing/laravel',
    runtime: 'PHP + Laravel',
    description: 'Composer package with migrations, branded activation UI, route middleware, automatic web protection, queues, and scheduled verification.',
  },
  {
    name: 'TypeScript',
    package: '@kernelbridge/licensing-client-typescript',
    href: '/docs/licensing/typescript',
    runtime: 'Electron or trusted Node',
    description: 'NPM package for Electron main processes and Node services with encrypted local state, guards, config tamper detection, and renewal notices.',
  },
  {
    name: 'Spring Boot',
    package: 'com.kernelbridge:kernelbridge-licensing-spring-boot-starter',
    href: '/docs/licensing/spring-boot',
    runtime: 'Java + Spring Boot',
    description: 'Starter with auto-configuration, encrypted file state, servlet protection, annotations, Spring Security integration, and scheduled verification.',
  },
  {
    name: 'Django',
    package: 'kernelbridge-licensing-django',
    href: '/docs/licensing/django',
    runtime: 'Python + Django',
    description: 'Reusable Django app with encrypted DB state, middleware, decorators, activation URLs, management commands, and renewal notice context.',
  },
  {
    name: 'MCP Server',
    package: '@kernelbridge/licensing-mcp-server',
    href: '/docs/licensing/mcp',
    runtime: 'AI assistant integration',
    description: 'MCP server for support lookup, onboarding workflows, renewal-risk analysis, safe ticket summaries, package compatibility checks, and audited license operations.',
  },
];

const apiEndpoints = [
  'POST /api/v1/licenses/activate',
  'POST /api/v1/licenses/verify',
  'POST /api/v1/licenses/deactivate',
  'GET /api/v1/subscriptions/status',
  'GET /api/v1/entitlements',
];

const checklist = [
  'Install only the client package that matches the product runtime.',
  'Copy product-specific API tokens and signing keys from the customer service page.',
  'Protect application pages before showing licensed screens.',
  'Use local cache for normal requests and verify on startup plus schedule.',
  'Fail closed after revocation, expiry, offline grace expiry, config tamper, or local state tamper.',
  'Warn customers at 3, 2, and 1 month before expiry without blocking active users.',
];
</script>

<template>
  <SeoHead
    title="Licensing Client Documentation | KernelBridge"
    description="Choose and install KernelBridge licensing clients or the MCP server for assistant-powered support and onboarding."
    canonical="https://kernelbridge.com/docs/licensing"
  />

  <PublicLayout>
    <section class="page-hero">
      <div class="page-shell">
        <p class="section-label !text-teal-300">Developer documentation</p>
        <h1 class="page-title">Install KernelBridge licensing.</h1>
        <p class="page-intro">Choose the client package for your product runtime, or connect an AI assistant through the MCP server for support, onboarding, and audited license operations.</p>
      </div>
    </section>

    <section class="page-shell py-16 lg:py-20">
      <div class="grid gap-5 md:grid-cols-2">
        <a v-for="client in clients" :key="client.name" :href="client.href" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-cyan-300 hover:shadow-xl">
          <p class="text-xs font-bold uppercase text-cyan-700">{{ client.runtime }}</p>
          <h2 class="mt-2 text-2xl font-bold text-[#0B3D6E]">{{ client.name }}</h2>
          <code class="mt-3 block break-words rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ client.package }}</code>
          <p class="mt-4 leading-7 text-slate-600">{{ client.description }}</p>
          <span class="mt-5 inline-flex font-bold text-cyan-700 group-hover:text-cyan-900">Open install guide</span>
        </a>
      </div>

      <div class="mt-16 grid gap-10 lg:grid-cols-[minmax(0,1fr)_360px]">
        <section>
          <p class="section-label">Protocol</p>
          <h2 class="section-title">One licensing API for every product.</h2>
          <p class="mt-5 max-w-3xl leading-8 text-slate-700">Every client uses the same public UUID-based API and stores only encrypted local state in the installed product. Product apps never query the central KernelBridge database directly.</p>
          <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <code v-for="endpoint in apiEndpoints" :key="endpoint" class="rounded-xl bg-[#071426] px-4 py-3 text-sm text-cyan-100">{{ endpoint }}</code>
          </div>
        </section>

        <aside class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <p class="section-label">Shared checklist</p>
          <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-700">
            <li v-for="item in checklist" :key="item" class="border-b border-slate-100 pb-3 last:border-b-0 last:pb-0">{{ item }}</li>
          </ul>
        </aside>
      </div>
    </section>
  </PublicLayout>
</template>

