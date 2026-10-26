import assert from 'node:assert/strict';
import { createSign, generateKeyPairSync } from 'node:crypto';
import { test } from 'node:test';
import Database from 'better-sqlite3';
import { DeploymentProfileService, KernelBridgeUnavailableError, LicenseAccessError, LicenseActivationService, LicenseCacheService, LicenseGuard, LicenseVerificationService, type DeploymentProfileStore, type Entitlement, type LicenseStore, type SecretVault, KernelBridgeLicenseClient, licensingOptionsFromEnv, RsaSha256EntitlementVerifier, SqliteLicenseStore, type SqliteDatabase } from '../src/index.js';

class MemoryStore implements LicenseStore {
  value: string | null = null;
  async load(): Promise<string | null> { return this.value; }
  async save(_productCode: string, value: string): Promise<void> { this.value = value; }
  async remove(): Promise<void> { this.value = null; }
}
class TestVault implements SecretVault {
  isAvailable(): boolean { return true; }
  async encrypt(value: string): Promise<string> { return Buffer.from(value).toString('base64'); }
  async decrypt(value: string): Promise<string> { return Buffer.from(value, 'base64').toString(); }
}
class MemoryProfileStore implements DeploymentProfileStore {
  value: string | null = null;
  async load(): Promise<string | null> { return this.value; }
  async save(_productCode: string, value: string): Promise<void> { this.value = value; }
}

const active = { license_uuid: 'license-uuid', activation_uuid: 'activation-uuid', subscription_uuid: 'subscription-uuid', status: 'active' as const, expires_at: '2030-01-01T00:00:00.000Z' };
const machine = 'a'.repeat(64);
const options = { apiUrl: 'https://kernelbridge.test/api/v1', productCode: 'ALIGNEX', apiToken: 'test-token', cacheSigningKey: 'test-cache-signing-key', appKey: 'desktop-app-key', machineIdentityProvider: { getFingerprint: async () => machine }, now: () => new Date('2027-01-01T00:00:00.000Z') };

function successFetch(requests: Request[], signed?: { signed_payload: string; signature: string; signature_algorithm: string; key_id: string }): typeof fetch {
  return async (input, init) => {
    const request = new Request(input, init); requests.push(request); const path = new URL(request.url).pathname;
    const data = path.endsWith('/subscriptions/status') ? { status: 'active', ends_at: active.expires_at }
      : path.endsWith('/entitlements') ? { entitlements: [{ key: 'finance', enabled: true, limit: null }, { key: 'inventory', enabled: false, limit: null }, { key: 'max_users', enabled: true, limit: '25' }], ...signed }
        : path.endsWith('/licenses/deactivate') ? { license_uuid: active.license_uuid, activation_uuid: active.activation_uuid, status: 'deactivated' }
          : active;
    return new Response(JSON.stringify({ success: true, data }), { status: path.endsWith('/licenses/activate') ? 201 : 200, headers: { 'Content-Type': 'application/json' } });
  };
}

test('activates, encrypts local state, and answers entitlement decisions without per-request API calls', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const client = new KernelBridgeLicenseClient(configured); const cache = new LicenseCacheService(store, new TestVault(), configured);
  const state = await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET', 'AlignEx desktop');
  assert.equal(state.status, 'active'); assert.equal(requests.length, 3); assert.ok(store.value); assert.ok(!store.value.includes('KBT-LICENSE-SECRET'));
  assert.equal(await cache.hasFeature('finance'), true); assert.equal(await cache.hasFeature('inventory'), false); assert.equal(await cache.limit('max_users'), '25'); assert.equal(requests.length, 3);
  assert.equal(requests[0]?.headers.get('Authorization'), 'Bearer test-token'); assert.equal(requests[0]?.headers.get('X-KernelBridge-Product'), 'ALIGNEX');
  assert.equal((await requests[0]!.clone().json() as { machine_fingerprint: string }).machine_fingerprint, machine);
});

