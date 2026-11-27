<?php

namespace Tests;

use App\Models\User;
use App\Notifications\LoginVerificationCode;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;

abstract class TestCase extends BaseTestCase
{
    protected function latestLoginCodeFor(User $user): string
    {
        $notification = Notification::sent($user, LoginVerificationCode::class)->last();

        $this->assertInstanceOf(LoginVerificationCode::class, $notification);

        return $notification->code;
    }

    protected function verifyLoginCode(User $user)
    {
        return $this->post(route('login.two-factor.verify'), [
            'code' => $this->latestLoginCodeFor($user),
        ]);
    }
}
