import { LicenseAccessError, type LicenseAccessErrorCode } from './errors.js';
import { LicenseCacheService } from './storage.js';
import type { LicenseAccessDecision, RenewalNotice } from './types.js';

export class LicenseGuard {
  constructor(private readonly cache: LicenseCacheService) {}

  async checkActive(): Promise<LicenseAccessDecision> {
    if (await this.cache.hasUsableLicense()) return { allowed: true, reason: 'license_active', message: 'KernelBridge license is active.' };
    return { allowed: false, reason: await this.cache.activationRequirementReason(), message: await this.cache.activationRequirementMessage() };
  }

  async shouldShowActivation(): Promise<boolean> { return !(await this.checkActive()).allowed; }
  async renewalNotice(): Promise<RenewalNotice | null> { return this.cache.renewalNotice(); }
  async assertActive(): Promise<void> { const decision = await this.checkActive(); if (!decision.allowed) throw new LicenseAccessError(decision.message, decision.reason as LicenseAccessErrorCode); }
  async assertFeature(feature: string): Promise<void> { await this.assertActive(); if (!await this.cache.hasFeature(feature)) throw new LicenseAccessError(`The '${feature}' feature is not included in this plan.`, 'feature_unavailable'); }
  async assertLimit(feature: string, required: number): Promise<void> { await this.assertFeature(feature); const limit = await this.cache.limit(feature); if (limit === null || limit === 'unlimited') return; if (Number(limit) < required) throw new LicenseAccessError(`The '${feature}' plan limit is insufficient.`, 'limit_exceeded'); }
}

export class PeriodicLicenseVerifier {
  private timer: NodeJS.Timeout | undefined;
  constructor(private readonly verify: () => Promise<unknown>, private readonly intervalMs: number, private readonly onError: (error: unknown) => void = () => undefined) {}
  start(): void { if (this.timer) return; this.timer = setInterval(() => void this.verify().catch(this.onError), this.intervalMs); this.timer.unref(); }
  stop(): void { if (this.timer) clearInterval(this.timer); this.timer = undefined; }
}


