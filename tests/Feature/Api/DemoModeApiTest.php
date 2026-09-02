<?php

namespace Tests\Feature\Api;

use App\Domain\Demo\Services\DemoModeService;
use App\Domain\Integration\Services\ApiCredentialService;
use App\Enums\UserRole;
use App\Models\DemoProfile;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoProfileSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoModeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class]);
    }

    public function test_product_retrieves_versioned_restrictions_and_non_overridable_blocks(): void
    {
        [$token] = $this->credential();
        $this->withToken($token)->getJson('/api/v1/demo/config')->assertOk()->assertJsonPath('data.product.code', 'ALIGNEX')->assertJsonPath('data.restrictions.create', true)->assertJsonPath('data.restrictions.delete', false)->assertJsonPath('data.restrictions.sms', false)->assertJsonPath('data.hard_blocks.real_financial_transactions', true)->assertJsonPath('data.hard_blocks.cross_organization_access', true)->assertJsonPath('data.reset.enabled', true)->assertJsonMissingPath('data.product.id');
    }

    public function test_session_pages_features_ctas_and_full_version_requests_are_tracked(): void
    {
        [$token] = $this->credential();
        $session = $this->withToken($token)->postJson('/api/v1/demo/sessions', ['anonymous_identifier' => 'anonymous-browser-cookie', 'organization_identifier' => 'demo-school', 'entry_url' => 'https://alignex.kernelbridge.com'])->assertCreated()->json('data.session_uuid');
        foreach ([['page-1', 'page_view', '/dashboard', null, null], ['feature-1', 'feature_used', null, 'exam_builder', null], ['cta-1', 'cta_clicked', null, null, 'request_demo'], ['full-1', 'request_full_version', null, null, 'upgrade']] as [$id,$type,$page,$feature,$cta]) {
            $this->withToken($token)->postJson('/api/v1/demo/events', ['session_uuid' => $session, 'event_id' => $id, 'event_type' => $type, 'page' => $page, 'feature' => $feature, 'cta' => $cta, 'occurred_at' => now()->toISOString()])->assertStatus(202);
        }
        $this->withToken($token)->postJson("/api/v1/demo/sessions/$session/end")->assertOk();
        $this->assertDatabaseHas('demo_events', ['event_type' => 'request_full_version', 'cta' => 'upgrade']);
        $this->assertDatabaseMissing('demo_sessions', ['anonymous_identifier_hash' => 'anonymous-browser-cookie']);
        $this->assertDatabaseHas('demo_sessions', ['organization_identifier' => 'demo-school']);
    }

    public function test_access_tokens_enforce_expiry_usage_and_are_never_returned_by_config(): void
    {
        [$machineToken] = $this->credential();
        $profile = DemoProfile::query()->whereHas('product', fn ($query) => $query->where('code', 'ALIGNEX'))->firstOrFail();
        $profile->update(['requires_access_token' => true]);
        $issued = app(DemoModeService::class)->issueToken($profile, 'one use', 1, now()->addHour());
        $this->withToken($machineToken)->postJson('/api/v1/demo/sessions', ['anonymous_identifier' => 'a'])->assertUnprocessable();
        $this->withToken($machineToken)->postJson('/api/v1/demo/sessions', ['anonymous_identifier' => 'a', 'access_token' => $issued['access_token']])->assertCreated();
        $this->withToken($machineToken)->postJson('/api/v1/demo/sessions', ['anonymous_identifier' => 'b', 'access_token' => $issued['access_token']])->assertUnprocessable();
        $response = $this->withToken($machineToken)->getJson('/api/v1/demo/config')->assertOk();
        $this->assertArrayNotHasKey('access_token', $response->json('data'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));
        $this->assertStringNotContainsString($issued['access_token'], $response->getContent());
    }

    public function test_reset_acknowledgement_records_execution_without_database_access(): void
    {
        [$token] = $this->credential();
        $this->withToken($token)->postJson('/api/v1/demo/resets/acknowledge', ['instruction_version' => 1])->assertOk()->assertJsonPath('data.acknowledged', true);
        $this->assertDatabaseHas('demo_profiles', ['last_acknowledged_version' => 1]);
        $this->withToken($token)->postJson('/api/v1/demo/resets/acknowledge', ['instruction_version' => 999])->assertUnprocessable();
    }

    public function test_admin_can_view_analytics_and_publish_configuration(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $profile = DemoProfile::query()->firstOrFail();
        DemoProfile::query()->whereHas('product', fn ($query) => $query->where('code', 'STAYCONTROL'))->delete();
        $this->actingAs($admin)->get(route('admin.demo-mode.index'))->assertOk();
        $this->assertSame(Product::query()->where('status', 'active')->count(), DemoProfile::query()->count());
        $this->assertDatabaseHas('demo_profiles', [
            'product_id' => Product::query()->where('code', 'STAYCONTROL')->value('id'),
        ]);
        $payload = $profile->only(['name', 'public_demo_url', 'is_active', 'requires_access_token', 'allow_create', 'allow_edit', 'allow_delete', 'allow_export', 'allow_email', 'allow_sms', 'allow_payment', 'allow_configuration', 'allow_user_management', 'protected_roles', 'protected_accounts', 'configuration', 'reset_enabled', 'reset_schedule', 'reset_instructions', 'next_reset_at']);
        $payload['allow_export'] = true;
        $payload['reset_instructions'] = 'Run the local guarded fixture restore command.';
        $this->actingAs($admin)->put(route('admin.demo-mode.update', $profile), $payload)->assertRedirect();
        $this->assertTrue($profile->fresh()->allow_export);
        $this->assertSame(2, $profile->fresh()->config_version);
        $this->assertSame(2, $profile->fresh()->reset_instruction_version);
    }

    private function credential(): array
    {
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $service = app(ApiCredentialService::class);
        $client = $service->createClient($product, 'AlignEx integration');
        $issued = $service->issue($client, 'test');

        return [$issued['token'], $client];
    }
}
