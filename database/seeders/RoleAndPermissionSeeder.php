<?php

namespace Database\Seeders;

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

        $productPermissions = ['product.view', 'product.create', 'product.update', 'product.delete'];
        $planPermissions = ['plan.view', 'plan.create', 'plan.update', 'plan.delete'];
        $customerPermissions = ['customer.view', 'customer.create', 'customer.update', 'customer.delete', 'customer.note'];
        $leadPermissions = ['lead.view', 'lead.update', 'lead.note', 'lead.convert', 'demo.manage'];
        $quotationPermissions = ['quotation.view', 'quotation.create', 'quotation.update', 'quotation.delete', 'quotation.send', 'quotation.convert'];
        $invoicePermissions = ['invoice.view', 'invoice.create', 'invoice.update', 'invoice.issue', 'invoice.payment', 'invoice.cancel'];
        $paymentPermissions = ['payment.view', 'payment.create', 'payment.verify', 'payment.reject', 'payment.refund', 'payment.delete'];
        $demoModePermissions = ['demo.analytics', 'demo.configure'];
        $supportPermissions = ['support.view', 'support.create', 'support.reply', 'support.note', 'support.manage'];
        $staffPermissions = ['staff.manage'];
        $accessPermissions = ['access.manage'];
        foreach ([...$productPermissions, ...$planPermissions, ...$customerPermissions, ...$leadPermissions, ...$quotationPermissions, ...$invoicePermissions, ...$paymentPermissions, ...$demoModePermissions, ...$supportPermissions, ...$staffPermissions, ...$accessPermissions] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($productPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($productPermissions);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($planPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($planPermissions);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($customerPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($customerPermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo(['customer.view', 'customer.create', 'customer.update', 'customer.note']);
        Role::findByName(UserRole::Finance->value, 'web')->givePermissionTo('customer.view');
        Role::findByName(UserRole::Support->value, 'web')->givePermissionTo(['customer.view', 'customer.note']);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($leadPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($leadPermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo($leadPermissions);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($quotationPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($quotationPermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo(['quotation.view', 'quotation.create', 'quotation.update', 'quotation.send']);
        Role::findByName(UserRole::Finance->value, 'web')->givePermissionTo(['quotation.view', 'quotation.convert']);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($invoicePermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($invoicePermissions);
        Role::findByName(UserRole::Finance->value, 'web')->givePermissionTo($invoicePermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo(['invoice.view', 'invoice.create']);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($paymentPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($paymentPermissions);
        Role::findByName(UserRole::Finance->value, 'web')->givePermissionTo($paymentPermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo(['payment.view', 'payment.create']);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($demoModePermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($demoModePermissions);
        Role::findByName(UserRole::Sales->value, 'web')->givePermissionTo('demo.analytics');
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($supportPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($supportPermissions);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($staffPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($staffPermissions);
        Role::findByName(UserRole::SuperAdmin->value, 'web')->givePermissionTo($accessPermissions);
        Role::findByName(UserRole::Admin->value, 'web')->givePermissionTo($accessPermissions);
        Role::findByName(UserRole::Support->value, 'web')->givePermissionTo($supportPermissions);
    }
}
