<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_all_initial_roles_are_seeded(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value, 'guard_name' => 'web']);
        }
    }

    public function test_guests_are_redirected_away_from_admin_pages(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_customers_cannot_access_admin_pages(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(UserRole::Customer->value);
        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_administrators_can_access_admin_pages(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(UserRole::Admin->value);
        $this->actingAs($administrator)->get('/admin')->assertOk();
    }

    public function test_administrators_can_access_generic_product_management(): void
    {
        $this->seed(ProductSeeder::class);
        $administrator = User::factory()->create();
        $administrator->assignRole(UserRole::Admin->value);

        $this->actingAs($administrator)->get('/admin/products')->assertOk();
    }
}
