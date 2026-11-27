<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Partners\CommissionService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Models\Customer;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LicenseActivationCode;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\PartnerNetworkCommission;
use App\Models\PartnerPointEntry;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProspectiveCustomer;
use App\Models\Subscription;
use App\Models\SoftwareLicense;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReply;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SupportTeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_partner_can_create_track_edit_reply_and_resolve_client_ticket_with_attachments(): void
    {
        Storage::fake('local');
        $this->seed(SupportTeamSeeder::class);
        $partner = $this->agent();
        $client = $this->client($partner);
        $team = SupportTeam::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($partner)->post(route('partner.support.store'), [
            'customer_uuid' => $client->uuid,
            'team_uuid' => $team->uuid,
            'subject' => 'Client cannot access portal',
            'priority' => 'normal',
            'message' => 'The client receives an error when signing in.',
            'attachments' => [
                UploadedFile::fake()->image('error.png'),
                UploadedFile::fake()->createWithContent('details.txt', 'The error occurred after the latest update.'),
            ],
        ])->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $message = $ticket->messages()->oldest()->firstOrFail()->load('attachments');
        $this->assertCount(2, $message->attachments);
        foreach ($message->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }
        Notification::assertSentOnDemand(SupportTicketReply::class, fn ($notification, $channels, $recipient) => ($recipient->routes['mail'] ?? null) === $client->email);
        Notification::assertSentOnDemand(SupportTicketReply::class, fn ($notification, $channels, $recipient) => ($recipient->routes['mail'] ?? null) === $partner->email);

        $this->get(route('partner.support.show', $ticket))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Ticket')
            ->where('ticket.uuid', $ticket->uuid)
            ->has('ticket.messages.0.attachments', 2));
        $this->get(route('partner.support.attachments.show', $message->attachments->first()->uuid))->assertOk();

        $this->put(route('partner.support.update', $ticket), [
            'team_uuid' => $team->uuid,
            'subject' => 'Updated portal access issue',
            'priority' => 'high',
            'message' => 'The client receives an error after entering their credentials.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Updated portal access issue', $ticket->fresh()->subject);
        $this->assertSame('high', $ticket->fresh()->priority->value);

        $this->post(route('partner.support.reply', $ticket), [
            'message' => 'The client has provided a screenshot of the current error.',
            'attachments' => [UploadedFile::fake()->image('follow-up.png')],
        ])->assertSessionHasNoErrors();
        $this->assertCount(2, $ticket->fresh()->messages);
        Notification::assertSentOnDemand(\App\Notifications\SupportTicketReply::class);

        $otherPartner = $this->agent();
        $this->actingAs($otherPartner)->get(route('partner.support.show', $ticket))->assertNotFound();
        $this->get(route('partner.support.attachments.show', $message->attachments->first()->uuid))->assertForbidden();

        $this->actingAs($partner)->post(route('partner.support.resolve', $ticket))->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status->value);
        $this->post(route('partner.support.reply', $ticket), ['message' => 'Please reopen this ticket for further review.'])->assertSessionHasNoErrors();
        $this->assertSame('open', $ticket->fresh()->status->value);
    }

    public function test_customers_are_not_partner_types_and_partner_login_uses_own_dashboard(): void
    {
        $this->get('/partners/customer/register')->assertNotFound();
        $user = $this->agent('draft', 'developer');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login.two-factor'));
        $this->verifyLoginCode($user)->assertRedirect(route('partner.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get('/partner')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Partner/Dashboard')
            ->where('canManageClients', false)
            ->where('auth.partner_photo_url', null)
            ->where('auth.partner_applications.0.type', 'developer')
            ->where('auth.partner_applications.0.label', 'Developer')
            ->where('auth.partner_applications.0.status', 'draft'));
        $this->get('/dashboard')->assertRedirect(route('partner.dashboard'));
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_pending_developer_investor_and_non_partner_cannot_manage_clients(): void
    {
        foreach ([['submitted', 'sales_agent'], ['approved', 'developer'], ['approved', 'investor']] as [$status,$type]) {
            $user = $this->agent($status, $type);
            foreach (['/partner/clients', '/partner/subscriptions', '/partner/activation-codes', '/partner/invoices', '/partner/payments', '/partner/commission', '/partner/nrs-commission', '/partner/effort-points', '/partner/revenue', '/partner/support'] as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
        $this->actingAs(User::factory()->create())->get('/partner')->assertForbidden();
    }

    public function test_overview_reports_only_the_partners_current_portfolio_and_effort_activity(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        $partner = $this->agent();
        $other = $this->agent();
        $this->client($partner);
        $this->client($partner)->update(['status' => 'inactive']);
        $this->client($other);
        $prospect = new ProspectiveCustomer(['name' => 'Prospect']);
        $prospect->forceFill(['partner_user_id' => $partner->id])->save();
        $otherProspect = new ProspectiveCustomer(['name' => 'Other prospect']);
        $otherProspect->forceFill(['partner_user_id' => $other->id])->save();
        $lead = Lead::factory()->create(['status' => 'won']);
        $lead->forceFill(['partner_user_id' => $partner->id])->save();
        $otherLead = Lead::factory()->create(['status' => 'won']);
        $otherLead->forceFill(['partner_user_id' => $other->id])->save();
        PartnerPointEntry::create(['partner_user_id' => $partner->id, 'prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 1]);
        PartnerPointEntry::create(['partner_user_id' => $partner->id, 'lead_id' => $lead->id, 'event_type' => 'opportunity_won', 'points' => 10]);
        PartnerPointEntry::create(['partner_user_id' => $partner->id, 'event_type' => 'payment_redemption', 'points' => -5]);
        $previousMonth = PartnerPointEntry::create(['partner_user_id' => $partner->id, 'event_type' => 'visit_recorded', 'points' => 2]);
        $previousMonth->forceFill(['created_at' => now()->subMonth()])->save();
        $oldEntry = PartnerPointEntry::create(['partner_user_id' => $partner->id, 'event_type' => 'visit_recorded', 'points' => 3]);
        $oldEntry->forceFill(['created_at' => now()->subMonths(6)])->save();
        PartnerPointEntry::create(['partner_user_id' => $other->id, 'prospective_customer_id' => $otherProspect->id, 'event_type' => 'pcr_registered', 'points' => 99]);

        $this->actingAs($partner)->get('/partner')->assertInertia(fn (Assert $page) => $page
            ->where('performance.prospects', 1)
            ->where('performance.leads', 1)
            ->where('performance.active_clients', 1)
            ->where('performance.points_balance', 11)
            ->where('performance.qualification.level', 'qualified')
            ->where('performance.network_policy_enabled', false)
            ->has('performance.activity', 6)
            ->where('performance.activity.0.points', 0)
            ->where('performance.activity.4.points', 2)
            ->where('performance.activity.5.points', 11)
            ->where('performance.pipeline.8.status', 'won')
            ->where('performance.pipeline.8.count', 1));
    }

    public function test_client_owner_is_server_assigned_and_cannot_be_changed(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $response = $this->actingAs($agent)->post('/partner/clients', [
            'name' => 'Agent Client', 'email' => 'client@example.test', 'type' => 'organization',
            'partner_user_id' => $other->id, 'status' => 'inactive',
        ])->assertSessionHasNoErrors();
        $client = Customer::where('email', 'client@example.test')->firstOrFail();
        $response->assertRedirect(route('partner.customers.subscriptions.create', $client->uuid));
        $this->assertSame($agent->id, $client->partner_user_id);
        $this->assertSame('active', $client->status->value);
        $this->assertSame('organization', $client->type->value);
        $this->assertNotNull($client->customer_number);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'partner.client_registered', 'actor_id' => $agent->id, 'entity_id' => $client->uuid,
        ]);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('prospective_customers', 0);
        $this->assertDatabaseCount('partner_point_entries', 0);
        $this->get('/partner/clients')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)->where('customers.data.0.uuid', $client->uuid));
        $this->get('/partner')->assertInertia(fn (Assert $page) => $page
            ->where('performance.active_clients', 1)
            ->where('performance.qualification.level', 'qualified'));
        $this->put('/partner/clients/'.$client->uuid, ['name' => 'Updated Client', 'email' => 'client@example.test', 'partner_user_id' => $other->id])->assertRedirect();
        $this->assertSame($agent->id, $client->fresh()->partner_user_id);
        $this->assertFalse($agent->customers()->exists());
    }

    public function test_direct_client_registration_validates_contact_and_rejects_duplicate_email(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $existing = $this->client($other);
        $existing->update(['email' => 'existing@example.test']);

        $this->actingAs($agent)->from('/partner/clients')->post('/partner/clients', [
            'name' => 'Duplicate', 'type' => 'individual', 'email' => 'EXISTING@example.test',
        ])->assertSessionHasErrors('email');
        $this->assertSame($other->id, $existing->fresh()->partner_user_id);
        $this->assertDatabaseCount('customers', 1);

        $this->post('/partner/clients', ['name' => 'Missing email', 'type' => 'individual', 'phone' => '123456'])
            ->assertSessionHasErrors('email');
        $this->post('/partner/clients', ['name' => 'Invalid type', 'type' => 'other', 'email' => 'type@example.test', 'phone' => '123456'])
            ->assertSessionHasErrors('type');
        $response = $this->post('/partner/clients', ['name' => 'Phone client', 'type' => 'individual', 'email' => 'phone@example.test', 'phone' => '123456'])
            ->assertSessionHasNoErrors();
        $response->assertRedirect(route('partner.customers.subscriptions.create', Customer::where('name', 'Phone client')->firstOrFail()->uuid));
        $this->assertDatabaseHas('customers', ['name' => 'Phone client', 'partner_user_id' => $agent->id, 'status' => 'active']);
    }

    public function test_direct_registration_records_business_details_contact_and_interested_products(): void
    {
        $agent = $this->agent();
        $products = Product::factory()->count(2)->create(['status' => 'active']);
        Product::factory()->create(['status' => 'inactive']);
        $this->actingAs($agent)->get('/partner/clients')->assertInertia(fn (Assert $page) => $page
            ->has('products', 2)
            ->where('products.0.id', $products[0]->id));

        $response = $this->post('/partner/clients', [
            'name' => 'Example School', 'type' => 'organization', 'email' => 'school@example.test',
            'contact_name' => 'Jane Contact', 'contact_role' => 'Director',
            'organization_type' => 'school', 'estimated_number_of_users' => 120,
            'billing_address' => '12 Main Street', 'city' => 'Lagos',
            'state' => 'Lagos', 'product_ids' => $products->pluck('id')->all(),
            'tax_id' => 'TAX-123', 'country' => 'ng',
        ])->assertSessionHasNoErrors();

        $client = Customer::where('email', 'school@example.test')->firstOrFail();
        $response->assertRedirect(route('partner.customers.subscriptions.create', $client->uuid));
        $this->assertNull($client->country);
        $this->assertSame('12 Main Street', $client->billing_address);
        $this->assertSame('Lagos', $client->city);
        $this->assertSame('Lagos', $client->state);
        $this->assertNull($client->tax_id);
        $this->assertSame('school', $client->metadata['organization_type']);
        $this->assertSame(120, $client->metadata['estimated_number_of_users']);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $client->id, 'name' => 'Jane Contact', 'job_title' => 'Director',
            'email' => 'school@example.test', 'is_primary' => true,
        ]);
        foreach ($products as $product) {
            $this->assertDatabaseHas('customer_product', ['customer_id' => $client->id, 'product_id' => $product->id]);
        }
        $this->assertDatabaseCount('subscriptions', 0);
        $this->get('/partner/clients')->assertInertia(fn (Assert $page) => $page
            ->where('customers.data.0.metadata.organization_type', 'school')
            ->where('customers.data.0.contacts.0.name', 'Jane Contact')
            ->has('customers.data.0.products', 2));
        $this->actingAs($this->agent())->get('/partner/clients')->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    }

    public function test_direct_registration_validates_business_details_and_product_selection(): void
    {
        $agent = $this->agent();
        $product = Product::factory()->create(['status' => 'active']);
        $inactive = Product::factory()->create(['status' => 'inactive']);
        $this->actingAs($agent)->post('/partner/clients', [
            'name' => 'Invalid', 'type' => 'organization', 'email' => 'invalid@example.test', 'phone' => '123456',
            'contact_role' => 'Director', 'organization_type' => 'unknown', 'estimated_number_of_users' => 0,
            'product_ids' => [$product->id, $product->id, $inactive->id, 999999],
        ])->assertSessionHasErrors([
            'contact_name', 'organization_type', 'estimated_number_of_users',
            'product_ids.0', 'product_ids.1', 'product_ids.2', 'product_ids.3',
        ]);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_only_approved_sales_agents_can_register_clients(): void
    {
        $data = ['name' => 'Client', 'type' => 'individual', 'phone' => '123456'];
        $this->post('/partner/clients', $data)->assertRedirect(route('login'));
        foreach ([['submitted', 'sales_agent'], ['approved', 'developer'], ['approved', 'investor']] as [$status, $type]) {
            $this->actingAs($this->agent($status, $type))->post('/partner/clients', $data)->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->post('/partner/clients', $data)->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
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
        $this->get(route('partner.customers.subscriptions.create', $client->uuid))->assertNotFound();
        $this->get('/partner/support/'.$ticket->uuid)->assertNotFound();
        $this->post('/partner/support/'.$ticket->uuid.'/messages', ['message' => 'A valid reply'])->assertNotFound();
        $this->get('/admin/partner-commissions')->assertForbidden();
    }

    public function test_client_subscription_setup_shows_interests_and_creates_only_selected_plan(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $interests = Product::factory()->count(2)->create(['status' => 'active']);
        $client->products()->attach($interests->modelKeys());
        $otherProduct = Product::factory()->create(['status' => 'active']);
        $inactiveProduct = Product::factory()->create(['status' => 'inactive']);
        $selectedPlan = Plan::factory()->create(['product_id' => $interests[0]->id, 'price' => '5000.00']);
        Plan::factory()->create(['product_id' => $interests[1]->id]);
        Plan::factory()->create(['product_id' => $otherProduct->id]);
        $inactivePlan = $selectedPlan->replicate(['uuid']);
        $inactivePlan->product_id = $inactiveProduct->id;
        $inactivePlan->save();
        $privatePlan = $selectedPlan->replicate(['uuid']);
        $privatePlan->slug = 'private-plan';
        $privatePlan->is_public = false;
        $privatePlan->save();

        $this->actingAs($agent)->get(route('partner.customers.subscriptions.create', $client->uuid))
            ->assertInertia(fn (Assert $page) => $page->component('Partner/CreateSubscription')
                ->where('customer.uuid', $client->uuid)
                ->where('customer.interested_product_ids', $interests->modelKeys())
                ->has('products', 3)
                ->has('plans', 3));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $response = $this->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $selectedPlan->uuid,
        ]);
        $invoice = Invoice::firstOrFail();
        $response->assertRedirect(route('partner.invoices.show', $invoice->uuid));
        $this->assertDatabaseHas('subscriptions', [
            'customer_id' => $client->id, 'product_id' => $interests[0]->id,
            'plan_id' => $selectedPlan->id, 'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_client_subscription_setup_allows_no_interests_and_requires_an_approved_owner(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $product = Product::factory()->create(['status' => 'active']);
        Plan::factory()->create(['product_id' => $product->id]);
        $url = route('partner.customers.subscriptions.create', $client->uuid);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->agent('submitted'))->get($url)->assertForbidden();
        $this->actingAs($agent)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('customer.interested_product_ids', [])
            ->has('products', 1)->has('plans', 1));
    }

    public function test_client_subscriptions_and_activation_statuses_are_partner_scoped(): void
    {
        $partner = $this->agent();
        $other = $this->agent();
        $client = $this->client($partner);
        $otherClient = $this->client($other);
        $subscription = Subscription::factory()->create(['customer_id' => $client->id]);
        $otherSubscription = Subscription::factory()->create(['customer_id' => $otherClient->id]);

        foreach ([$subscription, $otherSubscription] as $entry) {
            $license = SoftwareLicense::factory()->create(['subscription_id' => $entry->id]);
            LicenseActivationCode::create([
                'software_license_id' => $license->id,
                'product_id' => $license->product_id,
                'code_lookup' => hash('sha256', (string) $entry->id),
                'code_hash' => 'stored-hash',
                'status' => 'issued',
                'expires_at' => now()->subDay(),
            ]);
        }

        $this->actingAs($partner)->get('/partner/subscriptions')->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Subscriptions')->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.uuid', $subscription->uuid)
            ->where('subscriptions.data.0.customer.name', $client->name));
        $this->get('/partner/activation-codes')->assertInertia(fn (Assert $page) => $page
            ->component('Partner/ActivationCodes')->has('codes.data', 1)
            ->where('codes.data.0.customer', $client->name)
            ->where('codes.data.0.status', 'expired')
            ->missing('codes.data.0.code_hash')->missing('codes.data.0.code_lookup'));
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
        foreach (['/partner/invoices', '/partner/payments', '/partner/commission', '/partner/effort-points', '/partner/support'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/partner/revenue')->assertRedirect('/partner/commission');
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
        $this->put('/admin/partner-point-value', ['partner_point_value' => 750])->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put('/admin/partner-commissions/'.$product->slug, ['partner_commission_rate' => 101])->assertSessionHasErrors('partner_commission_rate');
        $this->put('/admin/partner-commissions/'.$product->slug, ['partner_commission_rate' => 7.5, 'organization_partner_commission_rate' => 10, 'developer_commission_rate' => 0, 'vat_commission_rate' => 7.5, 'platform_commission_rate' => 85, 'staff_commission_rate' => 0])->assertRedirect();
        $this->put('/admin/partner-point-value', ['partner_point_value' => 750])->assertRedirect();
        $this->assertEquals(7.5, $product->fresh()->partner_commission_rate);
        $this->assertSame('750.00', BillingSetting::findOrFail(1)->partner_point_value);
        $this->put('/admin/partner-point-value', ['partner_point_value' => 0])->assertSessionHasErrors('partner_point_value');
        $this->get('/admin/partner-commissions')->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Partners/Commissions')
            ->where('pointValue', '750.00'));
    }

    public function test_commission_page_shows_qualification_and_only_the_partners_network_earnings(): void
    {
        $partner = $this->agent();
        $sourcePartner = $this->agent();
        $this->client($partner);
        $sourceClient = $this->client($sourcePartner);
        $invoice = $this->invoice($sourceClient);
        $product = Product::factory()->create();
        PartnerNetworkCommission::create([
            'partner_user_id' => $partner->id,
            'source_partner_user_id' => $sourcePartner->id,
            'source_customer_id' => $sourceClient->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'partner_level' => 'qualified',
            'level_weight' => 1,
            'pool_rate' => 5,
            'payment_base_amount' => 100,
            'platform_amount' => 50,
            'pool_amount' => 5,
            'amount' => 5,
            'currency' => 'NGN',
            'status' => 'earned',
            'earned_at' => now(),
        ]);
        PartnerNetworkCommission::create([
            'partner_user_id' => $sourcePartner->id,
            'source_partner_user_id' => $sourcePartner->id,
            'source_customer_id' => $sourceClient->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'partner_level' => 'qualified',
            'level_weight' => 1,
            'pool_rate' => 5,
            'payment_base_amount' => 100,
            'platform_amount' => 50,
            'pool_amount' => 5,
            'amount' => 25,
            'currency' => 'NGN',
            'status' => 'earned',
            'earned_at' => now(),
        ]);
        $this->actingAs($partner)->get('/partner/commission')->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Commission')
            ->where('qualification.level', 'qualified')
            ->where('qualification.active_clients', 1)
            ->where('networkPolicyEnabled', false)
            ->has('networkCommissions.data', 1)
            ->where('networkCommissions.data.0.amount', '5.00')
            ->has('personalEarnings', 0)
            ->has('networkEarnings', 1)
            ->has('totalEarnings', 1));
        $this->get('/partner/nrs-commission')->assertInertia(fn (Assert $page) => $page
            ->component('Partner/NetworkCommission')
            ->where('qualification.level', 'qualified')
            ->has('networkCommissions.data', 1)
            ->where('networkCommissions.data.0.amount', '5.00')
            ->has('networkEarnings', 1)
            ->missing('networkCommissions.data.0.source_customer_id'));
        $this->get('/partner')->assertInertia(fn (Assert $page) => $page
            ->has('performance.network_earnings', 1)
            ->where('performance.network_earnings.0.total', 5));
    }

    public function test_verified_client_payment_redeems_points_with_a_payment_cap_and_refund_restores_them(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $invoice = $this->invoice($client);
        $prospect = new ProspectiveCustomer(['name' => 'Point Test Prospect']);
        $prospect->forceFill(['partner_user_id' => $agent->id])->save();
        PartnerPointEntry::create(['partner_user_id' => $agent->id, 'prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 12]);

        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 2500, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'POINT-CAP', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 2500]], $agent);
        $redemption = PartnerPointEntry::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(-5, $redemption->points);
        $this->assertSame('2500.00', $redemption->amount);
        $this->assertSame(7, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
        $this->actingAs($agent)->get('/partner/effort-points')->assertInertia(fn (Assert $p) => $p
            ->where('points.balance', 7)
            ->where('pointBonuses.0.total', 2500)
            ->has('pointEntries.data', 2));

        $manager->refund($payment->fresh(), $agent, 'Reverse point bonus');
        $this->assertSame('reversed', $redemption->fresh()->status);
        $this->assertSame(12, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
    }

    public function test_points_carry_to_the_next_verified_ngn_payment_and_ignore_other_currencies(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $invoice = $this->invoice($client);
        $prospect = new ProspectiveCustomer(['name' => 'Carry Test Prospect']);
        $prospect->forceFill(['partner_user_id' => $agent->id])->save();
        PartnerPointEntry::create(['partner_user_id' => $agent->id, 'prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 7]);
        $manager = app(PaymentManager::class);
        $usdInvoice = $this->invoice($client);
        $usdInvoice->update(['currency' => 'USD']);
        $usdPayment = $manager->create(['customer_id' => $client->id, 'amount' => 1000, 'currency' => 'USD', 'payment_method' => 'cash', 'transaction_reference' => 'POINT-USD', 'payment_date' => today()], $agent);
        $manager->verify($usdPayment, [['invoice_id' => $usdInvoice->id, 'amount' => 1000]], $agent);
        $this->assertDatabaseMissing('partner_point_entries', ['payment_id' => $usdPayment->id, 'event_type' => 'payment_redemption']);
        $this->assertSame(7, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));

        $payment = $manager->create(['customer_id' => $client->id, 'amount' => 2500, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'POINT-CARRY-1', 'payment_date' => today()], $agent);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => 2500]], $agent);
        $this->assertSame(-5, PartnerPointEntry::query()->where('payment_id', $payment->id)->value('points'));
        $this->assertSame(2, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));

        $secondInvoice = $this->invoice($client);
        $secondPayment = $manager->create(['customer_id' => $client->id, 'amount' => 1000, 'currency' => 'NGN', 'payment_method' => 'cash', 'transaction_reference' => 'POINT-CARRY-2', 'payment_date' => today()], $agent);
        $manager->verify($secondPayment, [['invoice_id' => $secondInvoice->id, 'amount' => 1000]], $agent);
        $this->assertSame(-2, PartnerPointEntry::query()->where('payment_id', $secondPayment->id)->value('points'));
        $this->assertSame(0, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
    }

    public function test_effort_points_page_shows_own_effort_and_anonymous_approved_partner_averages(): void
    {
        $agent = $this->agent();
        $prospect = new ProspectiveCustomer(['name' => 'My Prospect']);
        $prospect->forceFill(['partner_user_id' => $agent->id])->save();
        PartnerPointEntry::create(['partner_user_id' => $agent->id, 'prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 1]);

        foreach ([2, 4, 6] as $index => $points) {
            $other = $this->agent();
            $otherProspect = new ProspectiveCustomer(['name' => 'Other Prospect '.$index]);
            $otherProspect->forceFill(['partner_user_id' => $other->id])->save();
            PartnerPointEntry::create(['partner_user_id' => $other->id, 'prospective_customer_id' => $otherProspect->id, 'event_type' => 'pcr_registered', 'points' => $points]);
        }

        $this->actingAs($agent)->get('/partner/effort-points')->assertInertia(fn (Assert $p) => $p
            ->component('Partner/EffortPoints')
            ->where('points.balance', 1)
            ->where('comparisonAvailable', true)
            ->where('comparison.0.your_points', 1)
            ->where('comparison.0.other_average', 4)
            ->has('pointEntries.data', 1)
            ->missing('partners')
            ->missing('partnerNames'));
    }

    public function test_effort_comparison_is_hidden_when_fewer_than_three_other_partners_are_approved(): void
    {
        $agent = $this->agent();
        $this->agent();
        $this->actingAs($agent)->get('/partner/effort-points')->assertInertia(fn (Assert $p) => $p
            ->where('comparisonAvailable', false)
            ->has('comparison', 4));
    }

    public function test_successful_invoice_payment_redeems_points_once(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $invoice = $this->invoice($client);
        $prospect = new ProspectiveCustomer(['name' => 'Invoice Payment Prospect']);
        $prospect->forceFill(['partner_user_id' => $agent->id])->save();
        PartnerPointEntry::create(['partner_user_id' => $agent->id, 'prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 6]);

        app(InvoiceManager::class)->payment($invoice, [
            'amount' => 1750,
            'method' => 'cash',
            'reference' => 'INVOICE-POINTS',
            'paid_at' => now(),
        ], $agent);

        $redemption = PartnerPointEntry::query()->where('event_type', 'payment_redemption')->firstOrFail();
        $this->assertNotNull($redemption->invoice_payment_id);
        $this->assertNull($redemption->payment_id);
        $this->assertSame(-3, $redemption->points);
        $this->assertSame('1500.00', $redemption->amount);
        $this->assertSame(3, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
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
        $this->actingAs($agent)->get('/partner/commission')->assertInertia(fn (Assert $p) => $p->has('earnings', 0)->has('collections', 0));
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
        $this->actingAs($this->agent())->get('/partner/commission')->assertInertia(fn (Assert $p) => $p->has('commissions.data', 0)->has('earnings', 0));
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
