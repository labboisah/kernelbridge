<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_update_staff_role_permissions_without_losing_control_access(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $supportRole = Role::findByName(UserRole::Support->value);

        $this->actingAs($admin)->get(route('admin.access-control.index'))->assertOk();
        $this->put(route('admin.access-control.update', $supportRole), ['permissions' => ['support.view', 'support.reply']])->assertRedirect();
        $this->assertTrue($supportRole->fresh()->hasPermissionTo('support.view'));
        $this->assertFalse($supportRole->fresh()->hasPermissionTo('support.manage'));

        $adminRole = Role::findByName(UserRole::Admin->value);
        $this->put(route('admin.access-control.update', $adminRole), ['permissions' => ['customer.view']])->assertRedirect();
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('access.manage'));
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('staff.manage'));
    }

    public function test_admin_cannot_change_super_admin_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);

        $this->actingAs($admin)->put(route('admin.access-control.update', Role::findByName(UserRole::SuperAdmin->value)), ['permissions' => []])->assertForbidden();
    }
}
