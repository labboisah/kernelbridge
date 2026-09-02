import { randomUUID } from 'node:crypto';
import type { LicenseState, LicenseStore, LicensingClientOptions, SecretVault } from './types.js';

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
  constructor(private readonly store: LicenseStore, private readonly vault: SecretVault, private readonly options: LicensingClientOptions) { this.productCode = options.productCode.toUpperCase(); }
  async state(): Promise<LicenseState> {
    const encrypted = await this.store.load(this.productCode);
    if (encrypted) return JSON.parse(await this.vault.decrypt(encrypted)) as LicenseState;
    const state = this.empty(); await this.save(state); return state;
  }
  async save(state: LicenseState): Promise<void> { if (!this.vault.isAvailable()) throw new Error('Secure license storage is unavailable.'); await this.store.save(this.productCode, await this.vault.encrypt(JSON.stringify(state))); }
  async hasUsableLicense(): Promise<boolean> {
    const state = await this.state(); const now = (this.options.now ?? (() => new Date()))().getTime();
    return ['active', 'trial', 'grace'].includes(state.status) && !!state.lastSuccessfulVerification && (!state.expiresAt || Date.parse(state.expiresAt) > now) && !!state.offlineGraceExpiresAt && Date.parse(state.offlineGraceExpiresAt) > now;
  }
  async hasFeature(feature: string): Promise<boolean> { const state = await this.state(); return await this.hasUsableLicense() && state.entitlements[feature]?.enabled === true; }
  async limit(feature: string): Promise<number | string | null> { if (!await this.hasFeature(feature)) return 0; return (await this.state()).entitlements[feature]?.limit ?? null; }
  private empty(): LicenseState { return { version: 1, productCode: this.productCode, installationIdentifier: randomUUID(), licenseIdentifier: null, subscriptionIdentifier: null, activationIdentifier: null, status: 'unlicensed', lastSuccessfulVerification: null, expiresAt: null, offlineGraceExpiresAt: null, entitlements: {}, signedEntitlementPayload: null, entitlementSignature: null, signatureKeyId: null, encryptedLicenseKey: null, lastErrorCode: null }; }
}
