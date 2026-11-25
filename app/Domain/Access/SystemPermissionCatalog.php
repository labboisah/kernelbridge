<?php

namespace App\Domain\Access;

use App\Enums\UserRole;

final class SystemPermissionCatalog
{
    /** @return array<string, list<string>> */
    public static function groups(): array
    {
        return [
            'product' => ['product.view', 'product.create', 'product.update', 'product.delete'],
            'plan' => ['plan.view', 'plan.create', 'plan.update', 'plan.delete'],
            'customer' => ['customer.view', 'customer.create', 'customer.update', 'customer.delete', 'customer.note'],
            'lead' => ['lead.view', 'lead.update', 'lead.note', 'lead.convert', 'demo.manage'],
            'quotation' => ['quotation.view', 'quotation.create', 'quotation.update', 'quotation.delete', 'quotation.send', 'quotation.convert'],
            'invoice' => ['invoice.view', 'invoice.create', 'invoice.update', 'invoice.issue', 'invoice.payment', 'invoice.cancel'],
            'payment' => ['payment.view', 'payment.create', 'payment.verify', 'payment.reject', 'payment.refund', 'payment.delete'],
            'demo' => ['demo.analytics', 'demo.configure'],
            'support' => ['support.view', 'support.create', 'support.reply', 'support.note', 'support.manage', 'support.update', 'support.resolve'],
            'staff' => ['staff.dashboard', 'staff.manage'],
            'access' => ['access.manage', 'users.manage'],
            'admin' => ['admin.dashboard', 'admin.profile.manage'],
            'profile' => ['profile.manage'],
            'notification' => ['notification.inbox', 'notification.manage'],
            'partner' => [
                'partner.dashboard',
                'partner.application',
                'partner.manage',
                'partner.commission.manage',
                'partner.pcr.view',
                'partner.pcr.create',
                'partner.pcr.update',
                'partner.crm.view',
                'partner.crm.update',
                'partner.quotation.view',
                'partner.quotation.create',
                'partner.client.view',
                'partner.client.create',
                'partner.client.update',
                'partner.client.invite',
                'partner.subscription.view',
                'partner.subscription.create',
                'partner.license.view',
                'partner.invoice.view',
                'partner.invoice.create',
                'partner.invoice.pay',
                'partner.payment.view',
                'partner.payment.create',
                'partner.commission.view',
                'partner.network-commission.view',
                'partner.effort-points.view',
                'partner.revenue.view',
                'partner.bank.manage',
                'partner.organization.manage',
            ],
            'billing' => ['billing.manage', 'hosting.manage'],
            'integration' => ['mcp.manage', 'license.manage'],
            'portal' => [
                'portal.dashboard',
                'portal.checkout.view',
                'portal.checkout.create',
                'portal.services.view',
                'portal.services.create',
                'portal.services.update',
                'portal.services.suspend',
                'portal.services.delete',
                'portal.subscriptions.view',
                'portal.organization.view',
                'portal.licenses.view',
                'portal.licenses.manage',
                'portal.invoices.view',
                'portal.invoices.create',
                'portal.invoices.pay',
                'portal.invoices.credit',
                'portal.payments.view',
                'portal.payments.create',
            ],
            'portal.support' => [
                'portal.support.view',
                'portal.support.create',
                'portal.support.reply',
                'portal.support.update',
                'portal.support.resolve',
            ],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_values(array_merge(...array_values(self::groups())));
    }

    /** @return array<string, list<string>> */
    public static function rolePermissions(): array
    {
        $groups = self::groups();
        $all = self::names();
        $portal = [
            ...$groups['portal'],
            ...$groups['portal.support'],
            'profile.manage',
            'notification.inbox',
            'partner.application',
            'partner.bank.manage',
        ];
        $staffSupport = [
            'support.view',
            'support.create',
            'support.reply',
            'support.update',
            'support.resolve',
        ];
        $allAdmin = [
            ...$groups['product'],
            ...$groups['plan'],
            ...$groups['customer'],
            ...$groups['lead'],
            ...$groups['quotation'],
            ...$groups['invoice'],
            ...$groups['payment'],
            ...$groups['demo'],
            ...$groups['support'],
            'staff.manage',
            'admin.dashboard',
            'admin.profile.manage',
            'profile.manage',
            'notification.inbox',
            'notification.manage',
            'partner.application',
            'partner.bank.manage',
            'partner.manage',
            'partner.commission.manage',
            'billing.manage',
            'hosting.manage',
            'mcp.manage',
            'license.manage',
        ];

        $rolePermissions = [
            UserRole::SuperAdmin->value => $all,
            UserRole::Admin->value => $allAdmin,
            UserRole::Sales->value => [
                ...$portal,
                ...$staffSupport,
                ...$groups['lead'],
                'customer.view',
                'customer.create',
                'customer.update',
                'customer.note',
                'quotation.view',
                'quotation.create',
                'quotation.update',
                'quotation.send',
                'invoice.view',
                'invoice.create',
                'payment.view',
                'payment.create',
                'staff.dashboard',
                'demo.analytics',
            ],
            UserRole::Finance->value => [
                ...$portal,
                ...$staffSupport,
                'customer.view',
                'quotation.view',
                'quotation.convert',
                ...$groups['invoice'],
                ...$groups['payment'],
                'staff.dashboard',
            ],
            UserRole::Support->value => [
                ...$portal,
                'customer.view',
                'customer.note',
                ...$groups['support'],
                'staff.dashboard',
            ],
            UserRole::Customer->value => [...$portal],
            UserRole::Partner->value => [...$portal, 'partner.dashboard'],
            UserRole::Agent->value => [
                ...$portal,
                ...array_values(array_diff($groups['partner'], ['partner.manage', 'partner.commission.manage'])),
            ],
            UserRole::Investor->value => [...$portal, 'partner.dashboard'],
            UserRole::Developer->value => [...$portal, 'partner.dashboard'],
        ];

        return array_map(fn (array $permissions): array => array_values(array_unique($permissions)), $rolePermissions);
    }
}
