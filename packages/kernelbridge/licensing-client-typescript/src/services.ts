import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';
import { KernelBridgeLicenseClient } from './client.js';
import { LicenseCacheService } from './storage.js';
import type { EntitlementMap, EntitlementResponse, LicenseResponse, LicenseState, LicensingClientOptions, SubscriptionResponse } from './types.js';

export class LicenseActivationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}
  async activate(licenseKey: string, deviceName?: string): Promise<LicenseState> {
    const state = await this.cache.state(); const license = await this.client.activate(licenseKey, state.installationIdentifier, deviceName);
    return this.refresh(state, licenseKey, license);
  }
  private async refresh(state: LicenseState, licenseKey: string, license: LicenseResponse): Promise<LicenseState> {
    const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
    return persistVerified(this.cache, this.options, state, licenseKey, license, subscription, entitlements);
  }
}

export class LicenseVerificationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}
  async verify(force = false): Promise<LicenseState> {
    const state = await this.cache.state(); const now = (this.options.now ?? (() => new Date()))().getTime();
    if (!force && state.lastSuccessfulVerification && now - Date.parse(state.lastSuccessfulVerification) < (this.options.verificationIntervalMs ?? 15 * 60_000) && await this.cache.hasUsableLicense()) return state;
    if (!state.encryptedLicenseKey || !state.subscriptionIdentifier) throw new KernelBridgeApiError('No local KernelBridge license has been activated.', 'license_not_activated', 422);
    try {
      const license = await this.client.verify(state.encryptedLicenseKey, state.installationIdentifier); const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
      return persistVerified(this.cache, this.options, state, state.encryptedLicenseKey, license, subscription, entitlements);
    } catch (error) {
      if (error instanceof KernelBridgeUnavailableError && await this.cache.hasUsableLicense()) return this.cache.state();
      if (error instanceof KernelBridgeApiError) { state.status = 'invalid'; state.lastErrorCode = error.code; await this.cache.save(state); }
      throw error;
    }
  }
  async deactivate(): Promise<void> { const state = await this.cache.state(); if (state.encryptedLicenseKey) await this.client.deactivate(state.encryptedLicenseKey, state.installationIdentifier); state.status = 'deactivated'; state.offlineGraceExpiresAt = new Date(0).toISOString(); await this.cache.save(state); }
  async getEntitlements(force = false): Promise<EntitlementMap> { return (await this.verify(force)).entitlements; }
}

async function persistVerified(cache: LicenseCacheService, options: LicensingClientOptions, state: LicenseState, licenseKey: string, license: LicenseResponse, subscription: SubscriptionResponse, response: EntitlementResponse): Promise<LicenseState> {
  if (options.requireSignedEntitlements && (!response.signed_payload || !response.signature || !options.signatureVerifier?.verify(response.signed_payload, response.signature))) throw new KernelBridgeApiError('The entitlement signature is missing or invalid.', 'invalid_entitlement_signature', 422);
  if (response.signed_payload && response.signature && options.signatureVerifier && !options.signatureVerifier.verify(response.signed_payload, response.signature)) throw new KernelBridgeApiError('The entitlement signature is invalid.', 'invalid_entitlement_signature', 422);
  const now = (options.now ?? (() => new Date()))(); const expiry = license.expires_at ?? subscription.grace_ends_at ?? subscription.ends_at ?? null;
  const grace = new Date(now.getTime() + (options.offlineGraceMs ?? 72 * 60 * 60_000)); if (expiry && grace.getTime() > Date.parse(expiry)) grace.setTime(Date.parse(expiry));
  state.licenseIdentifier = license.license_uuid; state.subscriptionIdentifier = license.subscription_uuid; state.activationIdentifier = license.activation_uuid ?? state.activationIdentifier;
  state.status = subscription.status ?? license.status; state.lastSuccessfulVerification = now.toISOString(); state.expiresAt = expiry; state.offlineGraceExpiresAt = grace.toISOString(); state.encryptedLicenseKey = licenseKey; state.lastErrorCode = null;
  state.entitlements = Object.fromEntries(response.entitlements.map(item => [item.key, { enabled: item.enabled, limit: item.limit }])); state.signedEntitlementPayload = response.signed_payload ?? null; state.entitlementSignature = response.signature ?? null; state.signatureKeyId = response.key_id ?? null;
  cache.stampConfigFingerprint(state, now);
  await cache.save(state); return state;
}
