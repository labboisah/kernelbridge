import { createVerify } from 'node:crypto';
import type { EntitlementSignatureVerifier } from './types.js';

export class RsaSha256EntitlementVerifier implements EntitlementSignatureVerifier {
  constructor(private readonly publicKey: string) {}
  verify(payload: string, signature: string): boolean { const verifier = createVerify('RSA-SHA256'); verifier.update(payload); verifier.end(); return verifier.verify(this.publicKey, signature, 'base64'); }
}
