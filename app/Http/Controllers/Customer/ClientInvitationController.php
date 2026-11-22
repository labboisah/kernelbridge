<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PartnerClientInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ClientInvitationController extends Controller
{
    private function invitation(string $token, bool $lock = false): PartnerClientInvitation
    {
        abort_unless(strlen($token) === 64, 404);
        $query = PartnerClientInvitation::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->where('expires_at', '>', now());
        $invitation = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $invitation->load('customer');
        abort_unless($invitation->customer->email
            && mb_strtolower($invitation->customer->email) === $invitation->email, 404);

        return $invitation;
    }

    public function show(Request $request, string $token)
    {
        $invitation = $this->invitation($token);
        $user = $request->user();
        $matchingUser = $user && mb_strtolower($user->email) === $invitation->email;
        if ((! $user && User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists())
            || ($matchingUser && ! $user->hasVerifiedEmail())) {
            $request->session()->put('url.intended', route('client-invitations.show', $token));
        }

        return Inertia::render('Auth/ClientInvitation', [
            'token' => $token,
            'customerName' => $invitation->customer->name,
            'email' => $invitation->email,
            'signedIn' => (bool) $user,
            'matchingUser' => (bool) $matchingUser,
            'verified' => (bool) ($user?->hasVerifiedEmail()),
            'existingAccount' => User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists(),
        ])->toResponse($request)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token, AuditRecorder $audit)
    {
        $invitation = $this->invitation($token);
        $user = $request->user();
        if ($user) {
            abort_unless(mb_strtolower($user->email) === $invitation->email && $user->hasVerifiedEmail(), 403);
            $data = [];
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
        }

        $user = DB::transaction(function () use ($invitation, $token, $data, $user, $audit): User {
            Customer::query()->lockForUpdate()->findOrFail($invitation->customer_id);
            $invitation = $this->invitation($token, true);
            if ($user) {
                abort_unless(mb_strtolower($user->email) === $invitation->email && $user->hasVerifiedEmail(), 403);
            } else {
                if (User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'An account already exists for this email. Sign in to accept the invitation.']);
                }
                $user = User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password']]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole(UserRole::Customer->value);
            }
            $invitation->customer->users()->syncWithoutDetaching([$user->id => ['is_primary' => ! $invitation->customer->users()->exists()]]);
            $invitation->update(['accepted_at' => now()]);
            $audit->record('partner.client_invitation_accepted', $user, ['customer_uuid' => $invitation->customer->uuid]);

            return $user;
        });

        if (! $request->user()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('portal.services.index')->with('success', 'Your customer account is linked.');
    }
}
