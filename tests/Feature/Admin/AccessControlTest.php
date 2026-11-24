<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
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

    public function test_access_control_screens_are_separate_and_super_admin_only(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(UserRole::Admin->value);
        $routes = [
            'admin.access-control.roles',
            'admin.access-control.permissions',
            'admin.access-control.role-permissions',
            'admin.access-control.user-roles',
            'admin.access-control.user-permissions',
        ];

        foreach ($routes as $route) {
            $this->actingAs($admin)->get(route($route))->assertForbidden();
        }

        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)->get(route('admin.access-control.roles'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AccessControl/Roles'));
        $this->get(route('admin.access-control.roles', ['search' => 'support']))
            ->assertInertia(fn ($page) => $page->where('roles.data.0.name', UserRole::Support->value));
        $this->get(route('admin.access-control.permissions'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AccessControl/Permissions'));
        $this->get(route('admin.access-control.permissions', ['search' => 'support.reply']))
            ->assertInertia(fn ($page) => $page->where('permissions.data.0.name', 'support.reply'));
        $this->get(route('admin.access-control.role-permissions'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AccessControl/RolePermissions'));
        $this->get(route('admin.access-control.user-roles'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AccessControl/UserRoles'));
        $this->get(route('admin.access-control.user-permissions'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AccessControl/UserPermissions'));
    }

    public function test_super_admin_can_manage_custom_roles_and_permissions_but_not_system_entries(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->post(route('admin.access-control.roles.store'), ['name' => 'report_viewer'])
            ->assertRedirect(route('admin.access-control.roles'));
        $role = Role::findByName('report_viewer', 'web');
        $this->put(route('admin.access-control.roles.update', $role), ['name' => 'report_editor'])->assertRedirect(route('admin.access-control.roles'));
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'report_editor']);
        $this->delete(route('admin.access-control.roles.destroy', $role))->assertRedirect(route('admin.access-control.roles'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        $this->post(route('admin.access-control.permissions.store'), ['name' => 'report.export'])
            ->assertRedirect(route('admin.access-control.permissions'));
        $permission = Permission::findByName('report.export', 'web');
        $this->put(route('admin.access-control.permissions.update', $permission), ['name' => 'report.download'])
            ->assertRedirect(route('admin.access-control.permissions'));
        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'name' => 'report.download']);
        $this->delete(route('admin.access-control.permissions.destroy', $permission))
            ->assertRedirect(route('admin.access-control.permissions'));
        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);

        $systemRole = Role::findByName(UserRole::Support->value, 'web');
        $systemPermission = Permission::findByName('support.reply', 'web');
        $this->put(route('admin.access-control.roles.update', $systemRole), ['name' => 'renamed_support'])->assertForbidden();
        $this->delete(route('admin.access-control.roles.destroy', $systemRole))->assertForbidden();
        $this->put(route('admin.access-control.permissions.update', $systemPermission), ['name' => 'support.answer'])->assertForbidden();
        $this->delete(route('admin.access-control.permissions.destroy', $systemPermission))->assertForbidden();
    }

    public function test_super_admin_can_update_role_permissions_and_user_roles_with_checkbox_selections(): void
    {
        $superAdmin = $this->superAdmin();
        $supportRole = Role::findByName(UserRole::Support->value, 'web');
        $supportPermission = Permission::findByName('support.reply', 'web');

        $this->actingAs($superAdmin)->put(route('admin.access-control.role-permissions.update', $supportRole), [
            'permission_ids' => [$supportPermission->id],
        ])->assertRedirect(route('admin.access-control.role-permissions', ['role_id' => $supportRole->id]));
        $this->assertSame(['support.reply'], $supportRole->fresh()->permissions()->pluck('name')->all());

        $adminRole = Role::findByName(UserRole::Admin->value, 'web');
        $this->put(route('admin.access-control.role-permissions.update', $adminRole), [
            'permission_ids' => [(int) Permission::findByName('customer.view', 'web')->id],
        ])->assertRedirect();
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('access.manage'));
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('staff.manage'));

        $user = User::factory()->create(['name' => 'Access Managed User', 'status' => 'active']);
        $this->put(route('admin.access-control.user-roles.update', $user->public_id), [
            'role_ids' => [$supportRole->id],
        ])->assertRedirect();
        $this->assertSame([UserRole::Support->value], $user->fresh()->getRoleNames()->all());
        $this->get(route('admin.access-control.user-roles', ['search' => 'Access Managed User']))
            ->assertInertia(fn ($page) => $page->where('users.data.0.roles.0.name', UserRole::Support->value));

        $this->put(route('admin.access-control.user-roles.update', $superAdmin->public_id), [
            'role_ids' => [$adminRole->id],
        ])->assertSessionHasErrors('role_ids');
        $this->assertTrue($superAdmin->fresh()->hasRole(UserRole::SuperAdmin->value));
    }

    public function test_temporary_user_permissions_are_effective_only_until_their_expiry(): void
    {
        $superAdmin = $this->superAdmin();
        $user = User::factory()->create(['name' => 'Temporary Permission Target', 'status' => 'active']);
        $user->assignRole(UserRole::Customer->value);
        $permission = Permission::findByName('support.note', 'web');
        $expiresAt = now()->addHour();

        $this->actingAs($superAdmin)->put(route('admin.access-control.user-permissions.update', $user->public_id), [
            'grants' => [[
                'permission_id' => $permission->id,
                'expires_at' => $expiresAt->toDateTimeString(),
            ]],
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->can('support.note'));
        $this->assertDatabaseHas('temporary_user_permissions', [
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'granted_by' => $superAdmin->id,
        ]);
        $this->get(route('admin.access-control.user-permissions', ['search' => 'Temporary Permission Target']))
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.temporary_permission_grants.0.permission.name', 'support.note'));

        $this->travelTo($expiresAt->copy()->addMinute());
        $this->assertFalse($user->fresh()->can('support.note'));

        $this->delete(route('admin.access-control.user-permissions.destroy', $user->public_id))->assertRedirect();
        $this->assertDatabaseMissing('temporary_user_permissions', ['user_id' => $user->id]);
    }

    private function superAdmin(): User
    {
        $superAdmin = User::factory()->create(['status' => 'active']);
        $superAdmin->assignRole(UserRole::SuperAdmin->value);

        return $superAdmin;
    }
}
