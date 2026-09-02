import assert from 'node:assert/strict';
import { test } from 'node:test';
import Database from 'better-sqlite3';
import { KernelBridgeUnavailableError, LicenseAccessError, LicenseActivationService, LicenseCacheService, LicenseGuard, LicenseVerificationService, type LicenseStore, type SecretVault, KernelBridgeLicenseClient, licensingOptionsFromEnv, SqliteLicenseStore, type SqliteDatabase } from '../src/index.js';

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

const active = { license_uuid: 'license-uuid', activation_uuid: 'activation-uuid', subscription_uuid: 'subscription-uuid', status: 'active' as const, expires_at: '2030-01-01T00:00:00.000Z' };
const options = { apiUrl: 'https://kernelbridge.test/api/v1', productCode: 'ALIGNEX', apiToken: 'test-token', now: () => new Date('2027-01-01T00:00:00.000Z') };

function successFetch(requests: Request[]): typeof fetch {
  return async (input, init) => {
    const request = new Request(input, init); requests.push(request); const path = new URL(request.url).pathname;
    const data = path.endsWith('/subscriptions/status') ? { status: 'active', ends_at: active.expires_at }
      : path.endsWith('/entitlements') ? { entitlements: [{ key: 'finance', enabled: true, limit: null }, { key: 'inventory', enabled: false, limit: null }, { key: 'max_users', enabled: true, limit: '25' }] }
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
  const configured = licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_API_TOKEN: 'installation-token', KERNELBRIDGE_OFFLINE_GRACE_MS: '3600000' });
  assert.equal(configured.productCode, 'ALIGNEX'); assert.equal(configured.offlineGraceMs, 3_600_000);
  assert.throws(() => licensingOptionsFromEnv({}), /KERNELBRIDGE_API_URL/);
});
