#!/usr/bin/env node
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

import { configFromEnv } from './config.js';
import { createToolRegistry } from './tools.js';
import type { ClientRuntime, ToolHandler } from './types.js';

const runtime = z.enum(['laravel', 'typescript', 'spring-boot', 'django']);
const confirmationSchema = {
  confirmed_by: z.string().min(1).describe('Human operator or approver who confirmed this write action.'),
  confirmation_note: z.string().min(1).describe('Why the write action is approved.'),
};

export function createKernelBridgeMcpServer(env: NodeJS.ProcessEnv = process.env): McpServer {
  const config = configFromEnv(env);
  const registry = createToolRegistry({ config });
  const server = new McpServer({ name: 'kernelbridge-licensing', version: '0.1.0' });

  register(server, registry, 'kernelbridge_find_customer', {
    query: z.string().optional().describe('Name, email, customer number, or UUID.'),
    limit: z.number().int().min(1).max(50).optional().describe('Maximum records to return.'),
  });
  register(server, registry, 'kernelbridge_list_customer_services', {
    customer_uuid: z.string().describe('Customer public UUID.'),
  });
  register(server, registry, 'kernelbridge_explain_activation_issue', {
    license_uuid: z.string().optional().describe('License public UUID.'),
    activation_uuid: z.string().optional().describe('Activation public UUID.'),
    license_key_lookup: z.string().optional().describe('Redacted/lookup-safe license key reference, never a full raw key.'),
  });
  register(server, registry, 'kernelbridge_get_service_setup', {
    subscription_uuid: z.string().describe('Subscription public UUID.'),
    runtime: runtime.describe('Client package runtime.'),
  });
  register(server, registry, 'kernelbridge_generate_setup_template', {
    runtime: runtime.describe('Client package runtime.'),
    product_code: z.string().optional().describe('Product code, such as ALIGNEX.'),
    api_url: z.string().url().optional().describe('KernelBridge API URL.'),
    api_token: z.string().optional().describe('Product machine token. Omit when preparing generic docs.'),
    cache_signing_key: z.string().optional().describe('Local cache signing key. Omit when preparing generic docs.'),
  });
  register(server, registry, 'kernelbridge_list_license_activations', {
    license_uuid: z.string().describe('License public UUID.'),
  });
  register(server, registry, 'kernelbridge_issue_license', {
    subscription_uuid: z.string().describe('Subscription public UUID.'),
    max_activations: z.number().int().min(1).max(100).optional().describe('Maximum allowed active devices.'),
    ...confirmationSchema,
  });
  register(server, registry, 'kernelbridge_deactivate_license_activation', {
    activation_uuid: z.string().describe('Activation public UUID.'),
    reason: z.string().optional().describe('Operator reason for audit logs.'),
    ...confirmationSchema,
  });
  register(server, registry, 'kernelbridge_activation_support_playbook', {
    license_uuid: z.string().optional().describe('License public UUID.'),
    activation_uuid: z.string().optional().describe('Activation public UUID.'),
  });
  register(server, registry, 'kernelbridge_prepare_onboarding_workflow', {
    subscription_uuid: z.string().describe('Subscription public UUID.'),
    runtime: runtime.describe('Client package runtime.'),
  });
  register(server, registry, 'kernelbridge_analyze_customer_renewal_risk', {
    customer_uuid: z.string().describe('Customer public UUID.'),
    within_months: z.number().int().min(1).max(12).optional().describe('Renewal risk window in months.'),
  });
  register(server, registry, 'kernelbridge_generate_safe_ticket_summary', {
    customer_uuid: z.string().optional().describe('Customer public UUID.'),
    customer_name: z.string().optional().describe('Customer display name for the ticket summary.'),
    customer_email: z.string().optional().describe('Customer email for the ticket summary.'),
    license_uuid: z.string().optional().describe('License public UUID.'),
    activation_uuid: z.string().optional().describe('Activation public UUID.'),
    issue: z.string().optional().describe('Plain-language customer issue or symptom.'),
  });
  register(server, registry, 'kernelbridge_check_package_compatibility', {
    runtime: runtime.describe('Client package runtime.'),
    installed_version: z.string().optional().describe('Installed package version, such as 1.0.0.'),
  });
  register(server, registry, 'kernelbridge_list_docs', {});
  register(server, registry, 'kernelbridge_read_doc', {
    uri: z.string().describe('Documentation URI, resource name, or relative path.'),
  });

  return server;
}

function register<TInput extends Record<string, unknown>>(server: McpServer, registry: ToolHandler[], name: string, schema: Record<string, z.ZodTypeAny>): void {
  const tool = registry.find((item) => item.name === name);
  if (!tool) throw new Error(`Tool ${name} is not registered.`);
  server.tool(tool.name, tool.description, schema, async (input): Promise<any> => tool.handler(input as TInput));
}

if (import.meta.url === `file://${process.argv[1]?.replace(/\\/g, '/')}`) {
  const server = createKernelBridgeMcpServer();
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

export type { ClientRuntime };


