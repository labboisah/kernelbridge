<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccessControlController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('access.manage'), 403);
        $permissions = Permission::query()->where('guard_name', 'web')->orderBy('name')->get();
        $roles = Role::query()->whereIn('name', UserRole::administrative())->with('permissions:id,name')->orderBy('name')->get();

        return Inertia::render('Admin/AccessControl/Index', [
            'roles' => $roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')->values()]),
            'permissionGroups' => $permissions->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->headline()->toString())->map(fn ($items, string $label): array => ['label' => $label, 'permissions' => $items->pluck('name')->values()])->values(),
            'canManageSuperAdmin' => $request->user()->hasRole(UserRole::SuperAdmin->value),
        ]);
    }

    public function update(Request $request, Role $role, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->can('access.manage'), 403);
        abort_unless(in_array($role->name, UserRole::administrative(), true), 404);
        abort_if($role->name === UserRole::SuperAdmin->value && ! $request->user()->hasRole(UserRole::SuperAdmin->value), 403);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')]]);
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $permissions = collect($data['permissions'])->unique();
        if (in_array($role->name, [UserRole::SuperAdmin->value, UserRole::Admin->value], true)) {
            $permissions->push('access.manage', 'staff.manage');
        }
        $role->syncPermissions($permissions->unique()->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = $role->permissions()->pluck('name')->sort()->values()->all();
        $audit->record('access.role_permissions_updated', $request->user(), ['role' => $role->name], $role, ['permissions' => $before], ['permissions' => $after]);

        return back()->with('success', str($role->name)->headline().' permissions updated.');
    }
}
