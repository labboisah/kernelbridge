export type LicenseAccessErrorCode =
  | 'activation_limit_reached'
  | 'configuration_tampered'
  | 'deployment_profile_missing'
  | 'device_identity_unavailable'
  | 'device_mismatch'
  | 'feature_unavailable'
  | 'invalid_entitlement_signature'
  | 'license_active'
  | 'license_deactivated'
  | 'license_expired'
  | 'license_inactive'
  | 'license_invalid'
  | 'license_not_activated'
  | 'license_required'
  | 'license_revoked'
  | 'limit_exceeded'
  | 'local_license_tampered'
  | 'offline_grace_expired'
  | 'subscription_required';

export class KernelBridgeApiError extends Error {
  constructor(message: string, public readonly code: string, public readonly status: number, options?: ErrorOptions) { super(message, options); this.name = 'KernelBridgeApiError'; }
}

export class KernelBridgeUnavailableError extends Error {
  constructor(message = 'KernelBridge licensing is temporarily unavailable.', options?: ErrorOptions) { super(message, options); this.name = 'KernelBridgeUnavailableError'; }
}

export class LicenseAccessError extends Error {
  constructor(message: string, public readonly code: LicenseAccessErrorCode) { super(message); this.name = 'LicenseAccessError'; }
}
