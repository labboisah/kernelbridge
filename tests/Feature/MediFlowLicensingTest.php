<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Services\PaidLicenseService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\MediFlowPackageSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MediFlowLicensingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Plan $pharmacy;

    private Plan $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ProductSeeder::class, MediFlowPackageSeeder::class]);
        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->customer->users()->attach($this->user, ['is_primary' => true]);
        $this->pharmacy = Plan::query()->where('slug', 'pharmacy')->firstOrFail();
        $this->clinic = Plan::query()->where('slug', 'general_clinic')->firstOrFail();
        $this->pharmacy->update(['price' => '10000.25', 'setup_fee' => '1000.00']);
        $this->clinic->update(['price' => '20000.50']);
        $this->actingAs($this->user);
    }

    public function test_catalogue_uses_canonical_packages_and_preserves_configured_prices(): void
    {
        $this->seed(MediFlowPackageSeeder::class);
        $this->assertSame('10000.25', $this->pharmacy->fresh()->price);
        $this->get(route('portal.licenses.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Licenses/Index')->has('packages', 7)->has('orders', 0)->has('licenses', 0));
        $this->assertDatabaseHas('plans', ['slug' => 'specialist', 'name' => 'Care Network', 'price' => null]);
    }

    public function test_checkout_uses_server_prices_and_does_not_issue_an_unpaid_license(): void
    {
        $invoice = $this->purchase();
        $this->assertSame('31000.75', $invoice->total);
        $this->assertSame('issued', $invoice->status->value);
        $this->assertCount(3, $invoice->items);
        $subscription = Subscription::query()->sole();
        $this->assertSame('30000.75', $subscription->price);
        $this->assertSame('pending', $subscription->status->value);
        $this->assertSame('general_clinic', $subscription->metadata['primary_package']);
        $this->assertContains('pharmacy', $subscription->metadata['licensed_modules']);
        $this->assertContains('doctor', $subscription->metadata['licensed_modules']);
        $this->assertDatabaseCount('software_licenses', 0);
        $this->post(route('portal.licenses.purchase'), ['packages' => [$this->pharmacy->uuid]])->assertSessionHasErrors('packages');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_unpriced_duplicate_mixed_currency_and_private_packages_are_rejected(): void
    {
        $unpriced = Plan::query()->where('slug', 'hospital')->firstOrFail();
        $this->post(route('portal.licenses.purchase'), ['packages' => [$unpriced->uuid]])->assertSessionHasErrors('packages');
        $this->post(route('portal.licenses.purchase'), ['packages' => [$this->pharmacy->uuid, $this->pharmacy->uuid]])->assertSessionHasErrors('packages.0');
        $this->clinic->update(['currency' => 'USD']);
        $this->post(route('portal.licenses.purchase'), ['packages' => [$this->pharmacy->uuid, $this->clinic->uuid]])->assertSessionHasErrors('packages');
        $this->clinic->update(['currency' => 'NGN', 'billing_cycle' => 'monthly']);
        $this->post(route('portal.licenses.purchase'), ['packages' => [$this->pharmacy->uuid, $this->clinic->uuid]])->assertSessionHasErrors('packages');
        $this->pharmacy->update(['is_public' => false]);
        $this->post(route('portal.licenses.purchase'), ['packages' => [$this->pharmacy->uuid]])->assertSessionHasErrors('packages');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_partial_payment_waits_and_full_verified_payment_issues_exactly_one_license(): void
    {
        $invoice = $this->purchase();
        $this->pay($invoice, '10000.00', 'part-one');
        $this->assertDatabaseCount('software_licenses', 0);
        $this->assertSame('pending', Subscription::query()->sole()->status->value);
        $this->pay($invoice->fresh(), '21000.75', 'part-two');
        $subscription = Subscription::query()->sole();
        $this->assertSame('active', $subscription->status->value);
        $this->assertDatabaseCount('software_licenses', 1);
        $keys = $subscription->entitlements()->whereNull('effective_until')->pluck('feature_key')->all();
        $this->assertContains('doctor', $keys);
        $this->assertContains('pharmacy', $keys);
        $this->assertNotContains('maternity', $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        app(PaidLicenseService::class)->complete($invoice->fresh(), $this->user);
        $this->assertDatabaseCount('software_licenses', 1);
    }

    public function test_verified_online_payment_issues_one_code_and_callback_retries_are_safe(): void
    {
        config(['services.flutterwave.secret_key' => 'test-secret', 'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3']);
        $invoice = $this->purchase();
        Http::fake([
            'https://api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/package']]),
        ]);
        $this->post(route('portal.invoices.pay-online', $invoice))->assertRedirect('https://checkout.flutterwave.test/package');
        $reference = Payment::query()->sole()->transaction_reference;
        Http::fake([
            'https://api.flutterwave.com/v3/transactions/101/verify' => Http::response(['status' => 'success', 'data' => [
                'id' => 101, 'status' => 'successful', 'amount' => $invoice->total,
                'currency' => 'NGN', 'tx_ref' => $reference, 'payment_type' => 'card',
            ]]),
        ]);
        $url = route('payments.flutterwave.callback', ['status' => 'successful', 'tx_ref' => $reference, 'transaction_id' => '101']);
        $this->get($url)->assertRedirect(route('portal.invoices.show', $invoice));
        $this->get($url)->assertRedirect(route('portal.invoices.show', $invoice));
        $this->assertDatabaseCount('software_licenses', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_finance_invoice_payment_also_fulfils_the_package_order(): void
    {
        $invoice = $this->purchase();
        app(InvoiceManager::class)->payment($invoice, ['amount' => $invoice->total, 'reference' => 'cash-confirmed', 'method' => 'cash', 'paid_at' => now()], $this->user);
        $this->assertDatabaseCount('software_licenses', 1);
    }

    public function test_activation_records_device_and_rejects_copy_and_transfer_even_after_deactivation(): void
    {
        $invoice = $this->purchase();
        $this->pay($invoice, $invoice->total, 'paid');
        $license = SoftwareLicense::query()->sole();
        $credentials = app(ApiCredentialService::class);
        $client = $credentials->createClient($this->pharmacy->product, 'MediFlow');
        $token = $credentials->issue($client, 'test')['token'];
        $payload = [
            'license_key' => app(LicenseService::class)->key($license), 'device_id' => 'installation-one',
            'machine_fingerprint' => str_repeat('a', 64), 'device_name' => 'Clinic computer',
            'device_info' => ['hostname' => 'CLINIC-01', 'os' => 'Windows', 'architecture' => 'AMD64'],
        ];
        $this->withToken($token)->postJson('/api/v1/licenses/activate', array_diff_key($payload, ['machine_fingerprint' => true]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', $payload)->assertCreated();
        $this->assertDatabaseCount('license_activations', 1);
        $activation = $license->activations()->sole();
        $this->assertSame('CLINIC-01', $activation->metadata['device_info']['hostname']);
        $this->assertSame('127.0.0.1', $activation->ip_address);
        $this->assertNotSame(str_repeat('a', 64), $activation->metadata['machine_hash']);
        $copy = [...$payload, 'machine_fingerprint' => str_repeat('b', 64)];
        $this->withToken($token)->postJson('/api/v1/licenses/verify', $copy)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', $copy)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/deactivate', $copy)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', [...$copy, 'device_id' => 'installation-two'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/deactivate', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', [...$copy, 'device_id' => 'installation-two'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/licenses/activate', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/licenses/verify', $payload)->assertOk();
    }

    public function test_refund_blocks_previously_issued_package_license(): void
    {
        $invoice = $this->purchase();
        $payment = $this->pay($invoice, $invoice->total, 'refundable');
        app(PaymentManager::class)->refund($payment, $this->user, 'Order refunded');
        $this->assertSame('suspended', Subscription::query()->sole()->status->value);
    }

    public function test_other_customer_cannot_access_order_or_key(): void
    {
        $invoice = $this->purchase();
        $this->pay($invoice, $invoice->total, 'private');
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('portal.invoices.show', $invoice))->assertForbidden();
        $this->get(route('portal.licenses.index'))->assertInertia(fn (Assert $page) => $page->has('licenses', 0)->has('orders', 0));
    }

    private function purchase(): Invoice
    {
        $this->post(route('portal.licenses.purchase'), [
            'packages' => [$this->clinic->uuid, $this->pharmacy->uuid],
            'price' => '0.01', 'customer_id' => 999, 'licensed_modules' => ['*'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Invoice::query()->sole();
    }

    private function pay(Invoice $invoice, string $amount, string $reference): Payment
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create([
            'customer_id' => $invoice->customer_id, 'amount' => $amount, 'currency' => 'NGN',
            'payment_method' => 'bank_transfer', 'transaction_reference' => $reference, 'payment_date' => today(),
        ], $this->user);

        return $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $amount]], $this->user);
    }
}
