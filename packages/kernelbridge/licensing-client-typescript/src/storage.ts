import { createHmac, createHash, randomUUID, timingSafeEqual as nodeTimingSafeEqual } from 'node:crypto';
import type { LicenseState, LicenseStore, LicensingClientOptions, RenewalNotice, RenewalNoticeLevel, SecretVault } from './types.js';
import { machineFingerprint } from './machine-identity.js';
import { DeploymentProfileError, DeploymentProfileService } from './deployment-profile.js';

export interface SqliteStatement { get(...parameters: unknown[]): unknown; run(...parameters: unknown[]): unknown }
export interface SqliteDatabase { exec(sql: string): unknown; prepare(sql: string): SqliteStatement }

export class SqliteLicenseStore implements LicenseStore {
  constructor(private readonly database: SqliteDatabase) {
    database.exec('CREATE TABLE IF NOT EXISTS kernelbridge_license_state (product_code TEXT PRIMARY KEY NOT NULL, encrypted_state TEXT NOT NULL, updated_at TEXT NOT NULL)');
  }

  async load(productCode: string): Promise<string | null> {
    const row = this.database.prepare('SELECT encrypted_state FROM kernelbridge_license_state WHERE product_code = ?').get(productCode) as { encrypted_state?: string } | undefined;
    return row?.encrypted_state ?? null;
  }

  async save(productCode: string, encryptedState: string): Promise<void> {
    this.database.prepare('INSERT INTO kernelbridge_license_state (product_code, encrypted_state, updated_at) VALUES (?, ?, ?) ON CONFLICT(product_code) DO UPDATE SET encrypted_state = excluded.encrypted_state, updated_at = excluded.updated_at').run(productCode, encryptedState, new Date().toISOString());
  }

  async remove(productCode: string): Promise<void> { this.database.prepare('DELETE FROM kernelbridge_license_state WHERE product_code = ?').run(productCode); }
}

export interface ElectronSafeStorage { isEncryptionAvailable(): boolean; encryptString(value: string): Buffer; decryptString(value: Buffer): string }
export class ElectronSafeStorageVault implements SecretVault {
  constructor(private readonly safeStorage: ElectronSafeStorage) {}
  isAvailable(): boolean { return this.safeStorage.isEncryptionAvailable(); }
  async encrypt(value: string): Promise<string> { if (!this.isAvailable()) throw new Error('Electron safeStorage encryption is unavailable.'); return this.safeStorage.encryptString(value).toString('base64'); }
  async decrypt(value: string): Promise<string> { if (!this.isAvailable()) throw new Error('Electron safeStorage encryption is unavailable.'); return this.safeStorage.decryptString(Buffer.from(value, 'base64')); }
}

export class LicenseCacheService {
  private readonly productCode: string;

  constructor(private readonly store: LicenseStore, private readonly vault: SecretVault, private readonly options: LicensingClientOptions, private readonly deploymentProfile?: DeploymentProfileService) {
    if (!options.cacheSigningKey && !options.apiToken) throw new TypeError('KERNELBRIDGE_CACHE_SIGNING_KEY is required when using tokenProvider.');
    if (options.tokenProvider && !options.tokenProviderFingerprint) throw new TypeError('tokenProviderFingerprint is required to detect installation credential rotation.');
    this.productCode = options.productCode.toUpperCase();
  }

  async state(): Promise<LicenseState> {
    const encrypted = await this.store.load(this.productCode);
    if (encrypted) {
      try { return this.normalize(JSON.parse(await this.vault.decrypt(encrypted)) as Partial<LicenseState>); }
      catch (error) { throw new LocalLicenseStateError('The local license cache cannot be decrypted or is malformed.', { cause: error }); }
    }
    const state = this.empty(); await this.save(state); return state;
  }

  async save(state: LicenseState): Promise<void> {
    if (!this.vault.isAvailable()) throw new Error('Secure license storage is unavailable.');
    await this.store.save(this.productCode, await this.vault.encrypt(JSON.stringify(this.normalize(state))));
  }

  stampConfigFingerprint(state: LicenseState, at: Date = this.now()): LicenseState {
    state.configFingerprint = this.configFingerprint();
    state.configFingerprintedAt = at.toISOString();
    return state;
  }

  async hasUsableLicense(): Promise<boolean> {
    try {
      const state = await this.state(); const now = this.now().getTime();
      return ['active', 'trial', 'grace'].includes(state.status)
        && !!state.lastSuccessfulVerification
        && (!state.expiresAt || Date.parse(state.expiresAt) > now)
        && !!state.offlineGraceExpiresAt
        && Date.parse(state.offlineGraceExpiresAt) > now
        && this.configFingerprintIsValid(state)
        && this.entitlementSnapshotIsValid(state)
        && await this.machineMatches(state)
        && await this.deploymentProfileReady();
    } catch {
      return false;
    }
  }

