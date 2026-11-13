<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\LicenseActivationCode;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_issue_and_revoke_an_activation_code(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        [, $license] = $this->licenseFixture('direct');

        $this->actingAs($admin)->get(route('admin.licenses.show', $license->uuid))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Licensing/Show')->where('license.uuid', $license->uuid));

        $this->actingAs($admin)->post(route('admin.licenses.codes.issue', $license->uuid))
            ->assertRedirect(route('admin.licenses.show', $license->uuid))
            ->assertSessionHas('activation_code.kind', 'activation');

        $code = LicenseActivationCode::query()->where('software_license_id', $license->id)->sole();
        $this->assertSame('issued', $code->status);
        $this->assertNull($code->bound_installation_uuid);
        $this->assertSame($admin->id, $code->issued_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'license.activation_code.issued', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->post(route('admin.licenses.codes.revoke', [$license->uuid, $code->uuid]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Unused code revoked and its reserved seat released.');

        $this->assertSame('revoked', $code->fresh()->status);
        $this->assertNotNull($code->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'license.activation_code.revoked', 'actor_id' => $admin->id]);
    }

    public function test_admin_can_issue_an_installation_bound_code_for_an_approved_platform_link(): void
    {
        $admin = $this->userWithRole(UserRole::SuperAdmin);
        [$product, $license] = $this->licenseFixture('platform_account');
        $link = LicensePlatformAccountLink::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => (string) Str::uuid(),
            'status' => 'approved',
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
        ]);
        $installationUuid = (string) Str::uuid();

        $this->actingAs($admin)->post(route('admin.licenses.codes.issue', $license->uuid), [
            'platform_account_link_uuid' => $link->uuid,
            'installation_uuid' => $installationUuid,
            'device_name' => 'Clinic server',
        ])->assertRedirect(route('admin.licenses.show', $license->uuid))
            ->assertSessionHas('activation_code.kind', 'installation')
            ->assertSessionHas('activation_code.installation_uuid', $installationUuid);

        $code = LicenseActivationCode::query()->where('software_license_id', $license->id)->sole();
        $this->assertSame('product_platform', $code->entitlement_source);
        $this->assertSame($installationUuid, $code->bound_installation_uuid);
        $this->assertSame($link->id, $code->license_platform_account_link_id);
    }

    public function test_admin_can_revoke_an_active_installation_and_release_its_seat(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        [$product, $license] = $this->licenseFixture('direct');
        $installation = LicenseInstallation::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'installation_uuid' => (string) Str::uuid(),
            'credential_hash' => hash('sha256', Str::random(48)),
            'device_name' => 'Registered server',
            'runtime_type' => 'laravel',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.licenses.installations.revoke', [$license->uuid, $installation->uuid]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Installation revoked and its seat released.');

        $this->assertSame('revoked', $installation->fresh()->status);
        $this->assertNull($installation->fresh()->credential_encrypted);
        $this->assertDatabaseHas('audit_logs', ['event' => 'license.installation.revoked', 'actor_id' => $admin->id]);
    }

    public function test_only_super_admins_and_admins_can_manage_codes(): void
    {
        $sales = $this->userWithRole(UserRole::Sales);
        [, $license] = $this->licenseFixture('direct');

        $this->actingAs($sales)->get(route('admin.licenses.index'))->assertForbidden();
        $this->actingAs($sales)->post(route('admin.licenses.codes.issue', $license->uuid))->assertForbidden();
    }

    /**
     * @return array{Product, SoftwareLicense}
     */
    private function licenseFixture(string $mode): array
    {
        $product = Product::factory()->create(['code' => strtoupper($mode)]);
        $product->forceFill(['licensing_mode' => $mode, 'runtime_type' => 'laravel'])->save();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'grace_ends_at' => null,
        ]);
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'max_activations' => 3,
            'expires_at' => now()->addMonth(),
        ]);

        return [$product, $license];
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
