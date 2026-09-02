<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

final class InvoicePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('invoice.view');
    }

    public function view(User $u, Invoice $i): bool
    {
        return $u->can('invoice.view');
    }

    public function create(User $u): bool
    {
        return $u->can('invoice.create');
    }

    public function update(User $u, Invoice $i): bool
    {
        return $u->can('invoice.update') && $i->status->value === 'draft';
    }

    public function issue(User $u, Invoice $i): bool
    {
        return $u->can('invoice.issue') && $i->status->value === 'draft';
    }

    public function recordPayment(User $u, Invoice $i): bool
    {
        return $u->can('invoice.payment') && ! in_array($i->status->value, ['draft', 'cancelled', 'refunded', 'paid'], true);
    }

    public function cancel(User $u, Invoice $i): bool
    {
        return $u->can('invoice.cancel') && ! in_array($i->status->value, ['paid', 'refunded', 'cancelled'], true);
    }
}
