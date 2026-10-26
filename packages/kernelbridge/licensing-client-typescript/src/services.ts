import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';
import { KernelBridgeLicenseClient } from './client.js';
import { LicenseCacheService } from './storage.js';
import { machineFingerprint } from './machine-identity.js';
import type { EntitlementMap, EntitlementResponse, LicenseResponse, LicenseState, LicensingClientOptions, SubscriptionResponse } from './types.js';

export class LicenseActivationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}
  async activate(licenseKey: string, deviceName?: string): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state(); const fingerprint = await machineFingerprint(this.options.machineIdentityProvider);
    const license = await this.client.activate(licenseKey, state.installationIdentifier, deviceName, fingerprint);
    return this.refresh(state, licenseKey, license, fingerprint);
  }
  private async refresh(state: LicenseState, licenseKey: string, license: LicenseResponse, fingerprint: string): Promise<LicenseState> {
    const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
    return persistVerified(this.cache, this.options, state, licenseKey, license, subscription, entitlements, fingerprint);
  }
}

export class LicenseVerificationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}
  async verify(force = false): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state(); const now = (this.options.now ?? (() => new Date()))().getTime();
    if (!force && state.lastSuccessfulVerification && now - Date.parse(state.lastSuccessfulVerification) < (this.options.verificationIntervalMs ?? 15 * 60_000) && await this.cache.hasUsableLicense()) return state;
    if (!state.encryptedLicenseKey || !state.subscriptionIdentifier) throw new KernelBridgeApiError('No local KernelBridge license has been activated.', 'license_not_activated', 422);
    try {
      const fingerprint = await machineFingerprint(this.options.machineIdentityProvider);
      if (state.machineFingerprint && state.machineFingerprint !== fingerprint) throw new KernelBridgeApiError('This license cache belongs to a different computer.', 'device_mismatch', 403);
      const license = state.machineFingerprint
        ? await this.client.verify(state.encryptedLicenseKey, state.installationIdentifier, fingerprint)
        : await this.client.activate(state.encryptedLicenseKey, state.installationIdentifier, undefined, fingerprint);
      const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
      return persistVerified(this.cache, this.options, state, state.encryptedLicenseKey, license, subscription, entitlements, fingerprint);
    } catch (error) {
      if (error instanceof KernelBridgeUnavailableError && await this.cache.hasUsableLicense()) return this.cache.state();
      if (error instanceof KernelBridgeApiError && error.code !== 'device_mismatch') { state.status = 'invalid'; state.lastErrorCode = error.code; await this.cache.save(state); }
      throw error;
    }
  }
  async deactivate(): Promise<void> { await this.cache.assertDeploymentProfileReady(); const state = await this.cache.state(); if (state.encryptedLicenseKey) await this.client.deactivate(state.encryptedLicenseKey, state.installationIdentifier, await machineFingerprint(this.options.machineIdentityProvider)); state.status = 'deactivated'; state.offlineGraceExpiresAt = new Date(0).toISOString(); await this.cache.save(state); }
  async getEntitlements(force = false): Promise<EntitlementMap> { return (await this.verify(force)).entitlements; }
}

async function persistVerified(cache: LicenseCacheService, options: LicensingClientOptions, state: LicenseState, licenseKey: string, license: LicenseResponse, subscription: SubscriptionResponse, response: EntitlementResponse, fingerprint: string): Promise<LicenseState> {
  const verifiedEntitlements = verifyEntitlements(options, response, license, subscription);
  const now = (options.now ?? (() => new Date()))(); const expiry = license.expires_at ?? subscription.grace_ends_at ?? subscription.ends_at ?? null;
  const grace = new Date(now.getTime() + (options.offlineGraceMs ?? 72 * 60 * 60_000)); if (expiry && grace.getTime() > Date.parse(expiry)) grace.setTime(Date.parse(expiry));
  state.licenseIdentifier = license.license_uuid; state.subscriptionIdentifier = license.subscription_uuid; state.activationIdentifier = license.activation_uuid ?? state.activationIdentifier; state.machineFingerprint = fingerprint;
  state.status = subscription.status ?? license.status; state.lastSuccessfulVerification = now.toISOString(); state.expiresAt = expiry; state.offlineGraceExpiresAt = grace.toISOString(); state.encryptedLicenseKey = licenseKey; state.lastErrorCode = null;
  state.entitlements = verifiedEntitlements; state.signedEntitlementPayload = response.signed_payload ?? null; state.entitlementSignature = response.signature ?? null; state.signatureAlgorithm = response.signature_algorithm ?? null; state.signatureKeyId = response.key_id ?? null;
  cache.stampConfigFingerprint(state, now);
  await cache.save(state); return state;
}

