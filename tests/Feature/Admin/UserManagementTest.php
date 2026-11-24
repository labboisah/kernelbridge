<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_super_admin_can_list_all_roles_and_filter_users(): void
    {
        $admin = $this->superAdmin();
        $customer = User::factory()->create(['name' => 'Portal Customer']);
        $customer->assignRole(UserRole::Customer->value);
        $partner = User::factory()->create(['name' => 'Partner Account']);
        $partner->assignRole([UserRole::Partner->value, UserRole::Agent->value]);

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Partner', 'role' => UserRole::Agent->value]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->where('users.data.0.public_id', $partner->public_id)
                ->where('users.data.0.roles.0.name', UserRole::Partner->value)
                ->where('currentUserPublicId', $admin->public_id)
                ->has('roles', count(UserRole::cases())));
    }

    public function test_non_super_admin_cannot_access_user_management_routes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertForbidden();
        $deletedUser = User::factory()->create();
        $deletedUser->delete();
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertForbidden();
        $this->post(route('admin.users.restore', $deletedUser->public_id))->assertForbidden();

        $suspendedSuperAdmin = $this->superAdmin();
        $suspendedSuperAdmin->update(['status' => 'suspended']);
        $this->actingAs($suspendedSuperAdmin)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_super_admin_can_update_profile_roles_and_status_and_send_reset_link(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $admin = $this->superAdmin();
        $user = User::factory()->create(['email' => 'old-address@example.test', 'email_verified_at' => now()]);
        $user->assignRole(UserRole::Customer->value);
        $user->createToken('user-management-test');
        DB::table('sessions')->insert([
            'id' => 'user-management-test-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $user->public_id), [
            'name' => 'Updated Account',
            'email' => 'new-address@example.test',
            'roles' => [UserRole::Support->value],
            'status' => 'suspended',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Updated Account', $user->name);
        $this->assertSame('new-address@example.test', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('suspended', $user->status);
        $this->assertSame([UserRole::Support->value], $user->getRoleNames()->all());
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-management-test-session']);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.account_updated', 'actor_id' => $admin->id]);

        $this->post(route('admin.users.password-reset', $user->public_id))->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_reset_link_sent', 'actor_id' => $admin->id]);
    }

    public function test_account_deletion_is_soft_and_preserves_auditable_history(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        $user->assignRole(UserRole::Customer->value);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $user->public_id))->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNull(User::query()->find($user->id));
        $this->assertNotNull(User::withTrashed()->findOrFail($user->id));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.account_deleted', 'actor_id' => $admin->id]);
    }

    public function test_super_admin_can_view_and_restore_deleted_accounts(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(UserRole::Customer->value);
        $user->delete();

        $this->actingAs($admin)->get(route('admin.users.deleted'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Deleted')
                ->where('users.data.0.public_id', $user->public_id)
                ->where('users.data.0.status', 'active'));

        $this->post(route('admin.users.restore', $user->public_id))->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
        $this->assertSame('active', User::query()->findOrFail($user->id)->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.account_restored', 'actor_id' => $admin->id]);
        $this->post(route('admin.users.restore', $user->public_id))->assertNotFound();

        $suspendedUser = User::factory()->create(['status' => 'suspended']);
        $suspendedUser->delete();
        $this->post(route('admin.users.restore', $suspendedUser->public_id))->assertRedirect();
        $this->assertSame('suspended', User::query()->findOrFail($suspendedUser->id)->status);
    }

    public function test_super_admin_cannot_suspend_demote_or_delete_their_own_account(): void
    {
        $admin = $this->superAdmin();
        $payload = ['name' => $admin->name, 'email' => $admin->email, 'roles' => [UserRole::Admin->value], 'status' => 'suspended'];

        $this->actingAs($admin)->put(route('admin.users.update', $admin->public_id), $payload)->assertSessionHasErrors('roles');
        $this->delete(route('admin.users.destroy', $admin->public_id))->assertSessionHasErrors('account');
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertTrue($admin->fresh()->hasRole(UserRole::SuperAdmin->value));
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(UserRole::SuperAdmin->value);

        return $admin;
    }
}
