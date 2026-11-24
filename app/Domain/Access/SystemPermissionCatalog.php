<?php

namespace App\Domain\Access;

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
            'support' => ['support.view', 'support.create', 'support.reply', 'support.note', 'support.manage'],
            'staff' => ['staff.manage'],
            'access' => ['access.manage'],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_values(array_merge(...array_values(self::groups())));
    }
}
