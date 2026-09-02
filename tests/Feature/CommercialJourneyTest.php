<?php

namespace Tests\Feature;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DemoRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CommercialJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_visitor_to_renewal_and_support_journey(): void
    {
        Notification::fake();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, PlanSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::SuperAdmin->value);
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $plan = Plan::query()->where('product_id', $product->id)->where('slug', 'basic')->firstOrFail();

        $this->get(route('products.show', $product))->assertOk();
        $this->get(route('demo', ['product' => $product->slug]))->assertOk();
        $this->post(route('demo.store'), [
            'name' => 'Launch Prospect', 'organization' => 'Launch Academy', 'email' => 'launch@example.com',
            'phone' => '+2348000000000', 'location' => 'Lagos, Nigeria', 'organization_type' => 'school',
            'product_id' => $product->public_id, 'number_of_users' => 50,
            'preferred_demo_date' => now()->addWeek()->toDateString(), 'preferred_demo_time' => '10:00',
            'message' => 'Commercial launch journey.', 'source' => 'launch_test',
        ])->assertSessionHasNoErrors();
        $lead = Lead::query()->firstOrFail();
        $demo = DemoRequest::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.demo-requests.update', $demo), ['status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'assigned_to' => $admin->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.demo-requests.update', $demo->fresh()), ['status' => 'completed', 'scheduled_at' => now()->addDays(2), 'assigned_to' => $admin->id])->assertSessionHasNoErrors();
        $this->assertSame('demo_completed', $lead->fresh()->status->value);
        $this->actingAs($admin)->post(route('admin.leads.convert', $lead->fresh()))->assertRedirect();
        $customer = Customer::query()->where('email', 'launch@example.com')->firstOrFail();

        $portalUser = User::factory()->create(['email' => 'launch@example.com']);
        $portalUser->assignRole(UserRole::Customer->value);
        $customer->users()->attach($portalUser->id, ['is_primary' => true]);

        $this->actingAs($admin)->post(route('admin.quotations.store'), [
            'customer_id' => $customer->id, 'issue_date' => today()->toDateString(), 'expiry_date' => today()->addDays(14)->toDateString(),
            'currency' => 'NGN', 'terms' => 'Payment before activation.', 'items' => [[
                'product_id' => $product->id, 'plan_id' => $plan->id, 'description' => 'AlignEx subscription',
                'quantity' => 1, 'unit_price' => '75000.00', 'discount' => '0.00', 'tax' => '0.00',
            ]],
        ])->assertSessionHasNoErrors();
        $quotation = Quotation::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.quotations.send', $quotation))->assertSessionHasNoErrors();
        $acceptUrl = URL::temporarySignedRoute('quotations.customer.accept', now()->addHour(), ['quotation' => $quotation]);
        $this->post($acceptUrl)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.quotations.convert', $quotation->fresh()))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.invoices.issue', $invoice))->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.payments.store'), [
            'customer_id' => $customer->id, 'amount' => $invoice->total, 'currency' => $invoice->currency,
            'payment_method' => 'bank_transfer', 'transaction_reference' => 'LAUNCH-BANK-001',
            'payment_date' => today()->toDateString(), 'notes' => 'Verified launch payment.',
        ])->assertSessionHasNoErrors();
        $payment = Payment::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.payments.verify', $payment), ['allocations' => [['invoice_id' => $invoice->id, 'amount' => $invoice->total]]])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status->value);

        $subscriptions = app(SubscriptionService::class);
        $subscription = $subscriptions->create($customer, $plan, $invoice->fresh(), $admin);
        $subscription = $subscriptions->activate($subscription, $invoice->fresh(), $admin);
        $issued = app(LicenseService::class)->issue($subscription);
        $credentialService = app(ApiCredentialService::class);
        $apiClient = $credentialService->createClient($product, 'Launch integration');
        $token = $credentialService->issue($apiClient, 'launch', $admin)['token'];
        $this->withToken($token)->postJson('/api/v1/licenses/activate', ['license_key' => $issued['license_key'], 'device_id' => 'launch-installation'])->assertCreated()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->postJson('/api/v1/licenses/verify', ['license_key' => $issued['license_key'], 'device_id' => 'launch-installation'])->assertOk();

        $renewalInvoice = $this->renewalInvoice($subscription, $admin);
        $previousEnd = $subscription->ends_at->copy();
        $subscription = $subscriptions->renew($subscription, $renewalInvoice, $admin);
        $this->assertTrue($subscription->ends_at->greaterThan($previousEnd));

        $this->actingAs($portalUser)->get(route('dashboard'))->assertOk();
        $this->actingAs($portalUser)->post(route('support.store'), [
            'product_uuid' => $product->uuid, 'subject' => 'Launch onboarding support', 'category' => 'training',
            'priority' => 'normal', 'message' => 'Please confirm our onboarding session.',
        ])->assertSessionHasNoErrors();
        $ticket = SupportTicket::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.support.messages.store', $ticket), ['body' => 'Onboarding session confirmed.'])->assertSessionHasNoErrors();

        $this->assertSame('active', $subscription->status->value);
        $this->assertDatabaseHas('license_activations', ['status' => 'active']);
        $this->assertDatabaseHas('support_messages', ['body' => 'Onboarding session confirmed.', 'is_customer_visible' => true]);
    }

    private function renewalInvoice(Subscription $subscription, User $actor): Invoice
    {
        $invoice = Invoice::query()->create([
            'invoice_number' => 'KBT-INV-'.now()->year.'-999999', 'customer_id' => $subscription->customer_id,
            'issue_date' => today(), 'due_date' => today(), 'subtotal' => $subscription->price, 'discount' => 0,
            'tax' => 0, 'total' => $subscription->price, 'amount_paid' => $subscription->price, 'balance' => 0,
            'currency' => $subscription->currency, 'status' => 'paid', 'created_by' => $actor->id, 'paid_at' => now(),
        ]);
        $invoice->items()->create([
            'product_id' => $subscription->product_id, 'plan_id' => $subscription->plan_id,
            'product' => $subscription->product->name, 'plan' => $subscription->plan->name,
            'description' => 'Subscription renewal', 'quantity' => 1, 'unit_price' => $subscription->price,
            'discount' => 0, 'tax' => 0, 'total' => $subscription->price, 'sort_order' => 0,
        ]);

        return $invoice;
    }
}
