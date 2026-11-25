<?php

namespace Database\Seeders;

use App\Domain\Access\SystemPermissionCatalog;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemPermissionCatalog::names() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');
            $model->syncPermissions(SystemPermissionCatalog::rolePermissions()[$role->value]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
