<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

final class QuotationPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('quotation.view');
    }

    public function view(User $u, Quotation $q): bool
    {
        return $u->can('quotation.view');
    }

    public function create(User $u): bool
    {
        return $u->can('quotation.create');
    }

    public function update(User $u, Quotation $q): bool
    {
        return $u->can('quotation.update') && $q->status->value === 'draft';
    }

    public function delete(User $u, Quotation $q): bool
    {
        return $u->can('quotation.delete') && $q->status->value === 'draft';
    }

    public function send(User $u, Quotation $q): bool
    {
        return $u->can('quotation.send') && $q->status->value === 'draft';
    }

    public function convert(User $u, Quotation $q): bool
    {
        return $u->can('quotation.convert') && $q->status->value === 'accepted';
    }
}
