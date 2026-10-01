<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegisteredUserRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
    public function store(StoreRegisteredUserRequest $request, RegisterCustomer $registerCustomer): RedirectResponse
    {
        $user = $registerCustomer->execute($request->validated());

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('partner_registration');

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
