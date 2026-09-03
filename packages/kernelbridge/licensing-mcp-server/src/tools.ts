import { readDocumentationResource, listDocumentationResources } from './docs.js';
import { KernelBridgeApiClient } from './kernelbridge-api.js';
import { setupTemplate } from './setup-templates.js';
import { textResponse, type ClientRuntime, type ToolHandler, type ToolResponse } from './types.js';
import { activationSupportPlaybook, onboardingWorkflow, packageCompatibility, renewalRiskReport, safeTicketSummary, type ActivationIssueSummary, type ServiceSummary } from './workflows.js';
import type { KernelBridgeMcpConfig } from './config.js';

export interface ToolRegistryOptions {
  config: KernelBridgeMcpConfig;
  api?: KernelBridgeApiClient;
}

export function createToolRegistry(options: ToolRegistryOptions): ToolHandler[] {
  const api = options.api ?? new KernelBridgeApiClient(options.config);
  const ensureMutationAllowed = () => {
    if (!options.config.allowMutations) throw new Error('Mutation tools are disabled. Set KERNELBRIDGE_MCP_ALLOW_MUTATIONS=true to enable audited write operations.');
  };

  return [
    {
      name: 'kernelbridge_find_customer',
      description: 'Find customers by name, email, customer number, or UUID for support triage.',
      mutation: false,
      useCase: 'admin_support',
      async handler(input: { query?: string; limit?: number }): Promise<ToolResponse> {
        return textResponse(await api.get('/mcp/customers/search', { query: input.query ?? '', limit: input.limit ?? 10 }));
      },
    },
    {
      name: 'kernelbridge_list_customer_services',
      description: 'List services, subscriptions, licenses, activation counts, and renewal status for one customer.',
      mutation: false,
      useCase: 'admin_support',
      async handler(input: { customer_uuid: string }): Promise<ToolResponse> {
        return textResponse(await api.get(`/mcp/customers/${encodeURIComponent(input.customer_uuid)}/services`));
      },
    },
    {
      name: 'kernelbridge_explain_activation_issue',
      description: 'Explain why a customer, license, or installation cannot activate or verify.',
      mutation: false,
      useCase: 'admin_support',
      async handler(input: { license_uuid?: string; activation_uuid?: string; license_key_lookup?: string }): Promise<ToolResponse> {
        return textResponse(await api.get('/mcp/licenses/explain-activation-issue', input));
      },
    },
    {
      name: 'kernelbridge_get_service_setup',
      description: 'Generate install commands and environment/configuration values for one registered customer service.',
      mutation: false,
      useCase: 'onboarding',
      async handler(input: { subscription_uuid: string; runtime: ClientRuntime }): Promise<ToolResponse> {
        const service = await api.get<{ product_code: string; api_url?: string; api_token?: string; cache_signing_key?: string }>(`/mcp/services/${encodeURIComponent(input.subscription_uuid)}/setup`, { runtime: input.runtime });
        return textResponse(setupTemplate({ runtime: input.runtime, productCode: service.product_code, apiUrl: service.api_url ?? options.config.apiUrl, apiToken: service.api_token, cacheSigningKey: service.cache_signing_key }));
      },
    },
    {
      name: 'kernelbridge_generate_setup_template',
      description: 'Generate package-specific setup commands without reading customer data.',
      mutation: false,
      useCase: 'onboarding',
      async handler(input: { runtime: ClientRuntime; product_code?: string; api_url?: string; api_token?: string; cache_signing_key?: string }): Promise<ToolResponse> {
        return textResponse(setupTemplate({ runtime: input.runtime, productCode: input.product_code ?? options.config.defaultProductCode ?? 'ALIGNEX', apiUrl: input.api_url ?? options.config.apiUrl, apiToken: input.api_token, cacheSigningKey: input.cache_signing_key }));
      },
    },
    {
      name: 'kernelbridge_list_license_activations',
      description: 'List devices/activations for a license UUID, including status and last verification time.',
      mutation: false,
      useCase: 'license_operations',
      async handler(input: { license_uuid: string }): Promise<ToolResponse> {
        return textResponse(await api.get(`/mcp/licenses/${encodeURIComponent(input.license_uuid)}/activations`));
      },
    },
    {
      name: 'kernelbridge_issue_license',
      description: 'Issue a license for an eligible subscription. Mutating and disabled unless explicitly enabled. Requires confirmed_by and confirmation_note.',
      mutation: true,
      useCase: 'license_operations',
      async handler(input: { subscription_uuid: string; max_activations?: number; confirmed_by: string; confirmation_note: string }): Promise<ToolResponse> {
        ensureMutationAllowed();
        return textResponse(await api.post('/mcp/licenses/issue', { subscription_uuid: input.subscription_uuid, max_activations: input.max_activations ?? 1, confirmed_by: input.confirmed_by, confirmation_note: input.confirmation_note }));
      },
    },
    {
      name: 'kernelbridge_deactivate_license_activation',
      description: 'Deactivate one license activation/device. Mutating and disabled unless explicitly enabled. Requires confirmed_by and confirmation_note.',
      mutation: true,
      useCase: 'license_operations',
      async handler(input: { activation_uuid: string; reason?: string; confirmed_by: string; confirmation_note: string }): Promise<ToolResponse> {
        ensureMutationAllowed();
        return textResponse(await api.post(`/mcp/license-activations/${encodeURIComponent(input.activation_uuid)}/deactivate`, { reason: input.reason ?? 'MCP operator request', confirmed_by: input.confirmed_by, confirmation_note: input.confirmation_note }));
      },
    },
    {
      name: 'kernelbridge_activation_support_playbook',
      description: 'Build a guided, safe support playbook for an activation or license issue.',
      mutation: false,
      useCase: 'assistant_workflows',
      async handler(input: { license_uuid?: string; activation_uuid?: string }): Promise<ToolResponse> {
        const issue = await api.get<ActivationIssueSummary>('/mcp/licenses/explain-activation-issue', { license_uuid: input.license_uuid, activation_uuid: input.activation_uuid });
        const activations = issue.license_uuid ? await api.get<unknown[]>(`/mcp/licenses/${encodeURIComponent(issue.license_uuid)}/activations`) : [];
        return textResponse(activationSupportPlaybook(issue, activations));
      },
    },
    {
      name: 'kernelbridge_prepare_onboarding_workflow',
      description: 'Prepare a step-by-step onboarding workflow for a registered customer service and runtime.',
      mutation: false,
      useCase: 'assistant_workflows',
      async handler(input: { subscription_uuid: string; runtime: ClientRuntime }): Promise<ToolResponse> {
        const setup = await api.get<{ product_code: string; api_url?: string; api_token?: string; cache_signing_key?: string }>(`/mcp/services/${encodeURIComponent(input.subscription_uuid)}/setup`, { runtime: input.runtime });
        const template = setupTemplate({ runtime: input.runtime, productCode: setup.product_code, apiUrl: setup.api_url ?? options.config.apiUrl, apiToken: setup.api_token, cacheSigningKey: setup.cache_signing_key });
        return textResponse(onboardingWorkflow(template));
      },
    },
    {
      name: 'kernelbridge_analyze_customer_renewal_risk',
      description: 'Analyze renewal risk for a customer service list within a configurable month window.',
      mutation: false,
      useCase: 'assistant_workflows',
      async handler(input: { customer_uuid: string; within_months?: number }): Promise<ToolResponse> {
        const services = await api.get<ServiceSummary[]>(`/mcp/customers/${encodeURIComponent(input.customer_uuid)}/services`);
        return textResponse(renewalRiskReport(services, input.within_months ?? 3));
      },
    },
    {
      name: 'kernelbridge_generate_safe_ticket_summary',
      description: 'Generate a support-ticket-safe summary that excludes raw licenses, tokens, and secrets.',
      mutation: false,
      useCase: 'assistant_workflows',
      async handler(input: { customer_uuid?: string; customer_name?: string; customer_email?: string; license_uuid?: string; activation_uuid?: string; issue?: string }): Promise<ToolResponse> {
        const services = input.customer_uuid ? await api.get<ServiceSummary[]>(`/mcp/customers/${encodeURIComponent(input.customer_uuid)}/services`) : [];
        const activationIssue = input.license_uuid || input.activation_uuid ? await api.get<ActivationIssueSummary>('/mcp/licenses/explain-activation-issue', { license_uuid: input.license_uuid, activation_uuid: input.activation_uuid }) : undefined;
        const customer = Object.fromEntries(Object.entries({ uuid: input.customer_uuid, name: input.customer_name, email: input.customer_email }).filter(([, value]) => value !== undefined));
        const summaryInput: Parameters<typeof safeTicketSummary>[0] = { services };
        if (Object.keys(customer).length > 0) summaryInput.customer = customer;
        if (activationIssue) summaryInput.activationIssue = activationIssue;
        if (input.issue) summaryInput.issue = input.issue;
        return textResponse(safeTicketSummary(summaryInput));
      },
    },
    {
      name: 'kernelbridge_check_package_compatibility',
      description: 'Check whether a client package runtime/version is compatible with the current MCP workflow expectations.',
      mutation: false,
      useCase: 'assistant_workflows',
      async handler(input: { runtime: ClientRuntime; installed_version?: string }): Promise<ToolResponse> {
        return textResponse(packageCompatibility(input.runtime, input.installed_version));
      },
    },
    {
      name: 'kernelbridge_list_docs',
      description: 'List KernelBridge licensing documentation resources exposed through MCP.',
      mutation: false,
      useCase: 'documentation',
      async handler(): Promise<ToolResponse> {
        return textResponse(listDocumentationResources());
      },
    },
    {
      name: 'kernelbridge_read_doc',
      description: 'Read one KernelBridge licensing documentation resource by URI, name, or relative path.',
      mutation: false,
      useCase: 'documentation',
      async handler(input: { uri: string }): Promise<ToolResponse> {
        return textResponse(await readDocumentationResource(options.config.docsRoot, input.uri));
      },
    },
  ];
}



