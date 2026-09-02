<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;

final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payment.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payment.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payment.create');
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $user->can('payment.verify') && $payment->status === PaymentStatus::Pending;
    }

    public function reject(User $user, Payment $payment): bool
    {
        return $user->can('payment.reject') && $payment->status === PaymentStatus::Pending;
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('payment.refund') && $payment->status === PaymentStatus::Verified;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->can('payment.delete') && $payment->status === PaymentStatus::Pending;
    }
}
