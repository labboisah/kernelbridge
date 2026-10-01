<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Services\PackageCatalog;
use App\Domain\Licensing\Services\PackageChangeService;
use App\Domain\Licensing\Services\PaidLicenseService;
use App\Models\ClientSetupBundle;
use App\Models\Customer;
use App\Models\HostingSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\MediFlowPackageSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, MediFlowPackageSeeder::class]);
        $this->owner = User::factory()->create();
        $customer = Customer::factory()->create();
        $customer->users()->attach($this->owner, ['is_primary' => true]);
        $this->plan = Plan::where('slug', 'pharmacy')->firstOrFail();
        $this->plan->update(['price' => '10000.00', 'setup_fee' => '1000.00', 'billing_cycle' => 'annual']);
        $this->actingAs($this->owner);
    }

    public function test_admin_price_and_features_reach_public_checkout_and_paid_snapshot(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $features = $this->plan->features()->where('feature_key', '!=', 'reports')->get()->map(fn ($f) => [
            'feature_key' => $f->feature_key, 'feature_name' => $f->feature_name,
            'description' => 'Administrator feature description', 'enabled' => $f->enabled, 'limit_value' => null,
        ])->all();
        $payload = ['name' => 'Pharmacy managed by admin', 'slug' => 'pharmacy', 'description' => 'Administrator package description',
            'price' => '25000.00', 'setup_fee' => '1500.00', 'currency' => 'NGN', 'billing_cycle' => 'monthly',
            'trial_days' => 0, 'grace_period_days' => 0, 'sort_order' => 0, 'is_active' => true, 'is_public' => true,
            'features' => $features, 'limits' => [], 'metadata' => []];
        $this->actingAs($admin)->put(route('admin.plans.update', $this->plan), $payload)->assertSessionHasNoErrors();
        $this->plan->refresh();
        $this->actingAs($this->owner)->get(route('portal.checkout.show', ['packages' => [$this->plan->uuid]]))
            ->assertInertia(fn (Assert $page) => $page->where('plans.0.price', '25000.00')->where('plans.0.description', 'Administrator package description')
                ->where('plans.0.features.0.description', 'Administrator feature description')->has('plans.0.features', count($features)));
        $this->get(route('products.pricing', $this->plan->product))->assertInertia(fn (Assert $page) => $page
            ->where('plans', fn ($plans) => collect($plans)->contains(fn ($p) => $p['id'] === $this->plan->uuid && $p['price'] === '25000.00')));
        $invoice = $this->order('local');
        $this->assertSame('28487.50', $invoice->total);
        $subscription = Subscription::sole();
        $this->assertNotContains('reports', $subscription->metadata['licensed_modules']);
        $this->assertContains('finance', $subscription->metadata['licensed_modules']);
        $this->plan->features()->where('feature_key', 'finance')->update(['enabled' => false]);
        $this->seed(MediFlowPackageSeeder::class);
        $this->assertFalse($this->plan->features()->where('feature_key', 'reports')->exists());
        $this->pay($invoice);
        $configuration = app(PackageCatalog::class)->configuration($subscription->fresh());
        $this->assertContains('finance', array_column($configuration['packages'][0]['features'], 'key'));
        $this->assertSame('25000.00', $this->plan->fresh()->price);
    }

    private function order(string $mode = 'online'): Invoice
    {
        $this->post(route('portal.checkout.store'), ['packages' => [$this->plan->uuid], 'implementation' => ['mode' => $mode, 'domain' => 'clinic.example', 'monthly_price' => '0.01'], 'total' => '0.01'])->assertSessionHasNoErrors()->assertRedirect();

        return Invoice::query()->sole();
    }

    private function pay(Invoice $invoice): void
    {
        app(InvoiceManager::class)->payment($invoice, ['amount' => $invoice->balance, 'method' => 'bank_transfer', 'reference' => 'test-'.str()->uuid(), 'paid_at' => now()], $this->owner);
    }

    public function test_login_preserves_the_selected_plan_checkout(): void
    {
        auth()->logout();
        $url = route('portal.checkout.show', ['packages' => [$this->plan->uuid]]);
        $this->get($url)->assertRedirect(route('login'));
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect($url);
    }

    public function test_registration_preserves_the_selected_plan_checkout(): void
    {
        auth()->logout();
        $url = route('portal.checkout.show', ['packages' => [$this->plan->uuid]]);
        $this->get($url)->assertRedirect(route('login'));
        $this->post('/register', ['name' => 'Buyer', 'account_type' => 'individual', 'phone' => '+2348000000000', 'email' => 'buyer@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect($url);
    }

    public function test_checkout_displays_account_plan_and_admin_hosting_price(): void
    {
        $this->get(route('portal.checkout.show', ['packages' => [$this->plan->uuid]]))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Customer/Checkout')->where('plans.0.uuid', $this->plan->uuid)->where('hosting.monthly_price', '20000.00'));
    }

    public function test_server_prices_hosting_for_the_term_and_rejects_duplicate_orders(): void
    {
        $invoice = $this->order();
        $this->assertSame('269825.00', $invoice->total);
        $this->assertDatabaseCount('client_setup_bundles', 0);
        $this->assertSame(12, (int) $invoice->items()->where('description', 'like', 'Online%')->sole()->quantity);
        $this->post(route('portal.checkout.store'), ['packages' => [$this->plan->uuid], 'implementation' => ['mode' => 'local']])->assertSessionHasErrors('packages');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_local_implementation_has_no_hosting_charge_and_disabled_online_cannot_be_bought(): void
    {
        HostingSetting::find(1)->update(['enabled' => false]);
        $this->post(route('portal.checkout.store'), ['packages' => [$this->plan->uuid], 'implementation' => ['mode' => 'online']])->assertSessionHasErrors('implementation.mode');
        $this->assertSame('11825.00', $this->order('local')->total);
    }

    public function test_paid_setup_is_encrypted_private_and_api_token_is_subscription_restricted(): void
    {
        $invoice = $this->order();
        $subscription = Subscription::sole();
        $this->get(route('portal.services.environment', $subscription))->assertNotFound();
        $this->pay($invoice);
        $bundle = ClientSetupBundle::sole();
        $token = $bundle->secrets['api_token'];
        $this->assertStringNotContainsString($token, DB::table('client_setup_bundles')->value('secrets'));
        $this->get(route('portal.services.environment', $subscription))->assertOk()->assertSee('APP_MODE=client')->assertSee('KERNELBRIDGE_LICENSE_KEY=KBT-');
        $this->get(route('portal.invoices.show', $invoice))->assertInertia(fn (Assert $p) => $p->has('clientSetup.license_key')->has('clientSetup.download_url'));
        app(PaidLicenseService::class)->complete($invoice->fresh(), $this->owner);
        $this->assertDatabaseCount('client_setup_bundles', 1);
        $this->assertDatabaseCount('api_client_credentials', 1);
        $this->withToken($token)->getJson('/api/v1/subscriptions/status?subscription_uuid='.$subscription->uuid)->assertOk();
        $other = Subscription::factory()->create(['product_id' => $subscription->product_id]);
        $this->withToken($token)->getJson('/api/v1/subscriptions/status?subscription_uuid='.$other->uuid)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/mcp/customers/search')->assertForbidden();
        $other->update(['status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $otherKey = app(LicenseService::class)->issue($other)['license_key'];
        $this->withToken($token)->postJson('/api/v1/licenses/activate', ['license_key' => $otherKey, 'device_id' => 'foreign-device', 'machine_fingerprint' => str_repeat('a', 64)])->assertUnprocessable();

        $this->actingAs(User::factory()->create())->get(route('portal.services.environment', $subscription))->assertForbidden();
    }

    public function test_admin_controls_hosting_price_and_customer_cannot_edit_it(): void
    {
        $this->put(route('admin.hosting.update'), ['enabled' => true, 'currency' => 'NGN', 'monthly_price' => '25000.00'])->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put(route('admin.hosting.update'), ['enabled' => true, 'currency' => 'NGN', 'monthly_price' => '25000.00'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner);
        $this->assertSame('334325.00', $this->order()->total);
    }

    public function test_hosting_renewal_retains_contracted_price_after_admin_changes(): void
    {
        $invoice = $this->order();
        $this->pay($invoice);
        $subscription = Subscription::sole();
        HostingSetting::find(1)->update(['monthly_price' => '25000.00']);
        $this->travelTo($subscription->ends_at->copy()->addSecond());
        $renewal = app(PackageChangeService::class)->change($subscription, [$this->plan->uuid], $this->owner, false, true);
        $this->assertSame('268750.00', $renewal->total);
    }

    public function test_flutterwave_verified_payment_generates_code_and_client_settings_once(): void
    {
        config(['services.flutterwave.secret_key' => 'test', 'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3']);
        $invoice = $this->order();
        Http::fake(['https://api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay']])]);
        $this->post(route('portal.invoices.pay-online', $invoice))->assertRedirect('https://checkout.flutterwave.test/pay');
        Http::assertSent(fn ($r) => $r['amount'] === '269825.00');
        $reference = Payment::sole()->transaction_reference;
        Http::fake(['https://api.flutterwave.com/v3/transactions/123/verify' => Http::response(['status' => 'success', 'data' => ['id' => 123, 'status' => 'successful', 'amount' => '269825.00', 'currency' => 'NGN', 'tx_ref' => $reference, 'payment_type' => 'card']])]);
        $url = route('payments.flutterwave.callback', ['status' => 'successful', 'tx_ref' => $reference, 'transaction_id' => '123']);
        $this->get($url)->assertRedirect(route('portal.invoices.show', $invoice));
        $this->get($url)->assertRedirect(route('portal.invoices.show', $invoice));
        $this->assertDatabaseCount('software_licenses', 1);
        $this->assertDatabaseCount('client_setup_bundles', 1);
    }
}
