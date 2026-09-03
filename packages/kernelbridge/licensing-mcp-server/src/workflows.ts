import type { ClientRuntime, SetupTemplate } from './types.js';

export interface ServiceSummary {
  subscription_uuid: string;
  subscription_number?: string;
  status?: string;
  starts_at?: string;
  ends_at?: string;
  product?: { code?: string; name?: string };
  plan?: { name?: string };
  license?: {
    uuid: string;
    status?: string;
    max_activations?: number;
    active_activations?: number;
    expires_at?: string;
  } | null;
}

export interface ActivationIssueSummary {
  license_uuid?: string;
  status?: string;
  subscription_status?: string;
  active_activations?: number;
  max_activations?: number;
  reason?: string;
  message?: string;
}

const PACKAGE_COMPATIBILITY: Record<ClientRuntime, { packageName: string; minimumVersion: string; currentVersion: string; notes: string[] }> = {
  laravel: { packageName: 'kernelbridge/licensing-client-laravel', minimumVersion: '1.0.0', currentVersion: '1.0.0', notes: ['Requires Laravel middleware registration and published config.', 'Supports activation UI, route protection, and renewal notices.'] },
  typescript: { packageName: '@kernelbridge/licensing-client-typescript', minimumVersion: '1.0.0', currentVersion: '1.0.0', notes: ['Works with Node/Electron/server runtimes that can persist local license state.', 'Supports guard checks and renewal notices.'] },
  'spring-boot': { packageName: 'com.kernelbridge:kernelbridge-licensing-spring-boot-starter', minimumVersion: '1.0.0', currentVersion: '1.0.0', notes: ['Requires Spring Boot auto-configuration and application properties.', 'Supports servlet protection and renewal notices.'] },
  django: { packageName: 'kernelbridge-licensing-django', minimumVersion: '1.0.0', currentVersion: '1.0.0', notes: ['Requires Django app, middleware, and migrations.', 'Supports decorators, middleware protection, and renewal notices.'] },
};

export function activationSupportPlaybook(issue: ActivationIssueSummary, activations: unknown[] = []): Record<string, unknown> {
  const reason = issue.reason ?? 'unknown';
  const nextSteps: Record<string, string[]> = {
    activation_limit_reached: ['Confirm which device should remain active.', 'List activations and identify the retired or duplicate installation.', 'Deactivate the retired activation only after human confirmation.', 'Ask the customer to retry activation from the intended device.'],
    license_expired: ['Confirm subscription renewal/payment status.', 'Renew or extend the subscription before retrying activation.', 'Ask the customer to verify again after renewal is active.'],
    license_inactive: ['Check whether the license was suspended, cancelled, or manually disabled.', 'Reactivate only after account approval.', 'Ask the customer to retry activation after the license is active.'],
    subscription_inactive: ['Confirm the subscription status in KernelBridge.', 'Move the subscription into trial, active, or grace where commercially approved.', 'Ask the customer to retry activation.'],
    license_active: ['Confirm the customer is using the correct product and license.', 'Verify local app URL, product code, API token, and device clock.', 'Ask for the request ID from the failed client app if the problem continues.'],
    unknown: ['Collect license UUID or activation UUID.', 'Run the activation explanation tool again with a public UUID.', 'Escalate with request ID, customer, product, and timestamp if the reason remains unclear.'],
  };

  return {
    reason,
    message: issue.message ?? 'No platform explanation was returned.',
    license_uuid: issue.license_uuid,
    subscription_status: issue.subscription_status,
    activation_usage: issue.active_activations !== undefined && issue.max_activations !== undefined ? `${issue.active_activations}/${issue.max_activations}` : 'unknown',
    activations,
    recommended_next_steps: nextSteps[reason] ?? nextSteps.unknown,
    safe_customer_reply: customerActivationReply(reason, issue),
  };
}

export function onboardingWorkflow(template: SetupTemplate, service?: ServiceSummary): Record<string, unknown> {
  return {
    runtime: template.runtime,
    package: template.packageName,
    service: service ? summarizeService(service) : null,
    steps: [
      'Install the package using the generated command.',
      'Add the generated environment/configuration values to the client app.',
      'Run package migrations or app setup steps where required.',
      'Enable global route protection or attach the package middleware/decorators to protected pages.',
      `Open the activation UI at ${template.activationPath}.`,
      'Activate with the customer license and verify a protected page returns normally.',
      'Confirm renewal notices appear for the configured 3, 2, and 1 month windows.',
    ],
    install: template.install,
    configuration: template.configuration,
    protection: template.protection,
    docsPath: template.docsPath,
  };
}

