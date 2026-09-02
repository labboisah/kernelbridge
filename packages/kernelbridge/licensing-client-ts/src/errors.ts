export class KernelBridgeApiError extends Error {
  constructor(message: string, public readonly code: string, public readonly status: number) { super(message); this.name = 'KernelBridgeApiError'; }
}

export class KernelBridgeUnavailableError extends Error {
  constructor(message = 'KernelBridge licensing is temporarily unavailable.', options?: ErrorOptions) { super(message, options); this.name = 'KernelBridgeUnavailableError'; }
}

export class LicenseAccessError extends Error {
  constructor(message: string, public readonly code: 'subscription_required' | 'feature_unavailable' | 'limit_exceeded') { super(message); this.name = 'LicenseAccessError'; }
}