test('machine identity is bound on activation, verification, and deactivation', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const client = new KernelBridgeLicenseClient(configured); const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET');
  await new LicenseVerificationService(client, cache, configured).verify(true);
  await new LicenseVerificationService(client, cache, configured).deactivate();
  for (const request of requests.filter(item => /\/licenses\/(activate|verify|deactivate)$/.test(new URL(item.url).pathname))) {
    assert.equal((await request.clone().json() as { machine_fingerprint: string }).machine_fingerprint, machine);
  }
});

test('a copied cache fails closed on a different machine without invalidating the central license', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) };
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), new LicenseCacheService(store, new TestVault(), configured), configured).activate('KBT-LICENSE-SECRET');
  const otherMachine = { ...configured, machineIdentityProvider: { getFingerprint: async () => 'b'.repeat(64) } };
  const cache = new LicenseCacheService(store, new TestVault(), otherMachine);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'device_mismatch');
  await assert.rejects(() => new LicenseVerificationService(new KernelBridgeLicenseClient(otherMachine), cache, otherMachine).verify(true), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'device_mismatch');
  assert.equal((await cache.state()).status, 'active');
});

test('legacy state is migrated and rebound through one activation request', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  const legacy = JSON.parse(Buffer.from(store.value!, 'base64').toString()) as Record<string, unknown>;
  legacy.version = 1; delete legacy.machineFingerprint;
  store.value = Buffer.from(JSON.stringify(legacy)).toString('base64');

  assert.equal((await cache.state()).version, 2);
  await new LicenseVerificationService(new KernelBridgeLicenseClient(configured), cache, configured).verify(true);
  assert.equal(new URL(requests[3]!.url).pathname, '/api/v1/licenses/activate');
  assert.equal((await cache.state()).machineFingerprint, machine);
});

test('uses signed local grace during an outage and fails closed after grace', async () => {
  const store = new MemoryStore(); const good = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), good);
  await new LicenseActivationService(new KernelBridgeLicenseClient(good), cache, good).activate('KBT-LICENSE-SECRET');
  const outage = { ...options, fetch: (async () => { throw new Error('offline'); }) as typeof fetch };
  assert.equal((await new LicenseVerificationService(new KernelBridgeLicenseClient(outage), cache, outage).verify(true)).status, 'active');
  const state = await cache.state(); state.offlineGraceExpiresAt = '2026-12-31T23:59:59.000Z'; await cache.save(state);
  await assert.rejects(() => new LicenseVerificationService(new KernelBridgeLicenseClient(outage), cache, outage).verify(true), KernelBridgeUnavailableError);
});

test('active, feature, and plan limit guards use cached decisions', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET'); const guard = new LicenseGuard(cache);
  await guard.assertActive(); await guard.assertFeature('finance'); await guard.assertLimit('max_users', 25);
  await assert.rejects(() => guard.assertFeature('inventory'), (error: unknown) => error instanceof LicenseAccessError && error.code === 'feature_unavailable');
  await assert.rejects(() => guard.assertLimit('max_users', 26), (error: unknown) => error instanceof LicenseAccessError && error.code === 'limit_exceeded');
});
test('guard decision asks host app to show activation before activation', async () => {
  const cache = new LicenseCacheService(new MemoryStore(), new TestVault(), options);
  const guard = new LicenseGuard(cache);
  const decision = await guard.checkActive();

  assert.equal(decision.allowed, false);
  assert.equal(decision.reason, 'license_not_activated');
  assert.equal(decision.message, 'Activate this application before continuing.');
  assert.equal(await guard.shouldShowActivation(), true);
});

test('sensitive configuration changes require reactivation', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');

  assert.equal(await cache.hasUsableLicense(), true);

  const tampered = { ...configured, apiToken: 'tampered-token' };
  const tamperedCache = new LicenseCacheService(store, new TestVault(), tampered);

  assert.equal(await tamperedCache.hasUsableLicense(), false);
  assert.equal(await tamperedCache.activationRequirementReason(), 'configuration_tampered');
  assert.equal(await tamperedCache.activationRequirementMessage(), 'KernelBridge licensing configuration changed. Re-activation is required.');
  await assert.rejects(() => new LicenseGuard(tamperedCache).assertActive(), (error: unknown) => error instanceof LicenseAccessError && error.code === 'configuration_tampered');
});