  async hasFeature(feature: string): Promise<boolean> { try { const state = await this.state(); return await this.hasUsableLicense() && state.entitlements[feature]?.enabled === true; } catch { return false; } }
  async limit(feature: string): Promise<number | string | null> { if (!await this.hasFeature(feature)) return 0; return (await this.state()).entitlements[feature]?.limit ?? null; }

  async renewalNoticeLevel(): Promise<RenewalNoticeLevel | null> {
    try {
      if (this.options.renewalNoticeEnabled === false || !await this.hasUsableLicense()) return null;
      const state = await this.state();
      if (!state.expiresAt || Date.parse(state.expiresAt) <= this.now().getTime()) return null;

      const thresholds = [...(this.options.renewalNoticeThresholdMonths ?? [3, 2, 1])].filter(months => Number.isInteger(months) && months > 0).sort((a, b) => a - b);
      for (const months of thresholds) {
        if (addMonthsNoOverflow(this.now(), months).getTime() >= Date.parse(state.expiresAt)) return renewalLevel(months);
      }

      return null;
    } catch {
      return null;
    }
  }

  async renewalNoticeMessage(): Promise<string | null> {
    const notice = await this.renewalNotice();
    return notice?.message ?? null;
  }

  async renewalNotice(): Promise<RenewalNotice | null> {
    const level = await this.renewalNoticeLevel();
    if (!level) return null;
    const state = await this.state();
    if (!state.expiresAt) return null;
    const months = Number(level.split('_')[0]);
    const monthText = months === 1 ? '1 month' : `${months} months`;

    return {
      level,
      message: `Your KernelBridge license expires in about ${monthText} on ${formatDate(state.expiresAt)}. Renew to avoid service interruption.`,
      expiresAt: state.expiresAt,
    };
  }
  configFingerprintIsValid(state: LicenseState): boolean {
    if (!state.encryptedLicenseKey || !state.configFingerprint) return !state.encryptedLicenseKey;
    return timingSafeEqual(state.configFingerprint, this.configFingerprint());
  }

  entitlementSnapshotIsValid(state: LicenseState): boolean {
    const hasAnySignatureField = !!(state.signedEntitlementPayload || state.entitlementSignature || state.signatureAlgorithm || state.signatureKeyId);
    if (!hasAnySignatureField) return this.options.requireSignedEntitlements !== true;
    const hasPayload = !!state.signedEntitlementPayload;
    const hasSignature = !!state.entitlementSignature;
    if (!hasPayload || !hasSignature || !this.options.signatureVerifier || state.signatureAlgorithm !== 'RSA-SHA256') return false;
    try {
      if (!this.options.signatureVerifier.verify(state.signedEntitlementPayload!, state.entitlementSignature!, state.signatureKeyId ?? undefined)) return false;
      const claims = JSON.parse(state.signedEntitlementPayload!) as { product_code?: unknown; license_uuid?: unknown; subscription_uuid?: unknown; status?: unknown; expires_at?: unknown; entitlements?: unknown };
      return !!claims && typeof claims === 'object'
        && claims.product_code === this.productCode
        && claims.license_uuid === state.licenseIdentifier
        && claims.subscription_uuid === state.subscriptionIdentifier
        && claims.status === state.status
        && sameExpiry(claims.expires_at, state.expiresAt)
        && Array.isArray(claims.entitlements)
        && stableJson(normalizeEntitlements(claims.entitlements)) === stableJson(state.entitlements);
    } catch { return false; }
  }

  async assertDeploymentProfileReady(): Promise<void> {
    if (!this.deploymentProfile) {
      if (this.options.deploymentProfileMode === 'strict') throw new DeploymentProfileError('deployment_profile_missing', 'A deployment profile service is required in strict mode.');
      return;
    }
    await this.deploymentProfile.assertReady();
  }

  async deploymentProfileStatus() {
    if (this.deploymentProfile) return this.deploymentProfile.status();
    return { mode: this.options.deploymentProfileMode ?? 'compatibility', initialized: false, matches: this.options.deploymentProfileMode !== 'strict', profileVersion: null, initializedAt: null, reprovisionedAt: null } as const;
  }

  private async deploymentProfileReady(): Promise<boolean> {
    try { await this.assertDeploymentProfileReady(); return true; } catch { return false; }
  }