function verifyEntitlements(options: LicensingClientOptions, response: EntitlementResponse, license: LicenseResponse, subscription: SubscriptionResponse): EntitlementMap {
  const hasSignedFields = response.signed_payload !== undefined || response.signature !== undefined || response.signature_algorithm !== undefined || response.key_id !== undefined;
  if (!hasSignedFields) {
    if (options.requireSignedEntitlements) throw invalidEntitlementSignature('The entitlement signature is required but missing.');
    return entitlementMap(response.entitlements);
  }
  if (!response.signed_payload || !response.signature || !response.key_id || response.signature_algorithm !== 'RSA-SHA256' || !options.signatureVerifier) {
    throw invalidEntitlementSignature('The entitlement signature is incomplete or cannot be verified.');
  }
  let claims: { product_code?: unknown; license_uuid?: unknown; subscription_uuid?: unknown; status?: unknown; expires_at?: unknown; entitlements?: unknown };
  try {
    if (!options.signatureVerifier.verify(response.signed_payload, response.signature, response.key_id)) throw new Error('Signature verification failed.');
    claims = JSON.parse(response.signed_payload) as typeof claims;
    if (!claims || typeof claims !== 'object' || Array.isArray(claims)) throw new Error('Signed claims must be an object.');
  } catch (error) {
    throw invalidEntitlementSignature('The entitlement signature is invalid.', error);
  }
  const expectedStatus = subscription.status ?? license.status;
  const expectedExpiry = license.expires_at ?? subscription.grace_ends_at ?? subscription.ends_at ?? null;
  if (claims.product_code !== options.productCode.toUpperCase()
    || claims.license_uuid !== license.license_uuid
    || claims.subscription_uuid !== license.subscription_uuid
    || claims.status !== expectedStatus
    || !Object.hasOwn(claims, 'expires_at')
    || !sameExpiry(claims.expires_at, expectedExpiry)
    || !Array.isArray(claims.entitlements)) {
    throw invalidEntitlementSignature('The signed entitlement claims do not match this license response.');
  }
  const signedMap = entitlementMap(claims.entitlements as EntitlementResponse['entitlements']);
  const responseMap = entitlementMap(response.entitlements);
  if (stableJson(signedMap) !== stableJson(responseMap)) throw invalidEntitlementSignature('The signed entitlement claims do not match the entitlement response.');
  return signedMap;
}

function entitlementMap(entitlements: EntitlementResponse['entitlements']): EntitlementMap {
  if (!Array.isArray(entitlements)) throw invalidEntitlementSignature('The entitlement response is malformed.');
  const result: EntitlementMap = {};
  for (const item of entitlements) {
    if (!item || typeof item.key !== 'string' || typeof item.enabled !== 'boolean' || (item.limit !== null && typeof item.limit !== 'string' && typeof item.limit !== 'number') || Object.hasOwn(result, item.key)) {
      throw invalidEntitlementSignature('The entitlement response is malformed.');
    }
    result[item.key] = { enabled: item.enabled, limit: item.limit };
  }
  return result;
}

function sameExpiry(actual: unknown, expected: string | null): boolean {
  if (actual === null || actual === undefined || actual === '') return expected === null;
  if (typeof actual !== 'string' || expected === null) return false;
  return Date.parse(actual) === Date.parse(expected);
}

function invalidEntitlementSignature(message: string, cause?: unknown): KernelBridgeApiError {
  return new KernelBridgeApiError(message, 'invalid_entitlement_signature', 422, cause === undefined ? undefined : { cause });
}

function stableJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`).join(',')}}`;
  return JSON.stringify(value);
}
