<?php

namespace Tests\Feature;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_security_headers(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_login_route_is_protected_by_csrf_middleware(): void
    {
        $middleware = app('router')->getRoutes()->getByName('login')->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertTrue(app()->make(VerifyCsrfToken::class) instanceof VerifyCsrfToken);
    }

    public function test_authentication_and_entity_changes_have_structured_audits(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'authentication.login', 'actor_id' => $user->id, 'entity_type' => $user->getMorphClass(), 'entity_id' => (string) $user->id]);

        $this->actingAs($user);
        $customer = Customer::query()->create(['uuid' => (string) Str::uuid(), 'type' => 'organization', 'name' => 'Secure Ltd', 'status' => 'active']);
        $customer->update(['name' => 'Secure Technologies Ltd']);
        $audit = DB::table('audit_logs')->where('entity_type', $customer->getMorphClass())->where('entity_id', $customer->uuid)->where('action', 'customer.updated')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Secure Ltd', json_decode($audit->before, true)['name']);
        $this->assertSame('Secure Technologies Ltd', json_decode($audit->after, true)['name']);
        $this->assertNotNull($audit->ip_address);
    }

    public function test_audit_payloads_redact_secrets(): void
    {
        app(AuditRecorder::class)->record('security.secret_test', metadata: ['token' => 'plaintext-token', 'nested' => ['password' => 'plaintext-password']]);
        $metadata = json_decode(DB::table('audit_logs')->where('event', 'security.secret_test')->value('metadata'), true);
        $this->assertSame('[REDACTED]', $metadata['token']);
        $this->assertSame('[REDACTED]', $metadata['nested']['password']);
    }

    public function test_invoices_cannot_be_hard_deleted(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['type' => 'organization', 'name' => 'Finance Customer', 'status' => 'active']);
        $invoice = Invoice::query()->create(['customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => today()->addMonth(), 'subtotal' => 100, 'discount' => 0, 'tax' => 0, 'total' => 100, 'amount_paid' => 0, 'balance' => 100, 'currency' => 'NGN', 'status' => 'draft', 'created_by' => $user->id]);

        $this->expectException(\LogicException::class);
        $invoice->delete();
    }
}
