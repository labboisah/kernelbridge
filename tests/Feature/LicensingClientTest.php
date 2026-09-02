<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ServerRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;
use KernelBridge\LicensingClient\Http\Middleware\FeatureMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LicenseMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LimitMiddleware;
use KernelBridge\LicensingClient\KernelBridgeLicenseClient;
use KernelBridge\LicensingClient\Models\LicenseState;
use KernelBridge\LicensingClient\Services\LicenseActivationService;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class LicensingClientTest extends TestCase
{
    use RefreshDatabase;

    private LicenseCacheService $cache;

    private LicenseActivationService $activation;

    private LicenseVerificationService $verification;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kernelbridge-licensing', [
            'api_url' => 'https://kernelbridge.test/api/v1',
            'product_code' => 'ALIGNEX',
            'api_token' => 'machine-token',
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 2,
            'verification_interval_minutes' => 15,
            'offline_grace_hours' => 72,
            'signature_key' => 'test-signing-secret',
        ]);

        if (! Schema::hasTable('kernelbridge_license_states')) {
            $migration = require base_path('packages/kernelbridge/licensing-client/database/migrations/2026_01_01_000000_create_kernelbridge_license_states_table.php');
            $migration->up();
        }

        $client = new KernelBridgeLicenseClient;
        $this->cache = new LicenseCacheService;
        $this->activation = new LicenseActivationService($client, $this->cache);
        $this->verification = new LicenseVerificationService($client, $this->cache);
    }

    public function test_activation_persists_encrypted_signed_state_and_uses_machine_credentials(): void
    {
        $this->fakeSuccessfulApi();

        $state = $this->activation->activate('KBT-LIC-SECRET', 'AlignEx production');

        $this->assertSame('active', $state->status);
        $this->assertSame('lic-00000000-0000-4000-8000-000000000001', $state->license_identifier);
        $this->assertTrue($this->cache->signatureIsValid($state));
        $this->assertTrue($this->cache->hasFeature('finance'));
        $this->assertSame('25', $this->cache->limit('max_users'));
        $raw = DB::table('kernelbridge_license_states')->first();
        $this->assertStringNotContainsString('KBT-LIC-SECRET', $raw->encrypted_license_key);
        $this->assertStringNotContainsString('finance', $raw->entitlement_payload);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer machine-token')
            && $request->hasHeader('X-KernelBridge-Product', 'ALIGNEX'));
    }

    public function test_recent_verification_uses_local_cache_without_an_api_request(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        Http::swap(new Factory);
        Http::fake();

        $state = $this->verification->verify();

        $this->assertSame('active', $state->status);
        Http::assertNothingSent();
    }

    public function test_temporary_outage_uses_signed_grace_but_fails_closed_after_grace(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);

        $this->assertSame('active', $this->verification->verify(true)->status);

        LicenseState::query()->update(['offline_grace_expires_at' => now()->subMinute()]);
        $this->expectException(KernelBridgeUnavailableException::class);
        $this->verification->verify(true);
    }

    public function test_explicit_rejection_invalidates_cache_and_deactivation_is_persisted(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['code' => 'license_revoked', 'message' => 'Revoked']], 422)]);

        try {
            $this->verification->verify(true);
            $this->fail('The revoked license should be rejected.');
        } catch (KernelBridgeApiException $exception) {
            $this->assertSame('license_revoked', $exception->errorCode);
        }
        $this->assertSame('invalid', $this->cache->state()->refresh()->status);
        $this->assertFalse($this->cache->hasUsableLicense());

        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        $this->verification->deactivate();
        $this->assertSame('deactivated', $this->cache->state()->refresh()->status);
    }

    public function test_subscription_feature_and_limit_middleware_enforce_cached_entitlements(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        $request = ServerRequest::create('/dashboard');
        $next = fn (): Response => new Response('allowed');

        $this->assertSame(200, (new LicenseMiddleware($this->cache))->handle($request, $next)->getStatusCode());
        $this->assertSame(200, (new FeatureMiddleware($this->cache))->handle($request, $next, 'finance')->getStatusCode());
        $this->assertSame(200, (new LimitMiddleware($this->cache))->handle($request, $next, 'max_users', '25')->getStatusCode());

        $this->expectException(HttpException::class);
        (new FeatureMiddleware($this->cache))->handle($request, $next, 'inventory');
    }

    public function test_tampered_entitlement_payload_is_rejected(): void
    {
        $this->fakeSuccessfulApi();
        $state = $this->activation->activate('KBT-LIC-SECRET');
        DB::table('kernelbridge_license_states')->where('id', $state->id)->update(['entitlement_signature' => str_repeat('0', 64)]);

        $this->assertFalse($this->cache->hasUsableLicense());
        $this->assertFalse($this->cache->hasFeature('finance'));
    }

    private function fakeSuccessfulApi(): void
    {
        Http::swap(new Factory);
        $license = [
            'license_uuid' => 'lic-00000000-0000-4000-8000-000000000001',
            'activation_uuid' => 'act-00000000-0000-4000-8000-000000000001',
            'subscription_uuid' => 'sub-00000000-0000-4000-8000-000000000001',
            'status' => 'active',
            'expires_at' => now()->addYear()->toISOString(),
        ];
        Http::fake([
            '*/licenses/activate' => Http::response(['success' => true, 'data' => $license], 201),
            '*/licenses/verify' => Http::response(['success' => true, 'data' => $license]),
            '*/licenses/deactivate' => Http::response(['success' => true, 'data' => ['status' => 'deactivated']]),
            '*/subscriptions/status*' => Http::response(['success' => true, 'data' => ['status' => 'active', 'ends_at' => now()->addYear()->toISOString()]]),
            '*/entitlements*' => Http::response(['success' => true, 'data' => ['entitlements' => [
                ['key' => 'finance', 'enabled' => true, 'limit' => null],
                ['key' => 'inventory', 'enabled' => false, 'limit' => null],
                ['key' => 'max_users', 'enabled' => true, 'limit' => '25'],
            ]]]),
        ]);
    }
}
