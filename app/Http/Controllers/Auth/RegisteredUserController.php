<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\EmailLoginChallengeService;
use App\Domain\Identity\Services\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegisteredUserRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(StoreRegisteredUserRequest $request, RegisterCustomer $registerCustomer, EmailLoginChallengeService $challenges): RedirectResponse
    {
        $user = $registerCustomer->execute($request->validated());

        event(new Registered($user));

        $challenges->begin($user, $request);
        $request->session()->forget('partner_registration');

        return redirect()->route('login.two-factor');
    }
}
