import type { LicensingClientOptions } from './types.js';

export function licensingOptionsFromEnv(environment: NodeJS.ProcessEnv = process.env): LicensingClientOptions {
  const apiUrl = environment.KERNELBRIDGE_API_URL;
  const productCode = environment.KERNELBRIDGE_PRODUCT_CODE;
  const apiToken = environment.KERNELBRIDGE_API_TOKEN;
  if (!apiUrl || !productCode || !apiToken) throw new TypeError('KERNELBRIDGE_API_URL, KERNELBRIDGE_PRODUCT_CODE, and KERNELBRIDGE_API_TOKEN are required.');

  const options: LicensingClientOptions = {
    apiUrl,
    productCode,
    apiToken,
    timeoutMs: positive(environment.KERNELBRIDGE_API_TIMEOUT_MS, 10_000),
    verificationIntervalMs: positive(environment.KERNELBRIDGE_VERIFICATION_INTERVAL_MS, 15 * 60_000),
    offlineGraceMs: positive(environment.KERNELBRIDGE_OFFLINE_GRACE_MS, 72 * 60 * 60_000),
    renewalNoticeEnabled: environment.KERNELBRIDGE_RENEWAL_NOTICE_ENABLED !== 'false',
    renewalNoticeThresholdMonths: parseMonthThresholds(environment.KERNELBRIDGE_RENEWAL_NOTICE_MONTHS),
    requireSignedEntitlements: environment.KERNELBRIDGE_REQUIRE_SIGNED_ENTITLEMENTS === 'true',
  };

  if (environment.KERNELBRIDGE_CACHE_SIGNING_KEY) options.cacheSigningKey = environment.KERNELBRIDGE_CACHE_SIGNING_KEY;
  if (environment.KERNELBRIDGE_APP_KEY) options.appKey = environment.KERNELBRIDGE_APP_KEY;

  return options;
}

function positive(value: string | undefined, fallback: number): number {
  const parsed = Number(value ?? fallback);
  if (!Number.isFinite(parsed) || parsed <= 0) throw new TypeError('KernelBridge duration configuration must be a positive number.');
  return parsed;
}

function parseMonthThresholds(value: string | undefined): number[] {
  if (!value) return [3, 2, 1];
  const months = value.split(',').map(item => Number(item.trim())).filter(item => Number.isInteger(item) && item > 0);
  return months.length > 0 ? months : [3, 2, 1];
}
