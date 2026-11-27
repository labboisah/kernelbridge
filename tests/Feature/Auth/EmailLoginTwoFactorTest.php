<?php

namespace Tests\Feature\Auth;

use App\Models\EmailLoginChallenge;
use App\Models\User;
use App\Notifications\LoginVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailLoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    public function test_login_requires_a_single_use_email_code_before_authentication(): void
    {
        $user = User::factory()->create(['email' => 'test@example.test']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('login.two-factor'));

        $this->assertGuest();
        $this->assertNotNull(session('auth.email_otp.challenge_id'));
        $this->get(route('login.two-factor'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/VerifyLoginCode')->where('email', 't***@example.test'));

        $code = $this->latestLoginCodeFor($user);
        $challenge = EmailLoginChallenge::query()->sole();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue($challenge->expires_at->isFuture());
        $this->assertTrue($challenge->expires_at->lte(now()->addMinutes(5)));

        $invalidCode = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->post(route('login.two-factor.verify'), ['code' => $invalidCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(1, $challenge->fresh()->attempts);

        $response = $this->post(route('login.two-factor.verify'), ['code' => $code]);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_expired_code_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        $challenge = EmailLoginChallenge::query()->sole();
        $challenge->update(['expires_at' => now()->subSecond()]);

        $this->post(route('login.two-factor.verify'), ['code' => $this->latestLoginCodeFor($user)])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_five_incorrect_codes_consume_the_challenge(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        $code = $this->latestLoginCodeFor($user);
        $invalidCode = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.two-factor.verify'), ['code' => $invalidCode])
                ->assertSessionHasErrors('code');
        }

        $challenge = EmailLoginChallenge::query()->sole();
        $this->assertSame(5, $challenge->attempts);
        $this->assertNotNull($challenge->consumed_at);
        $this->assertGuest();
    }

    public function test_authenticated_sessions_without_a_verified_code_are_challenged(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login.two-factor'));
        $this->verifyLoginCode($user);
        session()->forget('auth.email_otp.verified_at');

        $this->get(route('dashboard'))->assertRedirect(route('login.two-factor'));

        $this->assertGuest();
        Notification::assertSentTo($user, LoginVerificationCode::class);
    }

    public function test_resending_a_code_invalidates_the_previous_code(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $oldCode = $this->latestLoginCodeFor($user);

        $this->post(route('login.two-factor.resend'))->assertRedirect(route('login.two-factor'));
        $newCode = $this->latestLoginCodeFor($user);
        $this->assertNotSame($oldCode, $newCode);

        $this->post(route('login.two-factor.verify'), ['code' => $oldCode])->assertSessionHasErrors('code');
        $this->post(route('login.two-factor.verify'), ['code' => $newCode])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }
}
