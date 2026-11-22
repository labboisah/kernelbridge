<?php

namespace App\Domain\Partners;

use App\Models\Customer;
use App\Models\PartnerClientInvitation;
use App\Notifications\PartnerClientRegistered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ClientInvitationService
{
    public function send(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if (! $customer->email) {
                throw ValidationException::withMessages(['email' => 'Add the client email before sending an invitation.']);
            }
            if ($customer->users()->whereRaw('LOWER(users.email) = ?', [mb_strtolower($customer->email)])->exists()) {
                throw ValidationException::withMessages(['email' => 'This client email already has access to the customer account.']);
            }
            $token = Str::random(64);
            PartnerClientInvitation::updateOrCreate(['customer_id' => $customer->id], [
                'email' => mb_strtolower($customer->email),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ]);
            Notification::route('mail', $customer->email)->notify(new PartnerClientRegistered(
                $customer->name, route('client-invitations.show', $token), false,
            ));
        });
    }
}
