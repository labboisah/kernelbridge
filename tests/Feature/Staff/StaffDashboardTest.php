<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StaffDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_operational_staff_receive_the_staff_dashboard_and_not_executive_dashboard(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(UserRole::Support->value);

        $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Staff/Dashboard')->has('cards'));
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_staff_login_redirects_to_staff_workspace(): void
    {
        $staff = User::factory()->create(['password' => 'password']);
        $staff->assignRole(UserRole::Finance->value);

        $this->post(route('login'), ['email' => $staff->email, 'password' => 'password'])->assertRedirect(route('staff.dashboard'));
    }
}
