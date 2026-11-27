<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Identity\Services\EmailLoginChallengeService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class RegistrationController extends Controller
{
    public function store(Request $request, string $type, EmailLoginChallengeService $challenges)
    {
        abort_unless(array_key_exists($type, config('partners')), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        $user = DB::transaction(function () use ($data, $type) {
            $user = User::create($data);
            $user->assignRole(['partner', UserRole::forPartnerType($type)]);
            (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => $type, 'status' => 'draft'])->save();

            return $user;
        });
        event(new Registered($user));

        $challenges->begin($user, $request, destination: route('partners.onboarding', $type, absolute: false));
        $request->session()->forget(['url.intended', 'partner_registration']);

        return redirect()->route('login.two-factor');
    }
}