test('signed entitlement claims are verified and cached entitlement edits fail closed', async () => {
  const entitlements: Entitlement[] = [{ key: 'finance', enabled: true, limit: null }, { key: 'inventory', enabled: false, limit: null }, { key: 'max_users', enabled: true, limit: '25' }];
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  const signed_payload = JSON.stringify({ product_code: 'ALIGNEX', license_uuid: active.license_uuid, subscription_uuid: active.subscription_uuid, status: 'active', expires_at: active.expires_at, entitlements });
  const signer = createSign('RSA-SHA256'); signer.update(signed_payload); signer.end();
  const signed = { signed_payload, signature: signer.sign(privateKey, 'base64'), signature_algorithm: 'RSA-SHA256', key_id: 'key-2026' };
  const requests: Request[] = []; const store = new MemoryStore();
  const configured = { ...options, requireSignedEntitlements: true, signatureVerifier: new RsaSha256EntitlementVerifier({ 'key-2026': publicKey.export({ type: 'spki', format: 'pem' }).toString() }), fetch: successFetch(requests, signed) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  assert.equal(await cache.hasFeature('finance'), true);
  const state = await cache.state(); state.entitlements.finance!.enabled = false; await cache.save(state);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});

test('strict signature mode rejects unsigned entitlement responses', async () => {
  const configured = { ...options, requireSignedEntitlements: true, signatureVerifier: new RsaSha256EntitlementVerifier('unused'), fetch: successFetch([]) };
  const cache = new LicenseCacheService(new MemoryStore(), new TestVault(), configured);
  await assert.rejects(() => new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET'), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'invalid_entitlement_signature');
});

test('deployment profiles initialize explicitly and require confirmed reprovisioning', async () => {
  const store = new MemoryProfileStore(); const vault = new TestVault();
  const configured = { ...options, deploymentProfileMode: 'strict' as const };
  const profiles = new DeploymentProfileService(store, vault, configured);
  assert.equal((await profiles.status()).initialized, false);
  await assert.rejects(() => profiles.assertReady(), /Initialize the KernelBridge deployment profile/);
  await profiles.initializeProfile();
  await profiles.assertReady();
  await assert.rejects(() => profiles.initializeProfile(), /already exists/);
  await assert.rejects(() => profiles.reprovisionProfile(false), /explicit confirmation/);
  const downgraded = new DeploymentProfileService(store, vault, { ...configured, deploymentProfileMode: 'compatibility' });
  await assert.rejects(() => downgraded.assertReady(), /cannot be downgraded/);

  const changed = new DeploymentProfileService(store, vault, { ...configured, apiUrl: 'https://changed.kernelbridge.test/api/v1' });
  await assert.rejects(() => changed.assertReady(), /configuration changed/);
  const reprovisioned = await changed.reprovisionProfile(true);
  assert.equal(reprovisioned.matches, true);
  assert.ok(reprovisioned.reprovisionedAt);
});

test('deployment profile corruption is reported and only confirmed reprovisioning repairs it', async () => {
  const store = new MemoryProfileStore(); const profiles = new DeploymentProfileService(store, new TestVault(), { ...options, deploymentProfileMode: 'strict' });
  await profiles.initializeProfile();
  store.value = Buffer.from('{broken-profile').toString('base64');
  await assert.rejects(() => profiles.assertReady(), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'configuration_tampered');
  await assert.rejects(() => profiles.reprovisionProfile(false), /explicit confirmation/);
  assert.equal((await profiles.reprovisionProfile(true)).matches, true);
});

test('strict deployment profile mode blocks activation until explicit initialization', async () => {
  const requests: Request[] = []; const configured = { ...options, deploymentProfileMode: 'strict' as const, fetch: successFetch(requests) };
  const vault = new TestVault(); const profileService = new DeploymentProfileService(new MemoryProfileStore(), vault, configured);
  const cache = new LicenseCacheService(new MemoryStore(), vault, configured, profileService);
  const activation = new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured);
  await assert.rejects(() => activation.activate('KBT-LICENSE-SECRET'), /Initialize the KernelBridge deployment profile/);
  assert.equal(requests.length, 0);
  await profileService.initializeProfile();
  await activation.activate('KBT-LICENSE-SECRET');
  assert.equal(requests.length, 3);
});

test('corrupt local state reports local tampering instead of an unactivated state', async () => {
  const store = new MemoryStore(); store.value = Buffer.from('{invalid-json').toString('base64');
  const cache = new LicenseCacheService(store, new TestVault(), options);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});

test('malformed decrypted local fields are rejected as tampered state', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  const state = JSON.parse(Buffer.from(store.value!, 'base64').toString()) as Record<string, unknown>;
  state.status = 'administrator';
  store.value = Buffer.from(JSON.stringify(state)).toString('base64');
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});
test('renewal notice levels warn at three two and one months', async () => {
  const store = new MemoryStore();
  const configured = { ...options, now: () => new Date('2029-10-02T00:00:00.000Z'), fetch: successFetch([]) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');

  assert.equal(await cache.renewalNoticeLevel(), '3_months');
  assert.match(await cache.renewalNoticeMessage() ?? '', /about 3 months/);

  const state = await cache.state();
  state.expiresAt = '2029-12-01T00:00:00.000Z';
  state.offlineGraceExpiresAt = state.expiresAt;
  await cache.save(state);
  assert.equal(await cache.renewalNoticeLevel(), '2_months');

  state.expiresAt = '2029-11-01T00:00:00.000Z';
  state.offlineGraceExpiresAt = state.expiresAt;
  await cache.save(state);
  assert.equal(await cache.renewalNoticeLevel(), '1_month');
  assert.equal((await new LicenseGuard(cache).renewalNotice())?.level, '1_month');
});

test('deactivation is sent centrally and invalidates local access', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  const client = new KernelBridgeLicenseClient(configured); await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET');
  await new LicenseVerificationService(client, cache, configured).deactivate(); assert.equal((await cache.state()).status, 'deactivated'); assert.equal(await cache.hasUsableLicense(), false); assert.equal(requests.length, 4);
});

