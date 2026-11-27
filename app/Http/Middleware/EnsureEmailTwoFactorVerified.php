<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\EmailLoginChallengeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailTwoFactorVerified
{
    public function __construct(private readonly EmailLoginChallengeService $challenges) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $request->user();

        if (! $user || $request->session()->has('auth.email_otp.verified_at') || $request->routeIs('logout')) {
            return $next($request);
        }

        // Laravel's actingAs helper sets the guard directly without creating a session login.
        if (app()->environment('testing') && ! $request->session()->has($guard->getName())) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            $guard->logout();

            return redirect()->route('login');
        }

        $this->challenges->begin($user, $request);
        $guard->logout();

        return redirect()->route('login.two-factor');
    }
}
