import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { configFromEnv } from '../src/config.js';
import { KernelBridgeApiClient } from '../src/kernelbridge-api.js';
import { readDocumentationResource } from '../src/docs.js';
import { setupTemplate } from '../src/setup-templates.js';
import { createToolRegistry } from '../src/tools.js';

test('generates setup templates for all client runtimes', () => {
  for (const runtime of ['laravel', 'typescript', 'spring-boot', 'django'] as const) {
    const template = setupTemplate({ runtime, productCode: 'alignex', apiUrl: 'https://kernelbridge.test/api/v1' });
    assert.equal(template.runtime, runtime);
    assert.match(template.configuration, /ALIGNEX/);
    assert.match(template.docsPath, /^\/docs\/licensing/);
  }
});

test('documentation resources read from the configured docs root', async () => {
  const root = await mkdtemp(join(tmpdir(), 'kernelbridge-docs-'));
  await mkdir(join(root, 'docs'), { recursive: true });
  await writeFile(join(root, 'docs', 'LICENSING_CLIENT_SPEC.md'), '# Spec\n');

  const text = await readDocumentationResource(root, 'kernelbridge://docs/licensing/spec');
  assert.equal(text, '# Spec\n');
});

test('api-backed tools call KernelBridge API paths through the client boundary', async () => {
  const calls: Array<{ url: string; method: string }> = [];
  const fetcher = async (url: URL | RequestInfo, init?: RequestInit): Promise<Response> => {
    calls.push({ url: String(url), method: init?.method ?? 'GET' });
    return new Response(JSON.stringify({ success: true, data: [{ uuid: 'cus_1', name: 'ACME Clinic' }] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token' });
  const api = new KernelBridgeApiClient(config, fetcher as typeof fetch);
  const tools = createToolRegistry({ config, api });

  const result = await tools.find((tool) => tool.name === 'kernelbridge_find_customer')!.handler({ query: 'acme' });

  assert.match(result.content[0]!.text, /ACME Clinic/);
  assert.equal(calls[0]!.method, 'GET');
  assert.equal(calls[0]!.url, 'https://kernelbridge.test/api/v1/mcp/customers/search?query=acme&limit=10');
});

test('mutation tools are disabled by default', async () => {
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token' });
  const tools = createToolRegistry({ config });
  const issue = tools.find((tool) => tool.name === 'kernelbridge_issue_license')!;

  await assert.rejects(() => issue.handler({ subscription_uuid: 'sub_1' }), /Mutation tools are disabled/);
});

test('generic setup tool works without a KernelBridge API token', async () => {
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1' });
  const tools = createToolRegistry({ config });
  const setup = tools.find((tool) => tool.name === 'kernelbridge_generate_setup_template')!;

  const result = await setup.handler({ runtime: 'django', product_code: 'alignex' });
  assert.match(result.content[0]!.text, /kernelbridge-licensing-django/);
  assert.match(result.content[0]!.text, /ALIGNEX/);
});

test('mutation tools send human confirmation fields when explicitly enabled', async () => {
  const calls: Array<{ url: string; method: string; body: string | undefined }> = [];
  const fetcher = async (url: URL | RequestInfo, init?: RequestInit): Promise<Response> => {
    calls.push({ url: String(url), method: init?.method ?? 'GET', body: init?.body as string | undefined });
    return new Response(JSON.stringify({ success: true, data: { license_uuid: 'lic_1', license_key: 'KBT-KEY', confirmation_uuid: 'confirmation_1' } }), { status: 201, headers: { 'Content-Type': 'application/json' } });
  };
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token', KERNELBRIDGE_MCP_ALLOW_MUTATIONS: 'true' });
  const api = new KernelBridgeApiClient(config, fetcher as typeof fetch);
  const tools = createToolRegistry({ config, api });

  await tools.find((tool) => tool.name === 'kernelbridge_issue_license')!.handler({ subscription_uuid: 'sub_1', max_activations: 2, confirmed_by: 'Support Manager', confirmation_note: 'Customer approved issuance.' });

  assert.equal(calls[0]!.method, 'POST');
  assert.deepEqual(JSON.parse(calls[0]!.body!), { subscription_uuid: 'sub_1', max_activations: 2, confirmed_by: 'Support Manager', confirmation_note: 'Customer approved issuance.' });
});


test('assistant workflow tools compose API primitives into support playbooks', async () => {
  const calls: Array<{ url: string; method: string }> = [];
  const fetcher = async (url: URL | RequestInfo, init?: RequestInit): Promise<Response> => {
    calls.push({ url: String(url), method: init?.method ?? 'GET' });
    const path = String(url);
    const data = path.includes('/activations')
      ? [{ uuid: 'act_1', device_name: 'Old Server', status: 'active' }]
      : { license_uuid: 'lic_1', reason: 'activation_limit_reached', message: 'Maximum activations reached.', active_activations: 1, max_activations: 1 };
    return new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token' });
  const api = new KernelBridgeApiClient(config, fetcher as typeof fetch);
  const tools = createToolRegistry({ config, api });

  const result = await tools.find((tool) => tool.name === 'kernelbridge_activation_support_playbook')!.handler({ license_uuid: 'lic_1' });

  assert.match(result.content[0]!.text, /activation_limit_reached/);
  assert.match(result.content[0]!.text, /Deactivate the retired activation/);
  assert.equal(calls.length, 2);
});

test('assistant onboarding workflow returns runtime checklist and setup config', async () => {
  const fetcher = async (): Promise<Response> => new Response(JSON.stringify({ success: true, data: { product_code: 'ALIGNEX', api_url: 'https://kernelbridge.test/api/v1', api_token: 'replace-with-product-machine-token', cache_signing_key: 'issue-license-first' } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token' });
  const api = new KernelBridgeApiClient(config, fetcher as typeof fetch);
  const tools = createToolRegistry({ config, api });

  const result = await tools.find((tool) => tool.name === 'kernelbridge_prepare_onboarding_workflow')!.handler({ subscription_uuid: 'sub_1', runtime: 'laravel' });

  assert.match(result.content[0]!.text, /kernelbridge\/licensing-client-laravel/);
  assert.match(result.content[0]!.text, /Enable global route protection/);
});

test('assistant renewal risk and compatibility tools produce local guidance', async () => {
  const soon = new Date();
  soon.setDate(soon.getDate() + 20);
  const fetcher = async (): Promise<Response> => new Response(JSON.stringify({ success: true, data: [{ subscription_uuid: 'sub_1', status: 'active', ends_at: soon.toISOString(), product: { code: 'ALIGNEX' }, plan: { name: 'Professional' }, license: { uuid: 'lic_1', status: 'active', max_activations: 1, active_activations: 1 } }] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1', KERNELBRIDGE_API_TOKEN: 'token' });
  const api = new KernelBridgeApiClient(config, fetcher as typeof fetch);
  const tools = createToolRegistry({ config, api });

  const risk = await tools.find((tool) => tool.name === 'kernelbridge_analyze_customer_renewal_risk')!.handler({ customer_uuid: 'cus_1', within_months: 3 });
  const compatibility = await tools.find((tool) => tool.name === 'kernelbridge_check_package_compatibility')!.handler({ runtime: 'django', installed_version: '0.9.0' });

  assert.match(risk.content[0]!.text, /renewal_due_soon/);
  assert.match(compatibility.content[0]!.text, /upgrade_required/);
});

test('safe ticket summary excludes secret-shaped fields', async () => {
  const config = configFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.test/api/v1' });
  const tools = createToolRegistry({ config });

  const result = await tools.find((tool) => tool.name === 'kernelbridge_generate_safe_ticket_summary')!.handler({ customer_name: 'ABC Academy', issue: 'Activation failed with limit reached.' });

  assert.match(result.content[0]!.text, /ABC Academy/);
  assert.match(result.content[0]!.text, /No raw license keys/);
  assert.doesNotMatch(result.content[0]!.text, /KBT-[A-Z0-9-]+/);
});
