export type LicenseStatus = 'unlicensed' | 'pending' | 'trial' | 'active' | 'grace' | 'expired' | 'suspended' | 'cancelled' | 'invalid' | 'deactivated';

export interface Entitlement { key: string; enabled: boolean; limit: number | string | null }
export interface EntitlementMap { [key: string]: { enabled: boolean; limit: number | string | null } }
export type RenewalNoticeLevel = '3_months' | '2_months' | '1_month';
export interface RenewalNotice { level: RenewalNoticeLevel; message: string; expiresAt: string }

export interface LicenseResponse {
  license_uuid: string;
  activation_uuid?: string;
  subscription_uuid: string;
  status: LicenseStatus;
  expires_at?: string | null;
}
export interface DeactivationResponse { license_uuid: string; activation_uuid: string; status: 'deactivated' }

export interface SubscriptionResponse {
  uuid?: string;
  status: LicenseStatus;
  starts_at?: string | null;
  ends_at?: string | null;
  grace_ends_at?: string | null;
}

export interface EntitlementResponse {
  entitlements: Entitlement[];
  signed_payload?: string;
  signature?: string;
  signature_algorithm?: string;
  key_id?: string;
}

export interface LicenseState {
  version: 1;
  productCode: string;
  installationIdentifier: string;
  licenseIdentifier: string | null;
  subscriptionIdentifier: string | null;
  activationIdentifier: string | null;
  status: LicenseStatus;
  lastSuccessfulVerification: string | null;
  expiresAt: string | null;
  offlineGraceExpiresAt: string | null;
  entitlements: EntitlementMap;
  signedEntitlementPayload: string | null;
  entitlementSignature: string | null;
  signatureKeyId: string | null;
  encryptedLicenseKey: string | null;
  configFingerprint: string | null;
  configFingerprintedAt: string | null;
  lastErrorCode: string | null;
}

export interface LicenseStore {
  load(productCode: string): Promise<string | null>;
  save(productCode: string, encryptedState: string): Promise<void>;
  remove(productCode: string): Promise<void>;
}

export interface SecretVault {
  isAvailable(): boolean;
  encrypt(value: string): Promise<string>;
  decrypt(value: string): Promise<string>;
}

export interface LicenseAccessDecision { allowed: boolean; reason: string; message: string }
export interface TokenProvider { getToken(): Promise<string> | string }
export interface EntitlementSignatureVerifier { verify(payload: string, signature: string): boolean }

export interface LicensingClientOptions {
  apiUrl: string;
  productCode: string;
  apiToken?: string;
  tokenProvider?: TokenProvider;
  timeoutMs?: number;
  verificationIntervalMs?: number;
  offlineGraceMs?: number;
  cacheSigningKey?: string;
  appKey?: string;
  extraConfigFingerprintValues?: Record<string, string | number | boolean | null | undefined>;
  renewalNoticeEnabled?: boolean;
  renewalNoticeThresholdMonths?: number[];
  requireSignedEntitlements?: boolean;
  signatureVerifier?: EntitlementSignatureVerifier;
  fetch?: typeof globalThis.fetch;
  now?: () => Date;
}


