<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const install = `npm install -g @kernelbridge/licensing-mcp-server`;
const localInstall = `cd packages/kernelbridge/licensing-mcp-server
npm install
npm run build
npm install -g .`;
const env = `KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_API_TOKEN=replace-with-mcp-scoped-token
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_DOCS_ROOT=C:/laragon/www/kernelbridge
KERNELBRIDGE_MCP_ALLOW_MUTATIONS=false`;
const powershell = `$env:KERNELBRIDGE_API_URL="https://kernelbridge.com/api/v1"
$env:KERNELBRIDGE_API_TOKEN="replace-with-mcp-token"
$env:KERNELBRIDGE_PRODUCT_CODE="ALIGNEX"
$env:KERNELBRIDGE_DOCS_ROOT="C:/laragon/www/kernelbridge"
$env:KERNELBRIDGE_MCP_ALLOW_MUTATIONS="false"
kernelbridge-licensing-mcp`;
const clientConfig = `{
  "mcpServers": {
    "kernelbridge-licensing": {
      "command": "kernelbridge-licensing-mcp",
      "env": {
        "KERNELBRIDGE_API_URL": "https://kernelbridge.com/api/v1",
        "KERNELBRIDGE_API_TOKEN": "replace-with-mcp-scoped-token",
        "KERNELBRIDGE_PRODUCT_CODE": "ALIGNEX",
        "KERNELBRIDGE_DOCS_ROOT": "C:/laragon/www/kernelbridge",
        "KERNELBRIDGE_MCP_ALLOW_MUTATIONS": "false"
      }
    }
  }
}`;
const publish = `cd packages/kernelbridge/licensing-mcp-server
npm ci
npm run typecheck
npm test
npm run build
npm run pack:dry-run
npm publish --access restricted --otp=YOUR_6_DIGIT_CODE`;
const toolGroups = [
  ['Support', ['kernelbridge_find_customer', 'kernelbridge_list_customer_services', 'kernelbridge_explain_activation_issue']],
  ['Onboarding', ['kernelbridge_generate_setup_template', 'kernelbridge_get_service_setup', 'kernelbridge_prepare_onboarding_workflow']],
  ['License operations', ['kernelbridge_list_license_activations', 'kernelbridge_issue_license', 'kernelbridge_deactivate_license_activation']],
  ['Assistant workflows', ['kernelbridge_activation_support_playbook', 'kernelbridge_analyze_customer_renewal_risk', 'kernelbridge_generate_safe_ticket_summary', 'kernelbridge_check_package_compatibility']],
  ['Documentation', ['kernelbridge_list_docs', 'kernelbridge_read_doc']],
];
</script>

<template>
  <SeoHead title="MCP Server Developer Guide | KernelBridge" description="Install and configure the KernelBridge licensing MCP server for support, onboarding, license operations, and developer documentation." canonical="https://kernelbridge.com/docs/licensing/mcp" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">MCP developer guide</p><h1 class="page-title">@kernelbridge/licensing-mcp-server</h1><p class="page-intro">Connect AI assistants to KernelBridge licensing workflows using scoped tokens, audited API endpoints, safe support summaries, onboarding playbooks, and documentation tools.</p></div></section>
    <article class="page-shell max-w-5xl py-16 lg:py-20">
      <a href="/docs/licensing" class="font-bold text-cyan-700">Back to developer docs</a>

      <section class="mt-10"><p class="section-label">Install</p><h2 class="section-title">Install the MCP server.</h2><p class="mt-5 leading-8 text-slate-700">Install globally after publishing to your npm registry, or install from the local monorepo while developing.</p><CodeBlock title="Published package" :code="install" /><CodeBlock title="Local monorepo" :code="localInstall" /></section>

      <section class="mt-14"><p class="section-label">Environment</p><h2 class="section-title">Pass env values from the process or MCP client.</h2><p class="mt-5 leading-8 text-slate-700">The server reads process environment variables. It does not automatically load a .env file. Use the admin MCP page to issue a scoped token, then put the values in your shell, process manager, or MCP client JSON.</p><CodeBlock title="Environment" :code="env" /><CodeBlock title="PowerShell" :code="powershell" /></section>

      <section class="mt-14"><p class="section-label">Client config</p><h2 class="section-title">Register the server with your MCP client.</h2><CodeBlock title="mcp.json" :code="clientConfig" /></section>

      <section class="mt-14"><p class="section-label">Tokens</p><h2 class="section-title">Create scoped MCP credentials.</h2><div class="mt-5 grid gap-4 md:grid-cols-2"><div class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-[#0B3D6E]">Read-only support</h3><p class="mt-2 text-sm leading-6 text-slate-600">Use for support triage, activation explanations, customer service lookup, and safe summaries.</p><code class="mt-3 block rounded-lg bg-slate-100 p-3 text-sm">mcp:read</code></div><div class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-[#0B3D6E]">License operator</h3><p class="mt-2 text-sm leading-6 text-slate-600">Use only for audited write tools. Write calls require confirmation fields and mutation env enabled.</p><code class="mt-3 block rounded-lg bg-slate-100 p-3 text-sm">mcp:read, mcp:write</code></div></div></section>

      <section class="mt-14"><p class="section-label">Tools</p><h2 class="section-title">Available MCP tools.</h2><div class="mt-6 grid gap-5 md:grid-cols-2"><div v-for="group in toolGroups" :key="group[0]" class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-[#0B3D6E]">{{ group[0] }}</h3><ul class="mt-4 space-y-2 text-sm text-slate-700"><li v-for="tool in group[1]" :key="tool"><code class="break-words rounded bg-slate-100 px-2 py-1">{{ tool }}</code></li></ul></div></div></section>

      <section class="mt-14"><p class="section-label">Write safety</p><h2 class="section-title">Keep mutations deliberate.</h2><p class="mt-5 leading-8 text-slate-700">Write tools are disabled unless <code>KERNELBRIDGE_MCP_ALLOW_MUTATIONS=true</code>. License issuing and activation deactivation also require <code>confirmed_by</code> and <code>confirmation_note</code>. KernelBridge stores confirmation records and audit logs for every MCP write.</p></section>

      <section class="mt-14"><p class="section-label">Publish</p><h2 class="section-title">Publish to a private registry.</h2><p class="mt-5 leading-8 text-slate-700">Run the release checks before publishing. If npm requires two-factor authentication, pass your current one-time code with <code>--otp</code>.</p><CodeBlock title="Release" :code="publish" /></section>
    </article>
  </PublicLayout>
</template>
