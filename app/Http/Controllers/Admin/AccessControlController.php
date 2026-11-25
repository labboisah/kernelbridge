<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\SystemPermissionCatalog;
use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TemporaryUserPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccessControlController extends Controller
{
    public function rolesIndex(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $roles = Role::query()->where('guard_name', 'web')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $this->isSystemRole($role),
            ]);

        return Inertia::render('Admin/AccessControl/Roles', [
            'roles' => $roles,
            'filters' => $filters,
        ]);
    }

    public function storeRole(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/', Rule::unique('roles', 'name')->where('guard_name', 'web')],
        ]);

        $role = DB::transaction(function () use ($data, $actor, $audit): Role {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $audit->record('access.role_created', $actor, [], $role, after: ['name' => $role->name]);

            return $role;
        });
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.roles')->with('success', 'Role created.');
    }

    public function updateRole(Request $request, Role $role, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $this->assertWebRole($role);
        abort_if($this->isSystemRole($role), 403, 'System roles cannot be renamed.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->getKey())],
        ]);
        $before = ['name' => $role->name];
        $role->name = $data['name'];
        $role->save();
        $audit->record('access.role_updated', $actor, [], $role, $before, ['name' => $role->name]);
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.roles')->with('success', 'Role updated.');
    }

    public function destroyRole(Request $request, Role $role, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $this->assertWebRole($role);
        abort_if($this->isSystemRole($role), 403, 'System roles cannot be deleted.');
        $before = ['name' => $role->name];

        DB::transaction(function () use ($role, $actor, $audit, $before): void {
            $audit->record('access.role_deleted', $actor, [], $role, $before);
            $role->delete();
        });
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.roles')->with('success', 'Role deleted.');
    }

    public function permissionsIndex(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $permissions = Permission::query()->where('guard_name', 'web')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
                'is_system' => $this->isSystemPermission($permission),
            ]);

        return Inertia::render('Admin/AccessControl/Permissions', [
            'permissions' => $permissions,
            'filters' => $filters,
        ]);
    }

    public function storePermission(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9._-]+\z/', Rule::unique('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $permission = Permission::create(['name' => $data['name'], 'guard_name' => 'web']);
        $audit->record('access.permission_created', $actor, [], $permission, after: ['name' => $permission->name]);
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.permissions')->with('success', 'Permission created.');
    }

    public function updatePermission(Request $request, Permission $permission, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $this->assertWebPermission($permission);
        abort_if($this->isSystemPermission($permission), 403, 'System permissions cannot be renamed.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9._-]+\z/', Rule::unique('permissions', 'name')->where('guard_name', 'web')->ignore($permission->getKey())],
        ]);
        $before = ['name' => $permission->name];
        $permission->name = $data['name'];
        $permission->save();
        $audit->record('access.permission_updated', $actor, [], $permission, $before, ['name' => $permission->name]);
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.permissions')->with('success', 'Permission updated.');
    }

    public function destroyPermission(Request $request, Permission $permission, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $this->assertWebPermission($permission);
        abort_if($this->isSystemPermission($permission), 403, 'System permissions cannot be deleted.');
        $before = ['name' => $permission->name];

        DB::transaction(function () use ($permission, $actor, $audit, $before): void {
            $audit->record('access.permission_deleted', $actor, [], $permission, $before);
            $permission->delete();
        });
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.permissions')->with('success', 'Permission deleted.');
    }

    public function rolePermissions(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate([
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);
        $roles = Role::query()->where('guard_name', 'web')->with('permissions:id,name')->orderBy('name')->get();
        $selectedRole = $roles->firstWhere('id', (int) ($filters['role_id'] ?? 0)) ?? $roles->first();

        return Inertia::render('Admin/AccessControl/RolePermissions', [
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $this->isSystemRole($role),
                'permission_ids' => $role->permissions->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            ])->values(),
            'selectedRoleId' => $selectedRole?->id,
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function updateRolePermissions(Request $request, Role $role, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $this->assertWebRole($role);
        $data = $request->validate([
            'permission_ids' => ['present', 'array', 'max:500'],
            'permission_ids.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
        ]);
        $before = $role->permissions()->orderBy('name')->pluck('name')->all();
        $permissionIds = collect($data['permission_ids'])->map(fn ($id): int => (int) $id);
        if (in_array($role->name, [UserRole::SuperAdmin->value, UserRole::Admin->value], true)) {
            $requiredPermissions = $role->name === UserRole::SuperAdmin->value
                ? ['access.manage', 'staff.manage']
                : ['staff.manage'];
            $permissionIds = $permissionIds->merge(Permission::query()->where('guard_name', 'web')->whereIn('name', $requiredPermissions)->pluck('id'));
        }
        $permissions = Permission::query()->where('guard_name', 'web')->whereIn('id', $permissionIds->unique())->get();

        DB::transaction(function () use ($role, $permissions, $actor, $audit, $before): void {
            $role->syncPermissions($permissions);
            $after = $role->permissions()->orderBy('name')->pluck('name')->all();
            $audit->record('access.role_permissions_updated', $actor, ['role' => $role->name], $role, ['permissions' => $before], ['permissions' => $after]);
        });
        $this->forgetPermissionCache();

        return redirect()->route('admin.access-control.role-permissions', ['role_id' => $role->getKey()])->with('success', str($role->name)->headline().' permissions updated.');
    }

    public function userRoles(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $users = User::query()->with('roles:id,name')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'public_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->values(),
            ]);
        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get();
        $roleGroups = collect([
            'Administrative' => $roles->filter(fn (Role $role): bool => in_array($role->name, UserRole::administrative(), true)),
            'Portal' => $roles->filter(fn (Role $role): bool => in_array($role->name, UserRole::names(), true) && ! in_array($role->name, UserRole::administrative(), true)),
            'Custom' => $roles->filter(fn (Role $role): bool => ! in_array($role->name, UserRole::names(), true)),
        ])->filter(fn (Collection $items): bool => $items->isNotEmpty())
            ->map(fn (Collection $items, string $label): array => [
                'label' => $label,
                'roles' => $items->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->values(),
            ])->values();

        return Inertia::render('Admin/AccessControl/UserRoles', [
            'users' => $users,
            'filters' => $filters,
            'roleGroups' => $roleGroups,
            'currentUserPublicId' => $request->user()->public_id,
        ]);
    }

    public function updateUserRoles(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);
        $roles = Role::query()->where('guard_name', 'web')->whereIn('id', $data['role_ids'])->orderBy('name')->get();
        $newRoleNames = $roles->pluck('name');
        $keepsSuperAdmin = $newRoleNames->contains(UserRole::SuperAdmin->value);

        if ($actor->is($user) && ! $keepsSuperAdmin) {
            throw ValidationException::withMessages(['role_ids' => 'You cannot remove your own super administrator role.']);
        }
        if ($user->status === 'active'
            && $user->hasRole(UserRole::SuperAdmin->value)
            && ! $keepsSuperAdmin
            && User::role(UserRole::SuperAdmin->value)->where('status', 'active')->count() <= 1) {
            throw ValidationException::withMessages(['role_ids' => 'The last active super administrator cannot be demoted.']);
        }

        $before = $user->getRoleNames()->sort()->values()->all();
        DB::transaction(function () use ($user, $roles, $actor, $audit, $before, $newRoleNames): void {
            $user->syncRoles($roles);
            $audit->record('access.user_roles_updated', $actor, [], $user, ['roles' => $before], ['roles' => $newRoleNames->sort()->values()->all()]);
        });

        return back()->with('success', $user->name.' roles updated.');
    }

    public function userPermissions(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $users = User::query()->with([
            'roles:id,name',
            'temporaryPermissionGrants.permission:id,name',
        ])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')->paginate(25)->withQueryString();

        return Inertia::render('Admin/AccessControl/UserPermissions', [
            'users' => $users,
            'filters' => $filters,
            'permissionGroups' => $this->permissionGroups(),
            'currentUserPublicId' => $request->user()->public_id,
        ]);
    }

    public function updateUserPermissions(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'grants' => ['present', 'array', 'max:500'],
            'grants.*.permission_id' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
            'grants.*.expires_at' => ['required', 'date', 'after:now'],
        ]);
        $grantsByPermission = collect($data['grants'])->keyBy(fn (array $grant): int => (int) $grant['permission_id']);
        $permissionIds = $grantsByPermission->keys()->map(fn ($id): int => (int) $id)->values();
        $permissions = Permission::query()->where('guard_name', 'web')->whereIn('id', $permissionIds)->get();
        $before = $user->temporaryPermissionGrants()->with('permission')->orderBy('permission_id')->get()
            ->map(fn (TemporaryUserPermission $grant): array => ['permission' => $grant->permission->name, 'expires_at' => $grant->expires_at?->toISOString()])
            ->values()->all();

        DB::transaction(function () use ($user, $actor, $audit, $permissions, $grantsByPermission, $permissionIds, $before): void {
            foreach ($permissions as $permission) {
                TemporaryUserPermission::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'permission_id' => $permission->getKey()],
                    [
                        'granted_by' => $actor->getKey(),
                        'expires_at' => $grantsByPermission->get($permission->getKey())['expires_at'],
                    ],
                );
            }
            $grants = $user->temporaryPermissionGrants();
            if ($permissionIds->isNotEmpty()) {
                $grants->whereNotIn('permission_id', $permissionIds);
            }
            $grants->delete();
            $user->unsetRelation('temporaryPermissions');

            $after = $user->temporaryPermissionGrants()->with('permission')->orderBy('permission_id')->get()
                ->map(fn (TemporaryUserPermission $grant): array => ['permission' => $grant->permission->name, 'expires_at' => $grant->expires_at?->toISOString()])
                ->values()->all();
            $audit->record('access.user_temporary_permissions_updated', $actor, [], $user, ['grants' => $before], ['grants' => $after]);
        });

        return back()->with('success', $user->name.' temporary permissions updated.');
    }

    public function revokeUserPermissions(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $before = $user->temporaryPermissionGrants()->with('permission')->get()
            ->map(fn (TemporaryUserPermission $grant): array => ['permission' => $grant->permission->name, 'expires_at' => $grant->expires_at?->toISOString()])
            ->values()->all();

        DB::transaction(function () use ($user, $actor, $audit, $before): void {
            $user->temporaryPermissionGrants()->delete();
            $user->unsetRelation('temporaryPermissions');
            $audit->record('access.user_temporary_permissions_revoked', $actor, [], $user, ['grants' => $before], ['grants' => []]);
        });

        return back()->with('success', $user->name.' temporary permissions revoked.');
    }

    private function permissionGroups(): Collection
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->get()
            ->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->headline()->toString())
            ->map(fn (Collection $permissions, string $label): array => [
                'label' => $label,
                'permissions' => $permissions->map(fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'is_system' => $this->isSystemPermission($permission),
                ])->values(),
            ])->values();
    }

    private function authorizeSuperAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->status === 'active' && $actor->hasRole(UserRole::SuperAdmin->value), 403);

        return $actor;
    }

    private function assertWebRole(Role $role): void
    {
        abort_unless($role->guard_name === 'web', 404);
    }

    private function assertWebPermission(Permission $permission): void
    {
        abort_unless($permission->guard_name === 'web', 404);
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, UserRole::names(), true);
    }

    private function isSystemPermission(Permission $permission): bool
    {
        return in_array($permission->name, SystemPermissionCatalog::names(), true);
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
