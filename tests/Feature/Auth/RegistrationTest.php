<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Notification::fake();
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'account_type' => 'individual',
            'phone' => '+2348000000000',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login.two-factor'));
        $this->verifyLoginCode(User::where('email', 'test@example.com')->firstOrFail())
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('customers', ['email' => 'test@example.com', 'phone' => '+2348000000000', 'type' => 'individual', 'status' => 'prospect']);
        $this->assertDatabaseHas('customer_user', ['is_primary' => true]);
    }

    public function test_registration_sends_a_verification_email_and_blocks_unverified_access(): void
    {
        $this->post('/register', [
            'name' => 'Verify Me', 'account_type' => 'individual', 'phone' => '+2348000000001',
            'email' => 'verify@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'verify@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->verifyLoginCode($user)->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->get('/account/services')->assertRedirect(route('verification.notice'));
    }

    public function test_organization_can_register_a_customer_account(): void
    {
        $this->post('/register', [
            'name' => 'Ada Admin', 'account_type' => 'organization', 'organization' => 'ABC Academy',
            'email' => 'ada@academy.test', 'phone' => '+2348111111111',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect(route('login.two-factor'));
        $this->verifyLoginCode(User::where('email', 'ada@academy.test')->firstOrFail())
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('customers', ['name' => 'ABC Academy', 'email' => 'ada@academy.test', 'type' => 'organization']);
    }
}