export function renewalRiskReport(services: ServiceSummary[], withinMonths: number): Record<string, unknown> {
  const now = new Date();
  const threshold = new Date(now);
  threshold.setMonth(threshold.getMonth() + withinMonths);
  const risks = services.map((service) => {
    const endsAt = parseDate(service.ends_at ?? service.license?.expires_at);
    const daysUntilEnd = endsAt ? Math.ceil((endsAt.getTime() - now.getTime()) / 86400000) : null;
    const flags = [
      service.status && !['trial', 'active', 'grace'].includes(service.status) ? `subscription_${service.status}` : null,
      service.license === null ? 'missing_license' : null,
      service.license?.status && service.license.status !== 'active' ? `license_${service.license.status}` : null,
      daysUntilEnd !== null && daysUntilEnd < 0 ? 'expired' : null,
      daysUntilEnd !== null && daysUntilEnd >= 0 && endsAt !== null && endsAt <= threshold ? 'renewal_due_soon' : null,
    ].filter(Boolean);
    return { ...summarizeService(service), days_until_end: daysUntilEnd, risk_flags: flags, risk_level: riskLevel(flags.length, daysUntilEnd) };
  });

  return {
    window_months: withinMonths,
    total_services: services.length,
    at_risk_services: risks.filter((item) => item.risk_flags.length > 0).length,
    services: risks,
    recommended_next_steps: ['Prioritize expired and inactive services first.', 'Contact customers with services due inside the notice window.', 'Confirm missing licenses before onboarding or renewal conversations.'],
  };
}

export function safeTicketSummary(input: { customer?: { uuid?: string; name?: string; email?: string }; services?: ServiceSummary[]; activationIssue?: ActivationIssueSummary; issue?: string }): Record<string, unknown> {
  return {
    customer: input.customer ?? null,
    issue: input.issue ?? input.activationIssue?.message ?? 'Licensing support request.',
    activation_reason: input.activationIssue?.reason ?? null,
    services: (input.services ?? []).map(summarizeService),
    safe_internal_summary: [
      input.customer?.name ? `Customer: ${input.customer.name}` : null,
      input.activationIssue?.reason ? `Activation reason: ${input.activationIssue.reason}` : null,
      input.activationIssue?.message ? `Platform message: ${input.activationIssue.message}` : null,
    ].filter(Boolean).join('\n'),
    customer_reply_draft: input.activationIssue ? customerActivationReply(input.activationIssue.reason ?? 'unknown', input.activationIssue) : 'Thanks for contacting KernelBridge support. We are checking your license status and will follow up with the next step shortly.',
    redaction_note: 'No raw license keys, API tokens, cache signing keys, or credential secrets are included.',
  };
}

export function packageCompatibility(runtime: ClientRuntime, installedVersion?: string): Record<string, unknown> {
  const info = PACKAGE_COMPATIBILITY[runtime];
  const normalized = installedVersion?.trim();
  const status = !normalized ? 'unknown' : compareSemver(normalized, info.minimumVersion) >= 0 ? 'compatible' : 'upgrade_required';
  return { runtime, package: info.packageName, installed_version: normalized ?? null, minimum_version: info.minimumVersion, current_version: info.currentVersion, status, notes: info.notes };
}

function summarizeService(service: ServiceSummary): Record<string, unknown> {
  return {
    subscription_uuid: service.subscription_uuid,
    subscription_number: service.subscription_number,
    status: service.status,
    product: service.product?.code ?? service.product?.name,
    plan: service.plan?.name,
    ends_at: service.ends_at,
    license_uuid: service.license?.uuid ?? null,
    license_status: service.license?.status ?? null,
    activation_usage: service.license ? `${service.license.active_activations ?? 0}/${service.license.max_activations ?? '?'}` : null,
  };
}

function customerActivationReply(reason: string, issue: ActivationIssueSummary): string {
  if (reason === 'activation_limit_reached') return 'Your license has reached its device limit. We can help remove an old device, then you can activate this installation again.';
  if (reason === 'license_expired') return 'Your license period has ended. Once the subscription is renewed, activation will work again.';
  if (reason === 'subscription_inactive') return 'Your subscription is not currently active for activation. We are checking the account status and will confirm the next step.';
  if (reason === 'license_inactive') return 'Your license is not currently active. We are reviewing the account and will confirm once it is ready to use.';
  if (issue.message) return issue.message;
  return 'We are checking your license details and will confirm the next step shortly.';
}

function parseDate(value?: string): Date | null {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

function riskLevel(flagCount: number, daysUntilEnd: number | null): 'low' | 'medium' | 'high' {
  if (flagCount === 0) return 'low';
  if (daysUntilEnd !== null && daysUntilEnd <= 30) return 'high';
  if (flagCount > 1) return 'high';
  return 'medium';
}

function compareSemver(left: string, right: string): number {
  const a = left.split('.').map((part) => Number.parseInt(part, 10) || 0);
  const b = right.split('.').map((part) => Number.parseInt(part, 10) || 0);
  for (let i = 0; i < 3; i++) {
    if ((a[i] ?? 0) > (b[i] ?? 0)) return 1;
    if ((a[i] ?? 0) < (b[i] ?? 0)) return -1;
  }
  return 0;
}
