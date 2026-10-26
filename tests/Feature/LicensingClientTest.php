<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ServerRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;
use KernelBridge\LicensingClient\Http\Middleware\FeatureMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LicenseMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LimitMiddleware;
use KernelBridge\LicensingClient\KernelBridgeLicenseClient;
use KernelBridge\LicensingClient\Models\DeploymentProfile;
use KernelBridge\LicensingClient\Models\LicenseState;
use KernelBridge\LicensingClient\Services\DeploymentProfile as DeploymentProfileService;
use KernelBridge\LicensingClient\Services\DeviceIdentity;
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
            $migration = require base_path('packages/kernelbridge/licensing-client-laravel/database/migrations/2026_01_01_000000_create_kernelbridge_license_states_table.php');
            $migration->up();
        }

        if (! Schema::hasTable('kernelbridge_deployment_profiles')) {
            $migration = require base_path('packages/kernelbridge/licensing-client-laravel/database/migrations/2026_01_01_000002_create_kernelbridge_deployment_profiles_table.php');
            $migration->up();
        }

        $this->mock(DeviceIdentity::class, function ($mock): void {
            $mock->shouldReceive('fingerprint')->andReturn(str_repeat('a', 64));
            $mock->shouldReceive('information')->andReturn(['hostname' => 'TEST-PC', 'os' => 'Test OS']);
        });
        $client = new KernelBridgeLicenseClient;
        $this->cache = new LicenseCacheService;
        $this->activation = new LicenseActivationService($client, $this->cache);
        $this->verification = new LicenseVerificationService($client, $this->cache);

        Route::middleware('kernelbridge.subscription.active')->get('/protected-by-license', fn (): string => 'licensed');
        Route::middleware('web')->get('/client-dashboard', fn (): string => 'client app dashboard');
    }

    public function test_activation_displays_field_validation_details(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => [
            'code' => 'validation_failed', 'message' => 'The request data is invalid.',
            'details' => ['license_key' => ['License is invalid or inactive.']],
        ]], 422)]);
        $this->expectException(KernelBridgeApiException::class);
        $this->expectExceptionMessage('License is invalid or inactive.');
        $this->activation->activate('KBT-TEST');
    }

    public function test_activation_persists_encrypted_signed_state_and_uses_machine_credentials(): void
    {
        $this->fakeSuccessfulApi();

        $state = $this->activation->activate('KBT-LIC-SECRET', 'AlignEx production', 'https://alignex.example.test');

        $this->assertSame('active', $state->status);
        $this->assertSame('lic-00000000-0000-4000-8000-000000000001', $state->license_identifier);
        $this->assertTrue($this->cache->signatureIsValid($state));
        $this->assertTrue($this->cache->hasFeature('finance'));
        $this->assertSame('25', $this->cache->limit('max_users'));
        $raw = DB::table('kernelbridge_license_states')->first();
        $this->assertStringNotContainsString('KBT-LIC-SECRET', $raw->encrypted_license_key);
        $this->assertStringNotContainsString('finance', $raw->entitlement_payload);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer machine-token')
            && $request->hasHeader('X-KernelBridge-Product', 'ALIGNEX')
            && str_ends_with($request->url(), '/licenses/activate')
            && $request['device_name'] === 'AlignEx production'
            && $request['device_info']['platform_url'] === 'https://alignex.example.test');
    }

    public function test_copied_database_cache_is_not_usable_on_another_computer(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        $this->assertTrue($this->cache->hasUsableLicense());
        $this->mock(DeviceIdentity::class, function ($mock): void {
            $mock->shouldReceive('fingerprint')->andReturn(str_repeat('b', 64));
            $mock->shouldReceive('information')->andReturn(['hostname' => 'OTHER-PC']);
        });
        $this->assertFalse($this->cache->hasUsableLicense());
        $this->assertSame('device_mismatch', $this->cache->activationRequirementReason());
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

    public function test_license_cache_can_be_reset_for_reactivation(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');

        $this->assertDatabaseHas('kernelbridge_license_states', ['product_code' => 'ALIGNEX']);

        $this->cache->reset();

        $this->assertDatabaseMissing('kernelbridge_license_states', ['product_code' => 'ALIGNEX']);
        $this->assertSame('unlicensed', $this->cache->state()->status);
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

    public function test_package_routes_render_activation_ui_and_activate_license(): void
    {
        $this->get('/license')
            ->assertOk()
            ->assertSee('Activate your licence')
            ->assertSee('Computer name')
            ->assertSee('Your ALIGNEX URL')
            ->assertSee('ALIGNEX');

        $this->fakeSuccessfulApi();

        $this->post('/license', [
            'license_key' => 'KBT-LIC-SECRET',
            'device_name' => 'AlignEx production',
            'platform_url' => 'https://alignex.example.test',
        ])->assertRedirect('/');

        $this->assertTrue($this->cache->hasUsableLicense());
    }

    public function test_edunexa_activation_page_requests_platform_url_and_computer_name(): void
    {
        config()->set('kernelbridge-licensing.product_code', 'EDUNEXA');

        $this->get('/license')
            ->assertOk()
            ->assertSee('Computer name')
            ->assertSee('Your EduNexa URL')
            ->assertSee('name="platform_url"', false);
    }

    public function test_first_activation_uses_the_configured_setup_redirect(): void
    {
        config()->set('kernelbridge-licensing.product_code', 'EDUNEXA');
        config()->set('kernelbridge-licensing.redirects.after_activation', '/configuration');
        session()->put('url.intended', '/client-dashboard');
        $this->fakeSuccessfulApi();

        $this->post('/license', [
            'license_key' => 'KBT-LIC-SECRET',
            'device_name' => 'EduNexa production',
            'platform_url' => 'https://edunexa.example.test',
        ])->assertRedirect('/configuration');
    }

    public function test_reactivation_does_not_use_the_first_activation_setup_redirect(): void
    {
        config()->set('kernelbridge-licensing.product_code', 'EDUNEXA');
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');
        config()->set('kernelbridge-licensing.redirects.after_activation', '/configuration');
        session()->put('url.intended', '/client-dashboard');

        $this->post('/license', [
            'license_key' => 'KBT-LIC-SECRET',
            'device_name' => 'EduNexa production',
            'platform_url' => 'https://edunexa.example.test',
        ])->assertRedirect('/client-dashboard');
    }

    public function test_activation_page_uses_configured_product_branding(): void
    {
        config()->set('kernelbridge-licensing.product_code', 'MEDIFLOW');
        config()->set('kernelbridge-licensing.ui', [
            'brand_name' => 'MediFlow',
            'brand_subtitle' => 'Healthcare Management',
            'heading' => 'Secure access for your care team.',
            'description' => 'Activate your MediFlow license to protect patient data.',
            'show_logo' => false,
        ]);

        $this->get('/license')
            ->assertOk()
            ->assertSee('<title>Activate MediFlow</title>', false)
            ->assertSee('Healthcare Management')
            ->assertSee('Secure access for your care team.')
            ->assertSee('Activate your MediFlow license to protect patient data.')
            ->assertSee('Activation licence code')
            ->assertSee('Activate MediFlow')
            ->assertDontSee('KernelBridge Technologies');
    }

    public function test_unlicensed_web_requests_redirect_to_activation_ui_but_json_requests_return_402(): void
    {
        $this->get('/protected-by-license')
            ->assertRedirect(route('kernelbridge.license.show'));

        $this->getJson('/protected-by-license')
            ->assertStatus(402)
            ->assertSee('Activate this application before continuing.', false);
    }

    public function test_global_web_protection_blocks_client_app_pages_until_activation(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(LicenseMiddleware::class);

        $this->get('/client-dashboard')
            ->assertRedirect(route('kernelbridge.license.show'));

        $this->fakeSuccessfulApi();
        $this->post('/license', [
            'license_key' => 'KBT-LIC-SECRET',
            'device_name' => 'Client app production',
            'platform_url' => 'https://client.example.test',
        ])->assertRedirect('/');

        $this->get('/client-dashboard')
            ->assertOk()
            ->assertSee('client app dashboard');
    }

    public function test_sensitive_configuration_changes_require_reactivation(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');

        $this->assertTrue($this->cache->hasUsableLicense());

        config()->set('kernelbridge-licensing.api_token', 'tampered-machine-token');

        $this->assertFalse($this->cache->hasUsableLicense());
        $this->assertSame('configuration_tampered', $this->cache->activationRequirementReason());
        $this->assertSame('KernelBridge licensing configuration changed. Re-activation is required.', $this->cache->activationRequirementMessage());
    }

    public function test_renewal_notice_levels_warn_at_three_two_and_one_months(): void
    {
        $this->fakeSuccessfulApi(now()->addMonthsNoOverflow(3)->subDay());
        $this->activation->activate('KBT-LIC-SECRET');
        $this->assertSame('3_months', $this->cache->renewalNoticeLevel());
        $this->assertStringContainsString('about 3 months', $this->cache->renewalNoticeMessage());

        LicenseState::query()->update(['expires_at' => now()->addMonthsNoOverflow(2)->subDay(), 'offline_grace_expires_at' => now()->addMonthsNoOverflow(2)->subDay()]);
        $this->assertSame('2_months', $this->cache->renewalNoticeLevel());

        LicenseState::query()->update(['expires_at' => now()->addMonthNoOverflow()->subDay(), 'offline_grace_expires_at' => now()->addMonthNoOverflow()->subDay()]);
        $this->assertSame('1_month', $this->cache->renewalNoticeLevel());

        $this->get('/protected-by-license')
            ->assertOk()
            ->assertHeader('X-KernelBridge-Renewal-Notice-Level', '1_month')
            ->assertHeader('X-KernelBridge-Renewal-Expires-At');
    }

    public function test_activation_limit_errors_use_a_device_limit_message(): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/licenses/activate' => Http::response([
                'success' => false,
                'error' => [
                    'code' => 'activation_limit_reached',
                    'message' => 'Activation limit reached.',
                ],
            ], 422),
        ]);

        $this->post('/license', [
            'license_key' => 'KBT-LIC-SECRET',
            'device_name' => 'Second device',
            'platform_url' => 'https://client.example.test',
        ])
            ->assertSessionHasErrors('license_key')
            ->assertRedirect();
    }

    public function test_runtime_status_reports_an_explicit_reason_and_revision_for_a_valid_license(): void
    {
        $this->fakeSuccessfulApi();
        $this->activation->activate('KBT-LIC-SECRET');

        $status = $this->cache->status();

        $this->assertTrue($status['active']);
        $this->assertSame('active', $status['status']);
        $this->assertSame('active', $status['reason']);
        $this->assertNotNull($status['revision']);
        $this->assertNotNull($status['effective_at']);
        $this->assertNotNull($status['expires_at']);
        $this->assertNotNull($status['offline_grace_expires_at']);
        $this->assertArrayHasKey('features', $status);

        config()->set('kernelbridge-licensing.api_token', 'tampered-machine-token');

        $tampered = $this->cache->status();
        $this->assertFalse($tampered['active']);
        $this->assertSame('configuration_tampered', $tampered['reason']);
    }

    public function test_deployment_profile_requires_config_to_match_the_persisted_profile(): void
    {
        config()->set('kernelbridge-licensing.deployment.strict_profile', true);
        app(DeploymentProfileService::class)->persist();

        config()->set('kernelbridge-licensing.api_token', 'different-machine-token');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('deployment profile mismatch');

        app(DeploymentProfileService::class)->assertReady();
    }

    public function test_reprovision_profile_updates_the_persisted_deployment_record(): void
    {
        config()->set('kernelbridge-licensing.deployment.strict_profile', true);
        $this->assertDatabaseMissing('kernelbridge_deployment_profiles', ['product_code' => 'ALIGNEX']);

        app(DeploymentProfileService::class)->persist();
        $original = DeploymentProfile::query()->where('product_code', 'ALIGNEX')->firstOrFail();

        config()->set('kernelbridge-licensing.api_token', 'rotated-machine-token');
        $updated = app(DeploymentProfileService::class)->persist();

        $this->assertSame($original->id, $updated->id);
        $this->assertSame('ALIGNEX', $updated->product_code);
        $this->assertSame(hash('sha256', 'rotated-machine-token'), hash('sha256', config('kernelbridge-licensing.api_token')));
        $this->assertSame($updated->fingerprint, app(DeploymentProfileService::class)->fingerprint());
    }

    public function test_deployment_profile_reprovision_is_not_exposed_as_a_web_route(): void
    {
        $this->post('/license/reprovision')->assertNotFound();
    }

    public function test_reprovision_command_persists_a_complete_rotated_deployment_profile(): void
    {
        config()->set('kernelbridge-licensing.deployment.strict_profile', true);
        app(DeploymentProfileService::class)->persist();
        config()->set('kernelbridge-licensing.api_token', 'rotated-machine-token');

        $this->artisan('kernelbridge:reprovision-profile', ['--force' => true])
            ->expectsOutput('Deployment profile persisted for ALIGNEX.')
            ->assertSuccessful();

        $this->assertSame(
            app(DeploymentProfileService::class)->fingerprint(),
            DeploymentProfile::query()->where('product_code', 'ALIGNEX')->firstOrFail()->fingerprint,
        );
    }

    private function fakeSuccessfulApi(?Carbon $expiresAt = null): void
    {
        Http::swap(new Factory);
        $license = [
            'license_uuid' => 'lic-00000000-0000-4000-8000-000000000001',
            'activation_uuid' => 'act-00000000-0000-4000-8000-000000000001',
            'subscription_uuid' => 'sub-00000000-0000-4000-8000-000000000001',
            'status' => 'active',
            'expires_at' => ($expiresAt ?? now()->addYear())->toISOString(),
        ];
        Http::fake([
            '*/licenses/activate' => Http::response(['success' => true, 'data' => $license], 201),
            '*/licenses/verify' => Http::response(['success' => true, 'data' => $license]),
            '*/licenses/deactivate' => Http::response(['success' => true, 'data' => ['status' => 'deactivated']]),
            '*/subscriptions/status*' => Http::response(['success' => true, 'data' => ['status' => 'active', 'ends_at' => ($expiresAt ?? now()->addYear())->toISOString()]]),
            '*/entitlements*' => Http::response(['success' => true, 'data' => ['entitlements' => [
                ['key' => 'finance', 'enabled' => true, 'limit' => null],
                ['key' => 'inventory', 'enabled' => false, 'limit' => null],
                ['key' => 'max_users', 'enabled' => true, 'limit' => '25'],
            ]]]),
        ]);
    }
}
