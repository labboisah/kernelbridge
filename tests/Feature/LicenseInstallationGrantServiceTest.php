<?php

namespace Tests\Feature;

use App\Domain\Licensing\Services\LicenseInstallationGrantService;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\LicenseBusinessClaim;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class LicenseInstallationGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    private LicenseInstallationGrantService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LicenseInstallationGrantService::class);
    }

    public function test_it_issues_a_grant_for_an_active_claimed_business_license(): void
    {
        $businessUuid = (string) Str::uuid();
        $branchUuid = (string) Str::uuid();
        $deviceUuid = (string) Str::uuid();
        $installationUuid = (string) Str::uuid();

        $product = Product::factory()->create(['code' => 'ALIGNEX']);
        $client = ApiClient::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Platform API',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
        ]);

        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'name' => 'Business Pro',
            'slug' => 'business-pro',
        ]);

        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'created_by' => $user->id,
        ]);

        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'key_lookup' => hash('sha256', 'alignex-active-licence'),
            'key_hash' => hash('sha256', 'alignex-active-licence'),
        ]);

        SubscriptionEntitlement::query()->create([
            'subscription_id' => $subscription->id,
            'feature_key' => 'max_server_installations',
            'feature_name' => 'Server installations',
            'enabled' => true,
            'limit_value' => '3',
            'source_plan_id' => $plan->id,
            'effective_from' => now()->subDay(),
        ]);

        LicenseBusinessClaim::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => $businessUuid,
        ]);

        $result = $this->service->issue($client, [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => $branchUuid,
            'device_uuid' => $deviceUuid,
            'installation_uuid' => $installationUuid,
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame('issued', $result['grant']['status']);
        $this->assertSame($businessUuid, $result['grant']['business_uuid']);
        $this->assertSame($installationUuid, $result['grant']['installation_uuid']);
        $this->assertNotSame('', $result['credential']);
        $this->assertMatchesRegularExpression('/^[^\s]+\.[^\s]+$/', $result['credential']);
    }

    public function test_it_rejects_a_second_installation_when_the_limit_has_been_reached(): void
    {
        $product = Product::factory()->create(['code' => 'ALIGNEX']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'limit-test']);
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'created_by' => $user->id,
        ]);
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'key_lookup' => hash('sha256', 'limit-test-license'),
            'key_hash' => hash('sha256', 'limit-test-license'),
        ]);

        SubscriptionEntitlement::query()->create([
            'subscription_id' => $subscription->id,
            'feature_key' => 'max_server_installations',
            'feature_name' => 'Server installations',
            'enabled' => true,
            'limit_value' => '1',
            'source_plan_id' => $plan->id,
            'effective_from' => now()->subDay(),
        ]);

        $businessUuid = (string) Str::uuid();
        $client = ApiClient::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Platform API',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
        ]);

        LicenseBusinessClaim::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => $businessUuid,
        ]);

        $this->service->issue($client, [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => (string) Str::uuid(),
            'device_uuid' => (string) Str::uuid(),
            'installation_uuid' => (string) Str::uuid(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The Business Server installation limit has been reached for this license.');

        $this->service->issue($client, [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => (string) Str::uuid(),
            'device_uuid' => (string) Str::uuid(),
            'installation_uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_it_rejects_rebinding_an_existing_installation_to_a_different_business_binding(): void
    {
        $product = Product::factory()->create(['code' => 'ALIGNEX']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'binding-test']);
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'created_by' => $user->id,
        ]);
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'key_lookup' => hash('sha256', 'binding-test-license'),
            'key_hash' => hash('sha256', 'binding-test-license'),
        ]);

        SubscriptionEntitlement::query()->create([
            'subscription_id' => $subscription->id,
            'feature_key' => 'max_server_installations',
            'feature_name' => 'Server installations',
            'enabled' => true,
            'limit_value' => '5',
            'source_plan_id' => $plan->id,
            'effective_from' => now()->subDay(),
        ]);

        $businessUuid = (string) Str::uuid();
        $branchUuid = (string) Str::uuid();
        $deviceUuid = (string) Str::uuid();
        $installationUuid = (string) Str::uuid();

        $client = ApiClient::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Platform API',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
        ]);

        LicenseBusinessClaim::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => $businessUuid,
        ]);

        $this->service->issue($client, [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => $branchUuid,
            'device_uuid' => $deviceUuid,
            'installation_uuid' => $installationUuid,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This installation is already bound to a different license, business, branch, or device.');

        $this->service->issue($client, [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => (string) Str::uuid(),
            'device_uuid' => (string) Str::uuid(),
            'installation_uuid' => $installationUuid,
        ]);
    }
}
