import { requireApiToken, type KernelBridgeMcpConfig } from './config.js';

export interface ApiEnvelope<T> { success: boolean; data?: T; error?: { code?: string; message?: string; details?: unknown } }
export type HttpMethod = 'GET' | 'POST';
export type Fetcher = typeof globalThis.fetch;

export class KernelBridgeApiClient {
  constructor(private readonly config: KernelBridgeMcpConfig, private readonly fetcher: Fetcher = globalThis.fetch) {}

  get<T>(path: string, query: Record<string, string | number | boolean | undefined> = {}): Promise<T> {
    return this.request<T>('GET', path, { query });
  }

  post<T>(path: string, body: Record<string, unknown> = {}): Promise<T> {
    return this.request<T>('POST', path, { body });
  }

  private async request<T>(method: HttpMethod, path: string, options: { query?: Record<string, string | number | boolean | undefined>; body?: Record<string, unknown> }): Promise<T> {
    requireApiToken(this.config);
    const url = new URL(`${this.config.apiUrl}${path}`);
    for (const [key, value] of Object.entries(options.query ?? {})) {
      if (value !== undefined) url.searchParams.set(key, String(value));
    }
    let response: Response;
    try {
      response = await this.fetcher(url, {
        method,
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${this.config.apiToken}`,
          'Content-Type': 'application/json',
          ...(this.config.defaultProductCode ? { 'X-KernelBridge-Product': this.config.defaultProductCode.toUpperCase() } : {}),
        },
        ...(method === 'POST' ? { body: JSON.stringify(options.body ?? {}) } : {}),
      });
    } catch (error) {
      throw new Error(`KernelBridge API request failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    let payload: ApiEnvelope<T>;
    try {
      payload = await response.json() as ApiEnvelope<T>;
    } catch (error) {
      throw new Error(`KernelBridge API returned unreadable JSON with HTTP ${response.status}.`);
    }
    if (!response.ok || payload.success !== true || payload.data === undefined) {
      throw new Error(payload.error?.message ?? `KernelBridge API rejected the request with HTTP ${response.status}.`);
    }
    return payload.data;
  }
}