  private async machineMatches(state: LicenseState): Promise<boolean> {
    if (!state.machineFingerprint) return false;
    try { return timingSafeEqual(state.machineFingerprint, await machineFingerprint(this.options.machineIdentityProvider)); } catch { return false; }
  }

  async activationRequirementReason(): Promise<string> {
    try {
      const state = await this.state(); const now = this.now().getTime();
      if (!state.encryptedLicenseKey) return 'license_not_activated';
      if (!await this.deploymentProfileReady()) {
        const profile = await this.deploymentProfileStatus();
        return profile.initialized ? 'configuration_tampered' : 'deployment_profile_missing';
      }
      if (!this.configFingerprintIsValid(state)) return 'configuration_tampered';
      if (!state.machineFingerprint) return 'local_license_tampered';
      try {
        const currentFingerprint = await machineFingerprint(this.options.machineIdentityProvider);
        if (!timingSafeEqual(state.machineFingerprint, currentFingerprint)) return 'device_mismatch';
      } catch { return 'device_identity_unavailable'; }
      if (!this.entitlementSnapshotIsValid(state)) return 'local_license_tampered';
      if (state.status === 'deactivated') return 'license_deactivated';
      if (state.status === 'invalid') return state.lastErrorCode ?? 'license_invalid';
      if (state.expiresAt && Date.parse(state.expiresAt) <= now) return 'license_expired';
      if (!state.offlineGraceExpiresAt || Date.parse(state.offlineGraceExpiresAt) <= now) return 'offline_grace_expired';
      if (!['active', 'trial', 'grace'].includes(state.status)) return 'license_inactive';

      return 'license_required';
    } catch (error) {
      if (error instanceof LocalLicenseStateError) return 'local_license_tampered';
      if (error instanceof DeploymentProfileError) return error.code;
      return 'license_required';
    }
  }

  async activationRequirementMessage(): Promise<string> {
    switch (await this.activationRequirementReason()) {
      case 'activation_limit_reached': return 'This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.';
      case 'configuration_tampered': return 'KernelBridge licensing configuration changed. Re-activation is required.';
      case 'deployment_profile_missing': return 'Initialize the KernelBridge deployment profile before continuing.';
      case 'device_identity_unavailable': return 'A stable machine identity could not be read. Contact your administrator.';
      case 'device_mismatch': return 'This license cache belongs to a different computer. Re-activate this installation.';
      case 'invalid_entitlement_signature': return 'The license entitlement signature is invalid. Reconnect to KernelBridge and verify the license.';
      case 'license_deactivated': return 'This installation has been deactivated. Re-activation is required.';
      case 'license_expired': return 'This license has expired. Renew or activate a valid license.';
      case 'license_not_activated': return 'Activate this application before continuing.';
      case 'license_revoked': return 'This license was revoked by KernelBridge. Activate a valid license.';
      case 'local_license_tampered': return 'The local license cache was changed or cannot be trusted. Re-activation is required.';
      case 'offline_grace_expired': return 'Offline grace has expired. Reconnect to KernelBridge and verify the license.';
      default: return 'An active KernelBridge subscription is required.';
    }
  }

  private configFingerprint(): string {
    return this.sign({
      api_url: this.options.apiUrl.replace(/\/$/, ''),
      product_code: this.productCode,
      api_token_hash: sha256(this.options.apiToken ?? ''),
      token_provider_fingerprint: this.options.tokenProviderFingerprint ?? '',
      app_key_hash: sha256(this.options.appKey ?? ''),
      signature_key_hash: sha256(this.options.cacheSigningKey ?? ''),
      require_signed_entitlements: this.options.requireSignedEntitlements === true,
      ...this.options.extraConfigFingerprintValues,
    });
  }

  private sign(payload: Record<string, unknown>): string {
    const key = this.options.cacheSigningKey ?? this.options.apiToken;
    if (!key) throw new Error('KERNELBRIDGE_CACHE_SIGNING_KEY is required when using tokenProvider.');
    return createHmac('sha256', key).update(stableJson(payload)).digest('hex');
  }