test('SQLite adapter persists, updates, and removes the encrypted envelope', async () => {
  const database = new Database(':memory:'); const store = new SqliteLicenseStore(database as unknown as SqliteDatabase);
  await store.save('ALIGNEX', 'encrypted-one'); assert.equal(await store.load('ALIGNEX'), 'encrypted-one');
  await store.save('ALIGNEX', 'encrypted-two'); assert.equal(await store.load('ALIGNEX'), 'encrypted-two');
  await store.remove('ALIGNEX'); assert.equal(await store.load('ALIGNEX'), null); database.close();
});

test('loads the documented KernelBridge environment configuration', () => {
  const configured = licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_API_TOKEN: 'installation-token', KERNELBRIDGE_OFFLINE_GRACE_MS: '3600000', KERNELBRIDGE_CACHE_SIGNING_KEY: 'cache-key', KERNELBRIDGE_APP_KEY: 'app-key' });
  assert.equal(configured.productCode, 'ALIGNEX'); assert.equal(configured.offlineGraceMs, 3_600_000); assert.equal(configured.cacheSigningKey, 'cache-key'); assert.equal(configured.appKey, 'app-key'); assert.equal(configured.deploymentProfileMode, 'compatibility');
  assert.throws(() => licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_API_TOKEN: 'installation-token', KERNELBRIDGE_DEPLOYMENT_PROFILE_MODE: 'unknown' }), /KERNELBRIDGE_DEPLOYMENT_PROFILE_MODE/);
  assert.throws(() => licensingOptionsFromEnv({}), /KERNELBRIDGE_API_URL/);
});


