<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\EmailLoginChallengeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();
        $key = 'login-email-code-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Too many verification code requests. Please try again later.']);
        }
        RateLimiter::hit($key, 900);

        app(EmailLoginChallengeService::class)->begin($user, $request, $request->boolean('remember'));

        return redirect()->route('login.two-factor');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
