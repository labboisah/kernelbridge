import { createHash } from 'node:crypto';
import type { DeploymentProfileMode, DeploymentProfileStatus, LicensingClientOptions, SecretVault } from './types.js';

export interface DeploymentProfileRecord {
  version: 1;
  mode: DeploymentProfileMode;
  fingerprint: string;
  initializedAt: string;
  reprovisionedAt: string | null;
}

export interface DeploymentProfileStore {
  load(productCode: string): Promise<string | null>;
  save(productCode: string, encryptedProfile: string): Promise<void>;
}

export interface DeploymentProfileStatement { get(...parameters: unknown[]): unknown; run(...parameters: unknown[]): unknown }
export interface DeploymentProfileDatabase { exec(sql: string): unknown; prepare(sql: string): DeploymentProfileStatement }

export class SqliteDeploymentProfileStore implements DeploymentProfileStore {
  constructor(private readonly database: DeploymentProfileDatabase) {
    database.exec('CREATE TABLE IF NOT EXISTS kernelbridge_deployment_profiles (product_code TEXT PRIMARY KEY NOT NULL, encrypted_profile TEXT NOT NULL, updated_at TEXT NOT NULL)');
  }

  async load(productCode: string): Promise<string | null> {
    const row = this.database.prepare('SELECT encrypted_profile FROM kernelbridge_deployment_profiles WHERE product_code = ?').get(productCode) as { encrypted_profile?: string } | undefined;
    return row?.encrypted_profile ?? null;
  }

  async save(productCode: string, encryptedProfile: string): Promise<void> {
    this.database.prepare('INSERT INTO kernelbridge_deployment_profiles (product_code, encrypted_profile, updated_at) VALUES (?, ?, ?) ON CONFLICT(product_code) DO UPDATE SET encrypted_profile = excluded.encrypted_profile, updated_at = excluded.updated_at').run(productCode, encryptedProfile, new Date().toISOString());
  }
}

export class DeploymentProfileError extends Error {
  constructor(public readonly code: 'deployment_profile_missing' | 'configuration_tampered', message: string) { super(message); this.name = 'DeploymentProfileError'; }
}

export class DeploymentProfileService {
  private readonly productCode: string;
  private readonly mode: DeploymentProfileMode;

  constructor(private readonly store: DeploymentProfileStore, private readonly vault: SecretVault, private readonly options: LicensingClientOptions) {
    this.productCode = options.productCode.toUpperCase();
    this.mode = options.deploymentProfileMode ?? 'compatibility';
  }

  async initializeProfile(): Promise<DeploymentProfileStatus> {
    if (await this.store.load(this.productCode)) throw new Error('A KernelBridge deployment profile already exists. Use confirmed reprovisioning to replace it.');
    const now = this.now().toISOString();
    await this.save({ version: 1, mode: this.mode, fingerprint: this.fingerprint(), initializedAt: now, reprovisionedAt: null });
    return this.status();
  }

  async reprovisionProfile(confirmed: boolean): Promise<DeploymentProfileStatus> {
    if (!confirmed) throw new Error('Deployment profile reprovisioning requires explicit confirmation.');
    const encrypted = await this.store.load(this.productCode);
    if (!encrypted) throw new DeploymentProfileError('deployment_profile_missing', 'Initialize a deployment profile before reprovisioning it.');
    const now = this.now().toISOString();
    let current: DeploymentProfileRecord | null = null;
    try { current = await this.decode(encrypted); } catch { current = null; }
    await this.save({ version: 1, mode: this.mode, fingerprint: this.fingerprint(), initializedAt: current?.initializedAt ?? now, reprovisionedAt: now });
    return this.status();
  }

  async status(): Promise<DeploymentProfileStatus> {
    const profile = await this.load();
    return {
      mode: this.mode,
      initialized: profile !== null,
      matches: profile === null ? this.mode !== 'strict' : profile.fingerprint === this.fingerprint() && !(profile.mode === 'strict' && this.mode !== 'strict'),
      profileVersion: profile?.version ?? null,
      initializedAt: profile?.initializedAt ?? null,
      reprovisionedAt: profile?.reprovisionedAt ?? null,
    };
  }

  async assertReady(): Promise<void> {
    const profile = await this.load();
    if (!profile) {
      if (this.mode === 'strict') throw new DeploymentProfileError('deployment_profile_missing', 'Initialize the KernelBridge deployment profile before licensing this installation.');
      return;
    }
    if (profile.mode === 'strict' && this.mode !== 'strict') throw new DeploymentProfileError('configuration_tampered', 'Strict deployment-profile enforcement cannot be downgraded without confirmed reprovisioning.');
    if (profile.fingerprint !== this.fingerprint()) throw new DeploymentProfileError('configuration_tampered', 'KernelBridge licensing configuration changed. Re-activation is required.');
  }

  async isReady(): Promise<boolean> {
    try { await this.assertReady(); return true; } catch { return false; }
  }

  private async load(): Promise<DeploymentProfileRecord | null> {
    const encrypted = await this.store.load(this.productCode);
    if (!encrypted) return null;
    try { return await this.decode(encrypted); }
    catch (error) {
      if (error instanceof DeploymentProfileError) throw error;
      throw new DeploymentProfileError('configuration_tampered', 'The KernelBridge deployment profile cannot be trusted. Confirm and reprovision it.');
    }
  }

  private async decode(encrypted: string): Promise<DeploymentProfileRecord> {
    if (!this.vault.isAvailable()) throw new Error('Secure deployment profile storage is unavailable.');
    const value: unknown = JSON.parse(await this.vault.decrypt(encrypted));
    if (!isDeploymentProfileRecord(value)) throw new Error('The KernelBridge deployment profile is malformed.');
    return value;
  }

  private async save(profile: DeploymentProfileRecord): Promise<void> {
    if (!this.vault.isAvailable()) throw new Error('Secure deployment profile storage is unavailable.');
    await this.store.save(this.productCode, await this.vault.encrypt(JSON.stringify(profile)));
  }

  private fingerprint(): string {
    return createHash('sha256').update(stableJson({
      api_url: this.options.apiUrl.replace(/\/$/, ''),
      product_code: this.productCode,
      credential_type: this.options.tokenProvider ? 'token_provider' : 'api_token',
      credential_fingerprint: this.options.tokenProviderFingerprint ?? hash(this.options.apiToken ?? ''),
      app_key_hash: hash(this.options.appKey ?? ''),
      cache_signing_key_hash: hash(this.options.cacheSigningKey ?? ''),
      extra: this.options.extraConfigFingerprintValues ?? {},
    })).digest('hex');
  }

  private now(): Date { return (this.options.now ?? (() => new Date()))(); }
}

function isDeploymentProfileRecord(value: unknown): value is DeploymentProfileRecord {
  if (!value || typeof value !== 'object') return false;
  const profile = value as Partial<DeploymentProfileRecord>;
  return profile.version === 1 && ['disabled', 'compatibility', 'strict'].includes(profile.mode!) && typeof profile.fingerprint === 'string' && typeof profile.initializedAt === 'string' && (profile.reprovisionedAt === null || typeof profile.reprovisionedAt === 'string');
}

function stableJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`).join(',')}}`;
  return JSON.stringify(value);
}

function hash(value: string): string { return createHash('sha256').update(value).digest('hex'); }