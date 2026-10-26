import { createVerify } from 'node:crypto';
import type { EntitlementSignatureVerifier } from './types.js';

export class RsaSha256EntitlementVerifier implements EntitlementSignatureVerifier {
  constructor(private readonly publicKeys: string | Record<string, string>) {}
  verify(payload: string, signature: string, keyId?: string): boolean {
    const publicKey = typeof this.publicKeys === 'string' ? this.publicKeys : keyId ? this.publicKeys[keyId] : undefined;
    if (!publicKey) return false;
    const verifier = createVerify('RSA-SHA256'); verifier.update(payload); verifier.end(); return verifier.verify(publicKey, signature, 'base64');
  }
}
