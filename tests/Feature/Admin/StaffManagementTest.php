<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_administrator_can_create_and_suspend_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::SuperAdmin->value);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'Support Officer', 'email' => 'officer@example.com', 'role' => 'support', 'status' => 'active', 'password' => 'SecurePassword!2026', 'password_confirmation' => 'SecurePassword!2026',
        ])->assertRedirect();

        $staff = User::query()->where('email', 'officer@example.com')->firstOrFail();
        $this->assertTrue($staff->hasRole('support'));

        $this->put(route('admin.staff.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'support', 'status' => 'suspended', 'password' => '', 'password_confirmation' => '',
        ])->assertRedirect();
        $this->assertSame('suspended', $staff->refresh()->status);

        auth()->logout();
        $this->post(route('login'), ['email' => $staff->email, 'password' => 'SecurePassword!2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_non_admin_cannot_manage_staff(): void
    {
        $support = User::factory()->create();
        $support->assignRole(UserRole::Support->value);

        $this->actingAs($support)->get(route('admin.staff.index'))->assertForbidden();
    }
}
