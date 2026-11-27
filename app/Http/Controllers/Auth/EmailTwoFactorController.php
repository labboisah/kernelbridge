<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\EmailLoginChallengeService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailTwoFactorController extends Controller
{
    public function show(Request $request, EmailLoginChallengeService $challenges): Response|RedirectResponse
    {
        $challenge = $challenges->current($request);
        if (! $challenge) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again to request a verification code.']);
        }

        return Inertia::render('Auth/VerifyLoginCode', [
            'email' => $this->maskEmail($challenge->user->email),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function verify(Request $request, EmailLoginChallengeService $challenges): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $challenge = $challenges->current($request);
        if (! $challenge) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again to request a verification code.']);
        }

        $key = 'login-email-code:'.$challenge->uuid.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new verification code.']);
        }
        RateLimiter::hit($key, 300);

        $user = $challenges->verify($challenge, $validated['code']);
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or expired. You can request a new code.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->get('auth.email_otp.remember', false);
        $destination = $request->session()->pull('auth.email_otp.destination');

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('auth.email_otp.verified_at', now()->timestamp);
        $request->session()->forget(['auth.email_otp.challenge_id', 'auth.email_otp.remember']);

        $defaultDestination = $destination ?? route($this->dashboardRoute($user), absolute: false);

        return redirect()->intended($defaultDestination);
    }

    public function resend(Request $request, EmailLoginChallengeService $challenges): RedirectResponse
    {
        $challenge = $challenges->current($request);
        if (! $challenge) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again to request a verification code.']);
        }

        $key = 'login-email-code-send:'.$challenge->user_id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['code' => 'Too many code requests. Please try again later.']);
        }
        RateLimiter::hit($key, 900);

        $remember = (bool) $request->session()->get('auth.email_otp.remember', false);
        $destination = $request->session()->get('auth.email_otp.destination');
        $challenges->begin($challenge->user, $request, $remember, $destination);

        return redirect()->route('login.two-factor')->with('status', 'A new verification code has been sent to your email.');
    }

    private function dashboardRoute(User $user): string
    {
        return $user->can('admin.dashboard')
            ? 'admin.dashboard'
            : ($user->can('staff.dashboard')
                ? 'staff.dashboard'
                : ($user->isPartner() && $user->can('partner.dashboard') ? 'partner.dashboard' : 'dashboard'));
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);
        $visible = mb_substr($name, 0, 1);

        return $visible.str_repeat('*', max(3, mb_strlen($name) - 1)).'@'.$domain;
    }
}
