<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, PlanSeeder::class]);
    }

    public function test_customer_portal_returns_only_linked_commercial_records(): void
    {
        [$user, $customer] = $this->account('customer@example.com', 'KBT-CUS-000001');
        [, $other] = $this->account('other@example.com', 'KBT-CUS-000002');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        $customer->products()->attach($plan->product_id);
        $invoice = $this->invoice($customer, $user, 'KBT-INV-2026-000001');
        $this->invoice($other, $user, 'KBT-INV-2026-000002');
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id, 'status' => 'active', 'created_by' => $user->id]);
        $this->artisan('licenses:issue', ['subscription' => $subscription->uuid])->assertSuccessful();
        SupportTicket::factory()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'opened_by' => $user->id, 'status' => 'open']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Dashboard')
            ->where('summary.active_subscriptions', 1)
            ->where('summary.active_licenses', 1)
            ->where('summary.open_tickets', 1)
            ->has('invoices', 1)
            ->where('invoices.0.number', 'KBT-INV-2026-000001')
            ->has('subscriptions', 1)
            ->has('licenses', 1));
    }

    public function test_verified_login_can_be_linked_with_the_controlled_operator_command(): void
    {
        $user = User::factory()->create(['email' => 'verified@example.com']);
        $customer = Customer::factory()->create(['customer_number' => 'KBT-CUS-000010']);

        $this->artisan('customer:link-user', ['customer' => $customer->customer_number, 'email' => $user->email, '--primary' => true])->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole(UserRole::Customer->value));
        $this->assertDatabaseHas('customer_user', ['customer_id' => $customer->id, 'user_id' => $user->id, 'is_primary' => true]);
    }

    public function test_customer_account_sections_only_return_linked_records(): void
    {
        [$user, $customer] = $this->account('portal@example.com', 'KBT-CUS-000020');
        [, $other] = $this->account('outsider@example.com', 'KBT-CUS-000021');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        $invoice = $this->invoice($customer, $user, 'KBT-INV-2026-000020');
        $this->invoice($other, $user, 'KBT-INV-2026-000021');
        Subscription::factory()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id, 'status' => 'active', 'created_by' => $user->id]);

        $this->actingAs($user)->get(route('portal.services.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Customer/Services/Index')->has('services', 1));
        $this->get(route('portal.invoices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Customer/Invoices/Index')->has('invoices', 1)->where('invoices.0.number', 'KBT-INV-2026-000020'));
        $this->get(route('portal.payments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Customer/Payments/Index')->has('payments', 0));
    }

    public function test_customer_services_include_client_app_setup_template_for_registered_services(): void
    {
        [$user, $customer] = $this->account('setup@example.com', 'KBT-CUS-000022');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        $invoice = $this->invoice($customer, $user, 'KBT-INV-2026-000022');
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id, 'status' => 'active', 'created_by' => $user->id]);
        $this->artisan('licenses:issue', ['subscription' => $subscription->uuid])->assertSuccessful();

        $this->actingAs($user)->get(route('portal.services.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Services/Index')
            ->where('services.0.client_setup.product_code', $plan->product->code)
            ->where('services.0.client_setup.api_url', rtrim((string) config('app.url'), '/').'/api/v1')
            ->where('services.0.client_setup.has_license', true)
            ->where('services.0.client_setup.env', fn (string $env): bool => str_contains($env, 'KERNELBRIDGE_PRODUCT_CODE='.$plan->product->code)
                && str_contains($env, 'KERNELBRIDGE_API_TOKEN=replace-with-product-machine-token')
                && str_contains($env, 'KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-independent-long-random-secret')
                && str_contains($env, 'KERNELBRIDGE_PROTECT_WEB_ROUTES=true'))
            ->where('services.0.client_setup.composer_commands', fn (string $commands): bool => str_contains($commands, 'composer require kernelbridge/licensing-client-laravel:^1.0'))
            ->where('services.0.client_setup.route_middleware', fn (string $code): bool => str_contains($code, 'kernelbridge.subscription.active'))
            ->where('services.0.client_setup.license_key', fn (?string $key): bool => is_string($key) && str_starts_with($key, 'KBT-')));
    }

    public function test_customer_can_submit_payment_evidence_for_own_account(): void
    {
        Storage::fake('public');
        [$user, $customer] = $this->account('payer@example.com', 'KBT-CUS-000030');

        $this->actingAs($user)->post(route('portal.payments.store'), [
            'customer_uuid' => $customer->uuid, 'amount' => '1250.00', 'currency' => 'ngn',
            'payment_method' => 'bank_transfer', 'transaction_reference' => 'CUST-BANK-001',
            'payment_date' => today()->toDateString(), 'proof_of_payment' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect(route('portal.payments.index'));

        $payment = Payment::query()->where('transaction_reference', 'CUST-BANK-001')->firstOrFail();
        $this->assertSame($customer->id, $payment->customer_id);
        $this->assertSame('pending', $payment->status->value);
        Storage::disk('public')->assertExists($payment->proof_of_payment);
    }

    public function test_customer_can_create_update_suspend_and_cancel_services_without_hard_deletion(): void
    {
        [$user, $customer] = $this->account('services@example.com', 'KBT-CUS-000035');
        $plan = Plan::query()->where('slug', 'basic')->where('is_public', true)->firstOrFail();

        $this->actingAs($user)->post(route('portal.services.store'), [
            'product_uuid' => $plan->product->uuid, 'plan_uuid' => $plan->uuid, 'auto_renew' => true,
        ])->assertRedirect();
        $subscription = Subscription::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $subscription->status->value);

        $this->put(route('portal.services.update', $subscription), ['plan_uuid' => $plan->uuid, 'auto_renew' => false])->assertRedirect();
        $this->assertFalse($subscription->fresh()->auto_renew);

        $this->post(route('portal.services.suspend', $subscription), ['reason' => 'Temporarily pausing deployment'])->assertRedirect(route('portal.services.index'));
        $this->assertSame('suspended', $subscription->fresh()->status->value);

        $this->delete(route('portal.services.destroy', $subscription), ['reason' => 'No longer require this service'])->assertRedirect(route('portal.services.index'));
        $this->assertSame('cancelled', $subscription->fresh()->status->value);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('subscription_changes', ['subscription_id' => $subscription->id, 'change_type' => 'cancelled']);
    }

    public function test_service_form_separates_products_and_plans_and_exposes_database_features(): void
    {
        [$user, $customer] = $this->account('selector@example.com', 'KBT-CUS-000038');

        $this->actingAs($user)->get(route('portal.services.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Services/Create')
            ->where('customer.uuid', $customer->uuid)
            ->has('products', 4)
            ->has('plans', 8)
            ->has('plans.0.features')
            ->missing('accounts'));
    }

    public function test_verified_flutterwave_payment_pays_invoice_records_payment_and_activates_service(): void
    {
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-safe', 'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3']);
        [$user] = $this->account('online-payer@example.com', 'KBT-CUS-000039');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        Http::fake(['https://api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/123']])]);

        $this->actingAs($user)->post(route('portal.services.store'), ['product_uuid' => $plan->product->uuid, 'plan_uuid' => $plan->uuid, 'auto_renew' => true])->assertRedirect();
        $invoice = Invoice::query()->firstOrFail();
        $subscription = Subscription::query()->firstOrFail();

        $this->post(route('portal.invoices.pay-online', $invoice))->assertRedirect('https://checkout.flutterwave.test/pay/123');
        $reference = Payment::query()->where('provider', 'flutterwave')->value('transaction_reference');
        Http::fake(['https://api.flutterwave.com/v3/transactions/98765/verify' => Http::response(['status' => 'success', 'data' => ['id' => 98765, 'status' => 'successful', 'amount' => $invoice->total, 'currency' => $plan->currency, 'tx_ref' => $reference, 'payment_type' => 'card']])]);
        $this->get(route('payments.flutterwave.callback', ['status' => 'successful', 'tx_ref' => $reference, 'transaction_id' => '98765']))->assertRedirect(route('portal.invoices.show', $invoice));
        $this->get(route('payments.flutterwave.callback', ['status' => 'successful', 'tx_ref' => $reference, 'transaction_id' => '98765']))->assertRedirect(route('portal.invoices.show', $invoice));

        $this->assertSame('paid', $invoice->fresh()->status->value);
        $this->assertSame('active', $subscription->fresh()->status->value);
        $this->assertDatabaseCount('software_licenses', 1);
        $license = SoftwareLicense::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertNotNull(data_get($license->metadata, 'encrypted_license_key'));
        $this->assertDatabaseHas('payments', ['transaction_reference' => $reference, 'provider' => 'flutterwave', 'status' => 'verified']);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('software_licenses', 1);
    }

    public function test_inertia_online_payment_request_receives_an_external_location_response(): void
    {
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-safe', 'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3']);
        [$user, $customer] = $this->account('inertia-payer@example.com', 'KBT-CUS-000042');
        $invoice = $this->invoice($customer, $user, 'KBT-INV-2026-000042');
        Http::fake(['https://api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/inertia']])]);

        $this->actingAs($user)->withHeader('X-Inertia', 'true')->post(route('portal.invoices.pay-online', $invoice))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.flutterwave.test/pay/inertia');
    }

    public function test_customer_cannot_manage_another_customers_service(): void
    {
        [$user] = $this->account('service-owner@example.com', 'KBT-CUS-000036');
        [$otherUser, $otherCustomer] = $this->account('service-other@example.com', 'KBT-CUS-000037');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        $subscription = Subscription::factory()->create(['customer_id' => $otherCustomer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'status' => 'active', 'created_by' => $otherUser->id]);

        $this->actingAs($user)->get(route('portal.services.edit', $subscription))->assertForbidden();
        $this->post(route('portal.services.suspend', $subscription), ['reason' => 'Unauthorized suspension'])->assertForbidden();
        $this->assertSame('active', $subscription->fresh()->status->value);
    }

    public function test_support_form_lists_products_from_customer_services(): void
    {
        [$user, $customer] = $this->account('support-products@example.com', 'KBT-CUS-000043');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        Subscription::factory()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'status' => 'active', 'created_by' => $user->id]);

        $this->actingAs($user)->get(route('support.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Support/Create')->has('products', 1)->where('products.0.uuid', $plan->product->uuid));
    }

    public function test_customer_cannot_deactivate_another_customers_installation(): void
    {
        [$user] = $this->account('owner@example.com', 'KBT-CUS-000040');
        [$otherUser, $otherCustomer] = $this->account('other-owner@example.com', 'KBT-CUS-000041');
        $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
        $subscription = Subscription::factory()->create(['customer_id' => $otherCustomer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'status' => 'active', 'created_by' => $otherUser->id]);
        $this->artisan('licenses:issue', ['subscription' => $subscription->uuid])->assertSuccessful();
        $license = SoftwareLicense::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $activation = $license->activations()->create(['device_fingerprint' => hash('sha256', 'device'), 'device_name' => 'Office PC', 'status' => 'active', 'activated_at' => now()]);

        $this->actingAs($user)->post(route('portal.licenses.activations.deactivate', $activation))->assertForbidden();
        $this->assertSame('active', $activation->fresh()->status);
    }

    private function account(string $email, string $number): array
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(UserRole::Customer->value);
        $customer = Customer::factory()->create(['email' => $email, 'customer_number' => $number]);
        $customer->users()->attach($user->id, ['is_primary' => true]);

        return [$user, $customer];
    }

    private function invoice(Customer $customer, User $actor, string $number): Invoice
    {
        return Invoice::factory()->create(['customer_id' => $customer->id, 'invoice_number' => $number, 'status' => 'issued', 'total' => 1000, 'balance' => 1000, 'amount_paid' => 0, 'created_by' => $actor->id]);
    }
}
