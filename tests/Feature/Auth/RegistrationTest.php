<?php

namespace Tests\Feature\Auth;

use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
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

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('customers', ['email' => 'test@example.com', 'phone' => '+2348000000000', 'type' => 'individual', 'status' => 'prospect']);
        $this->assertDatabaseHas('customer_user', ['is_primary' => true]);
    }

    public function test_organization_can_register_a_customer_account(): void
    {
        $this->post('/register', [
            'name' => 'Ada Admin', 'account_type' => 'organization', 'organization' => 'ABC Academy',
            'email' => 'ada@academy.test', 'phone' => '+2348111111111',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('customers', ['name' => 'ABC Academy', 'email' => 'ada@academy.test', 'type' => 'organization']);
    }
}
