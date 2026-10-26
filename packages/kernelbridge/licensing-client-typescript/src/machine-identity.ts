import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { LicenseAccessError } from './errors.js';
import type { MachineIdentityProvider } from './types.js';

const machineId = createRequire(import.meta.url)('node-machine-id').machineId as () => Promise<string>;

export class NodeMachineIdentityProvider implements MachineIdentityProvider {
  private fingerprintPromise: Promise<string> | undefined;

  getFingerprint(): Promise<string> {
    this.fingerprintPromise ??= machineId().then(identifier => createHash('sha256').update(`kernelbridge-device-v1|${identifier}`).digest('hex'));
    return this.fingerprintPromise;
  }
}

export async function machineFingerprint(provider?: MachineIdentityProvider): Promise<string> {
  try {
    const fingerprint = await (provider ?? defaultMachineIdentityProvider).getFingerprint();
    if (!/^[a-f0-9]{64}$/i.test(fingerprint)) throw new Error('Machine identity provider must return a SHA-256 fingerprint.');
    return fingerprint.toLowerCase();
  } catch (error) {
    throw new LicenseAccessError('A stable machine identity could not be read. Contact your administrator.', 'device_identity_unavailable');
  }
}

const defaultMachineIdentityProvider = new NodeMachineIdentityProvider();