  private normalize(state: Partial<Omit<LicenseState, 'version'>> & { version?: number }): LicenseState {
    if (state.version !== undefined && state.version !== 1 && state.version !== 2) throw new Error('Unsupported local license state version.');
    if (state.productCode !== undefined && state.productCode.toUpperCase() !== this.productCode) throw new Error('Local license state belongs to a different product.');
    if (state.status !== undefined && !['unlicensed', 'pending', 'trial', 'active', 'grace', 'expired', 'suspended', 'cancelled', 'invalid', 'deactivated'].includes(state.status)) throw new Error('Invalid local license status.');
    if (state.entitlements !== undefined && !isEntitlementMap(state.entitlements)) throw new Error('Invalid local entitlement map.');
    if (typeof state.installationIdentifier !== 'string' || state.installationIdentifier.length === 0) throw new Error('Invalid local installation identifier.');
    for (const field of ['machineFingerprint', 'licenseIdentifier', 'subscriptionIdentifier', 'activationIdentifier', 'lastSuccessfulVerification', 'expiresAt', 'offlineGraceExpiresAt', 'signedEntitlementPayload', 'entitlementSignature', 'signatureAlgorithm', 'signatureKeyId', 'encryptedLicenseKey', 'configFingerprint', 'configFingerprintedAt', 'lastErrorCode'] as const) {
      if (state[field] !== undefined && state[field] !== null && typeof state[field] !== 'string') throw new Error(`Invalid local license state field: ${field}.`);
    }
    return { ...this.empty(), ...state, version: 2, productCode: this.productCode, machineFingerprint: state.machineFingerprint ?? null, signatureAlgorithm: state.signatureAlgorithm ?? null };
  }

  private empty(): LicenseState {
    return { version: 2, productCode: this.productCode, installationIdentifier: randomUUID(), machineFingerprint: null, licenseIdentifier: null, subscriptionIdentifier: null, activationIdentifier: null, status: 'unlicensed', lastSuccessfulVerification: null, expiresAt: null, offlineGraceExpiresAt: null, entitlements: {}, signedEntitlementPayload: null, entitlementSignature: null, signatureAlgorithm: null, signatureKeyId: null, encryptedLicenseKey: null, configFingerprint: null, configFingerprintedAt: null, lastErrorCode: null };
  }

  private now(): Date { return (this.options.now ?? (() => new Date()))(); }
}

function stableJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(item => stableJson(item)).join(',')}]`;
  if (value && typeof value === 'object') {
    return `{${Object.entries(value as Record<string, unknown>).filter(([, item]) => item !== undefined).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`).join(',')}}`;
  }

  return JSON.stringify(value);
}

function sha256(value: string): string { return createHash('sha256').update(value).digest('hex'); }
function timingSafeEqual(left: string, right: string): boolean { return left.length === right.length && nodeTimingSafeEqual(Buffer.from(left), Buffer.from(right)); }

export class LocalLicenseStateError extends Error {
  constructor(message: string, options?: ErrorOptions) { super(message, options); this.name = 'LocalLicenseStateError'; }
}

function normalizeEntitlements(items: unknown[]): Record<string, { enabled: boolean; limit: number | string | null }> {
  return Object.fromEntries(items.map(item => {
    if (!item || typeof item !== 'object') throw new Error('Invalid signed entitlement.');
    const entitlement = item as { key?: unknown; enabled?: unknown; limit?: unknown };
    if (typeof entitlement.key !== 'string' || typeof entitlement.enabled !== 'boolean') throw new Error('Invalid signed entitlement.');
    if (entitlement.limit !== null && entitlement.limit !== undefined && typeof entitlement.limit !== 'string' && typeof entitlement.limit !== 'number') throw new Error('Invalid signed entitlement limit.');
    return [entitlement.key, { enabled: entitlement.enabled, limit: (entitlement.limit as number | string | null | undefined) ?? null }];
  }));
}

function isEntitlementMap(value: unknown): value is LicenseState['entitlements'] {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  return Object.values(value as Record<string, unknown>).every(item => {
    if (!item || typeof item !== 'object') return false;
    const entitlement = item as { enabled?: unknown; limit?: unknown };
    return typeof entitlement.enabled === 'boolean' && (entitlement.limit === null || entitlement.limit === undefined || typeof entitlement.limit === 'string' || typeof entitlement.limit === 'number');
  });
}

function sameExpiry(actual: unknown, expected: string | null): boolean {
  if (actual === null || actual === undefined || actual === '') return expected === null;
  if (typeof actual !== 'string' || expected === null) return false;
  return Date.parse(actual) === Date.parse(expected);
}

function addMonthsNoOverflow(date: Date, months: number): Date {
  const copy = new Date(date.getTime());
  const day = copy.getUTCDate();
  copy.setUTCDate(1);
  copy.setUTCMonth(copy.getUTCMonth() + months);
  const lastDay = new Date(Date.UTC(copy.getUTCFullYear(), copy.getUTCMonth() + 1, 0)).getUTCDate();
  copy.setUTCDate(Math.min(day, lastDay));
  return copy;
}

function renewalLevel(months: number): RenewalNoticeLevel {
  return months === 1 ? '1_month' : `${months}_months` as RenewalNoticeLevel;
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value));
}

