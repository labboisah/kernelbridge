import type { LicensingClientOptions } from './types.js';

export function licensingOptionsFromEnv(environment: NodeJS.ProcessEnv = process.env): LicensingClientOptions {
  const apiUrl = environment.KERNELBRIDGE_API_URL;
  const productCode = environment.KERNELBRIDGE_PRODUCT_CODE;
  const apiToken = environment.KERNELBRIDGE_API_TOKEN;
  if (!apiUrl || !productCode || !apiToken) throw new TypeError('KERNELBRIDGE_API_URL, KERNELBRIDGE_PRODUCT_CODE, and KERNELBRIDGE_API_TOKEN are required.');

  return {
    apiUrl,
    productCode,
    apiToken,
    timeoutMs: positive(environment.KERNELBRIDGE_API_TIMEOUT_MS, 10_000),
    verificationIntervalMs: positive(environment.KERNELBRIDGE_VERIFICATION_INTERVAL_MS, 15 * 60_000),
    offlineGraceMs: positive(environment.KERNELBRIDGE_OFFLINE_GRACE_MS, 72 * 60 * 60_000),
    requireSignedEntitlements: environment.KERNELBRIDGE_REQUIRE_SIGNED_ENTITLEMENTS === 'true',
  };
}

function positive(value: string | undefined, fallback: number): number {
  const parsed = Number(value ?? fallback);
  if (!Number.isFinite(parsed) || parsed <= 0) throw new TypeError('KernelBridge duration configuration must be a positive number.');
  return parsed;
}
