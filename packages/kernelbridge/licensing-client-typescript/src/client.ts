import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';
import type { DeactivationResponse, EntitlementResponse, LicenseResponse, LicensingClientOptions, SubscriptionResponse } from './types.js';

export class KernelBridgeLicenseClient {
  private readonly fetcher: typeof globalThis.fetch;
  constructor(private readonly options: LicensingClientOptions) {
    if (!options.apiUrl || !options.productCode || (!options.apiToken && !options.tokenProvider)) throw new TypeError('KernelBridge API URL, product code, and token provider are required.');
    this.fetcher = options.fetch ?? globalThis.fetch;
  }

  activate(licenseKey: string, installationIdentifier: string, deviceName?: string, machineFingerprint?: string): Promise<LicenseResponse> {
    return this.request('POST', '/licenses/activate', { license_key: licenseKey, device_id: installationIdentifier, ...(deviceName ? { device_name: deviceName } : {}), ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}) });
  }
  verify(licenseKey: string, installationIdentifier: string, machineFingerprint?: string): Promise<LicenseResponse> {
    return this.request('POST', '/licenses/verify', { license_key: licenseKey, device_id: installationIdentifier, ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}) });
  }
  deactivate(licenseKey: string, installationIdentifier: string, machineFingerprint?: string): Promise<DeactivationResponse> {
    return this.request('POST', '/licenses/deactivate', { license_key: licenseKey, device_id: installationIdentifier, ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}) });
  }
  getEntitlements(subscriptionIdentifier: string): Promise<EntitlementResponse> {
    return this.request('GET', '/entitlements', { subscription_uuid: subscriptionIdentifier });
  }
  getSubscription(subscriptionIdentifier: string): Promise<SubscriptionResponse> {
    return this.request('GET', '/subscriptions/status', { subscription_uuid: subscriptionIdentifier });
  }

  private async request<T>(method: 'GET' | 'POST', path: string, data: Record<string, string>): Promise<T> {
    const token = this.options.tokenProvider ? await this.options.tokenProvider.getToken() : this.options.apiToken!;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.options.timeoutMs ?? 10_000);
    const url = new URL(`${this.options.apiUrl.replace(/\/$/, '')}${path}`);
    if (method === 'GET') Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
    let response: Response;
    try {
      response = await this.fetcher(url, { method, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', 'X-KernelBridge-Product': this.options.productCode.toUpperCase() }, ...(method === 'POST' ? { body: JSON.stringify(data) } : {}), signal: controller.signal });
    } catch (error) {
      throw new KernelBridgeUnavailableError(undefined, { cause: error });
    } finally { clearTimeout(timeout); }
    if (response.status >= 500) throw new KernelBridgeUnavailableError();
    let body: { success?: boolean; data?: T; error?: { code?: string; message?: string } };
    try { body = await response.json() as typeof body; } catch (error) { throw new KernelBridgeUnavailableError('KernelBridge returned an unreadable response.', { cause: error }); }
    if (!response.ok || body.success !== true || body.data === undefined) throw new KernelBridgeApiError(body.error?.message ?? 'KernelBridge rejected the licensing request.', body.error?.code ?? 'license_rejected', response.status);
    return body.data;
  }
}
