<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Partners\CommissionService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function agent(string $status = 'approved', string $type = 'sales_agent'): User
    {
        $user = User::factory()->create();
        $user->assignRole(['partner', \App\Enums\UserRole::forPartnerType($type)]);
        (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => $type, 'status' => $status])->save();

        return $user;
    }

    private function client(User $agent): Customer
    {
        $c = Customer::factory()->create();
        $c->forceFill(['partner_user_id' => $agent->id])->save();

        return $c;
    }

    private function invoice(Customer $customer): Invoice
    {
        return Invoice::factory()->create(['customer_id' => $customer->id, 'total' => '10000.00', 'subtotal' => '10000.00', 'balance' => '10000.00']);
    }

    public function test_customers_are_not_partner_types_and_partner_login_uses_own_dashboard(): void
    {
        $this->get('/partners/customer/register')->assertNotFound();
        $user = $this->agent('draft', 'developer');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('partner.dashboard'));
        $this->get('/partner')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Partner/Dashboard')->where('canManageClients', false));
        $this->get('/dashboard')->assertRedirect(route('partner.dashboard'));
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_pending_developer_investor_and_non_partner_cannot_manage_clients(): void
    {
        foreach ([['submitted', 'sales_agent'], ['approved', 'developer'], ['approved', 'investor']] as [$status,$type]) {
            $user = $this->agent($status, $type);
            foreach (['/partner/clients', '/partner/invoices', '/partner/payments', '/partner/revenue', '/partner/support'] as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
        $this->actingAs(User::factory()->create())->get('/partner')->assertForbidden();
    }

    public function test_client_owner_is_server_assigned_and_cannot_be_changed(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $this->actingAs($agent)->post('/partner/clients', ['name' => 'Agent Client', 'email' => 'client@example.test', 'type' => 'organization', 'partner_user_id' => $other->id, 'status' => 'active'])->assertRedirect();
        $client = Customer::firstOrFail();
        $this->assertSame($agent->id, $client->partner_user_id);
        $this->assertSame('prospect', $client->status->value);
        $this->put('/partner/clients/'.$client->uuid, ['name' => 'Updated Client', 'email' => 'client@example.test', 'partner_user_id' => $other->id])->assertRedirect();
        $this->assertSame($agent->id, $client->fresh()->partner_user_id);
        $this->assertFalse($agent->customers()->exists());
    }

    public function test_agent_cannot_read_or_mutate_another_agents_records(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $client = $this->client($other);
        $invoice = $this->invoice($client);
        $ticket = SupportTicket::factory()->create(['customer_id' => $client->id]);
        $this->actingAs($agent)->get('/partner/clients')->assertInertia(fn (Assert $p) => $p->has('customers.data', 0));
        $this->get('/partner/invoices/'.$invoice->uuid)->assertNotFound();
        $this->post('/partner/invoices/'.$invoice->uuid.'/pay')->assertNotFound();
        $this->post('/partner/invoices/'.$invoice->uuid.'/payments')->assertNotFound();
        $this->put('/partner/clients/'.$client->uuid, [])->assertNotFound();
        $this->get('/partner/support/'.$ticket->uuid)->assertNotFound();
        $this->post('/partner/support/'.$ticket->uuid.'/messages', ['message' => 'A valid reply'])->assertNotFound();
        $this->get('/admin/partner-commissions')->assertForbidden();
    }

    public function test_invoice_uses_catalogue_price_and_manual_payment_stays_pending(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $this->actingAs($agent)->post('/partner/invoices', ['customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid, 'price' => 1])->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('10750.00', $invoice->total);
        $this->get('/partner/invoices/'.$invoice->uuid)->assertOk();
        $this->post('/partner/invoices/'.$invoice->uuid.'/payments', ['amount' => 10000, 'payment_method' => 'cash', 'payment_date' => today()->toDateString(), 'transaction_reference' => 'AGENT-001', 'status' => 'verified'])->assertRedirect();
        $this->assertDatabaseHas('payments', ['transaction_reference' => 'AGENT-001', 'status' => 'pending', 'customer_id' => $client->id]);
        $this->assertSame('10750.00', $invoice->fresh()->balance);
        $this->assertDatabaseCount('partner_commissions', 0);
        foreach (['/partner/invoices', '/partner/payments', '/partner/revenue', '/partner/support'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_support_is_client_scoped_and_internal_messages_are_hidden(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $team = SupportTeam::where('slug', 'general')->firstOrFail();
        $this->actingAs($agent)->post('/partner/support', ['customer_uuid' => $client->uuid, 'team_uuid' => $team->uuid, 'subject' => 'Need help with setup', 'priority' => 'normal', 'message' => 'Please help configure the installation.'])->assertRedirect();
        $ticket = SupportTicket::firstOrFail();
        $ticket->messages()->create(['sender_id' => $agent->id, 'type' => 'internal_note', 'body' => 'Private staff note', 'is_customer_visible' => false]);
        $this->get('/partner/support/'.$ticket->uuid)->assertInertia(fn (Assert $p) => $p->has('ticket.messages', 1));
        $this->post('/partner/support/'.$ticket->uuid.'/messages', ['message' => 'Here is more information.', 'internal' => true])->assertRedirect();
        $this->assertDatabaseHas('support_messages', ['body' => 'Here is more information.', 'is_customer_visible' => true]);
    }

    public function test_admin_sets_rate_but_agents_cannot(): void
    {
        $product = Product::factory()->create();
        $agent = $this->agent();
        $this->actingAs($agent)->put('/admin/partner-commissions/'.$product->slug, ['partner_commission_rate' => 80])->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put('/admin/partner-commissions/'.$product->slug, ['partner_commission_rate' => 101])->assertSessionHasErrors('partner_commission_rate');
        $this->put('/admin/partner-commissions/'.$product->slug, ['partner_commission_rate' => 7.5, 'developer_commission_rate' => 0, 'vat_commission_rate' => 7.5, 'platform_commission_rate' => 85, 'staff_commission_rate' => 0])->assertRedirect();
        $this->assertEquals(7.5, $product->fresh()->partner_commission_rate);
    }

    public function test_verified_payment_earns_commission_once_and_refund_reverses_it(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $plan->product->forceFill(['partner_commission_rate' => 10, 'platform_commission_rate' => 82.5])->save();
        $this->actingAs($agent)->post('/partner/invoices', ['customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid])->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 10750, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'PAY-001', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 10750]], $agent);
        $this->assertDatabaseHas('partner_commissions', ['partner_user_id' => $agent->id, 'invoice_id' => $invoice->id, 'amount' => 1075, 'rate' => 10, 'status' => 'earned']);
        $plan->product->forceFill(['partner_commission_rate' => 20, 'platform_commission_rate' => 72.5])->save();
        app(CommissionService::class)->accrue($invoice->fresh());
        $this->assertDatabaseCount('partner_commissions', 1);
        $this->assertSame('1075.00', PartnerCommission::first()->amount);
        $manager->refund($payment->fresh(), $agent, 'Customer refund');
        $this->assertDatabaseHas('partner_commissions', ['invoice_id' => $invoice->id, 'status' => 'reversed']);
        $this->get('/partner/revenue')->assertInertia(fn (Assert $p) => $p->has('earnings', 0)->has('collections', 0));
    }

    public function test_partial_payment_does_not_earn_commission_and_other_agent_cannot_see_it(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $plan->product->forceFill(['partner_commission_rate' => 10, 'platform_commission_rate' => 82.5])->save();
        $this->actingAs($agent)->post('/partner/invoices', ['customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid]);
        $invoice = Invoice::firstOrFail();
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 5000, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'HALF', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 5000]], $agent);
        $this->assertDatabaseCount('partner_commissions', 0);
        $this->actingAs($this->agent())->get('/partner/revenue')->assertInertia(fn (Assert $p) => $p->has('commissions.data', 0)->has('earnings', 0));
    }

    public function test_renewal_earns_new_commission_after_successful_payment(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $plan->product->forceFill(['partner_commission_rate' => 12.5, 'platform_commission_rate' => 80])->save();
        $subscription = Subscription::factory()->create(['customer_id' => $client->id, 'plan_id' => $plan->id, 'ends_at' => now()->subDay()]);
        $invoice = $this->invoice($client);
        $invoice->items()->create(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => 'Monthly renewal', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0, 'tax' => 0, 'total' => 10000]);
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 10000, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'RENEWAL', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 10000]], $agent);
        $this->assertDatabaseCount('partner_commissions', 0);
        app(SubscriptionService::class)->renew($subscription, $invoice->fresh(), $agent);
        $this->assertDatabaseHas('partner_commissions', ['invoice_id' => $invoice->id, 'amount' => 1250, 'rate' => 12.5]);
    }

    public function test_vat_included_but_hosting_and_credit_funded_amounts_are_excluded(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $plan->product->forceFill(['partner_commission_rate' => 10, 'platform_commission_rate' => 82.5])->save();
        $invoice = $this->invoice($client);
        $invoice->update(['total' => 12000, 'amount_paid' => 6000, 'balance' => 6000, 'status' => 'partially_paid']);
        $invoice->items()->create(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => 'Subscription', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0, 'tax' => 1000, 'total' => 11000]);
        $invoice->items()->create(['product_id' => $plan->product_id, 'plan_id' => null, 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000]);
        Subscription::factory()->create(['customer_id' => $client->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id]);
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 6000, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'AFTER-CREDIT', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 6000]], $agent);
        $this->assertDatabaseHas('partner_commissions', ['invoice_id' => $invoice->id, 'base_amount' => 5500, 'amount' => 550]);
    }

    public function test_zero_rate_and_unapproved_agent_do_not_earn_commission(): void
    {
        $agent = $this->agent('submitted');
        $client = $this->client($agent);
        $plan = Plan::factory()->create(['price' => 10000]);
        $invoice = $this->invoice($client);
        $invoice->items()->create(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => 'Subscription', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0, 'tax' => 0, 'total' => 10000]);
        Subscription::factory()->create(['customer_id' => $client->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id]);
        app(InvoiceManager::class)->payment($invoice, ['amount' => 10000, 'method' => 'cash', 'reference' => 'DIRECT', 'paid_at' => now()], $agent);
        $this->assertDatabaseCount('partner_commissions',0);
        PartnerApplication::where('user_id',$agent->id)->update(['status' => 'approved']);
        app(CommissionService::class)->accrue($invoice->fresh());
        $this->assertDatabaseCount('partner_commissions',0);
    }
}
