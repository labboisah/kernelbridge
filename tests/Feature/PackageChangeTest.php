<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\CustomerCreditService;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Licensing\Services\PackageChangeService;
use App\Domain\Licensing\Services\PackagePurchaseService;
use App\Domain\Licensing\Services\PaidLicenseService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Database\Seeders\MediFlowPackageSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PackageChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Plan $pharmacy;

    private Plan $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->seed([ProductSeeder::class, MediFlowPackageSeeder::class]);
        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->customer->users()->attach($this->user, ['is_primary' => true]);
        $this->pharmacy = Plan::where('slug', 'pharmacy')->firstOrFail();
        $this->clinic = Plan::where('slug', 'general_clinic')->firstOrFail();
        $this->pharmacy->update(['price' => 100, 'setup_fee' => 0, 'billing_cycle' => 'monthly']);
        $this->clinic->update(['price' => 200, 'setup_fee' => 0, 'billing_cycle' => 'monthly']);
        $this->actingAs($this->user);
    }

    public function test_additions_are_prorated_and_locked_until_full_settlement(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid]);
        $this->postJson(route('portal.licenses.preview', $subscription), ['packages' => [$this->pharmacy->uuid, $this->clinic->uuid]])
            ->assertOk()->assertJsonPath('charge', '107.50')->assertJsonPath('vat', '7.50')->assertJsonPath('credit', '0.00');
        $this->assertDatabaseCount('subscription_package_orders', 0);
        $this->post(route('portal.licenses.change', $subscription), ['packages' => [$this->pharmacy->uuid, $this->clinic->uuid], 'use_credit' => false, 'charge' => '0.01'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = SubscriptionPackageOrder::query()->sole();
        $this->assertSame('107.50', $order->invoice->total);
        $this->assertFalse($subscription->entitlements()->whereNull('effective_until')->where('feature_key', 'doctor')->exists());
        $this->pay($order->invoice, '25.00');
        $this->assertSame(['pharmacy'], $subscription->fresh()->metadata['selected_packages']);
        $this->pay($order->invoice->fresh(), '82.50');
        $this->assertSame(['pharmacy', 'general_clinic'], $subscription->fresh()->metadata['selected_packages']);
        $this->assertTrue($subscription->entitlements()->whereNull('effective_until')->where('feature_key', 'doctor')->exists());
        $this->assertSame('applied', $order->fresh()->status);
        $revision = $subscription->fresh()->metadata['configuration_revision'];
        app(PaidLicenseService::class)->complete($order->invoice->fresh(), $this->user);
        $this->assertSame($revision, $subscription->fresh()->metadata['configuration_revision']);
    }

    public function test_removal_generates_credit_once_and_preserves_shared_features(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid, $this->clinic->uuid]);
        $this->post(route('portal.licenses.change', $subscription), ['packages' => [$this->clinic->uuid], 'use_credit' => false])->assertSessionHasNoErrors();
        $this->assertSame(5000, app(CustomerCreditService::class)->balance($this->customer->id, 'NGN'));
        $this->assertSame(['general_clinic'], $subscription->fresh()->metadata['selected_packages']);
        $this->assertFalse($subscription->entitlements()->whereNull('effective_until')->where('feature_key', 'pharmacy')->exists());
        $this->assertTrue($subscription->entitlements()->whereNull('effective_until')->where('feature_key', 'billing')->exists());
        $this->post(route('portal.licenses.change', $subscription), ['packages' => [$this->clinic->uuid], 'use_credit' => false])->assertSessionHasErrors('packages');
        $this->assertDatabaseCount('customer_credit_entries', 1);
    }

    public function test_credit_settles_the_next_renewal_and_keeps_the_same_license(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid, $this->clinic->uuid]);
        $licenseId = $subscription->license->id;
        app(PackageChangeService::class)->change($subscription, [$this->clinic->uuid], $this->user, false);
        $this->travelTo($subscription->ends_at->copy()->addSecond());
        $this->post(route('portal.licenses.renew', $subscription), ['use_credit' => true])->assertSessionHasNoErrors();
        $order = SubscriptionPackageOrder::query()->sole();
        $this->assertSame('215.00', $order->invoice->total);
        $this->assertSame('165.00', $order->invoice->balance);
        $this->assertSame(0, app(CustomerCreditService::class)->balance($this->customer->id, 'NGN'));
        $this->pay($order->invoice, '165.00');
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
        $this->assertTrue($subscription->license->fresh()->expires_at->isFuture());
        $this->assertSame($licenseId, $subscription->fresh()->license->id);
        $this->assertSame('applied', $order->fresh()->status);
    }

    public function test_credit_can_fully_settle_an_addition_without_creating_a_fake_cash_payment(): void
    {
        $this->pharmacy->update(['price' => 1000]);
        $subscription = $this->subscription([$this->pharmacy->uuid]);
        $invoice = app(PackageChangeService::class)->change($subscription, [$this->clinic->uuid], $this->user, true);
        $this->assertSame('paid', $invoice->status->value);
        $this->assertSame('0.00', $invoice->balance);
        $this->assertSame(39250, app(CustomerCreditService::class)->balance($this->customer->id, 'NGN'));
        $this->assertSame(['general_clinic'], $subscription->fresh()->metadata['selected_packages']);
        $this->assertSame('account_credit', $invoice->payments()->sole()->method);
        $this->post(route('portal.invoices.credit', $invoice))->assertSessionHasNoErrors();
        $this->assertSame(39250, app(CustomerCreditService::class)->balance($this->customer->id, 'NGN'));
    }

    public function test_pending_changes_block_duplicates_and_can_be_cancelled_without_unlocking_features(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid]);
        $invoice = app(PackageChangeService::class)->change($subscription, [$this->pharmacy->uuid, $this->clinic->uuid], $this->user, false);
        $this->post(route('portal.licenses.change', $subscription), ['packages' => [$this->clinic->uuid], 'use_credit' => false])->assertSessionHasErrors('packages');
        $order = SubscriptionPackageOrder::query()->sole();
        $this->post(route('portal.licenses.cancel-order', $order->uuid))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $invoice->fresh()->status->value);
        $this->assertSame(['pharmacy'], $subscription->fresh()->metadata['selected_packages']);
    }

    public function test_late_addition_payment_is_credited_instead_of_unlocking_expired_access(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid]);
        $invoice = app(PackageChangeService::class)->change($subscription, [$this->pharmacy->uuid, $this->clinic->uuid], $this->user, false);
        $this->travelTo($subscription->ends_at->copy()->addDay());
        $this->pay($invoice, $invoice->total);
        $this->assertSame('credited', SubscriptionPackageOrder::query()->sole()->status);
        $this->assertSame(10750, app(CustomerCreditService::class)->balance($this->customer->id, 'NGN'));
        $this->assertSame(['pharmacy'], $subscription->fresh()->metadata['selected_packages']);
    }

    public function test_another_customer_cannot_quote_change_or_spend_credit(): void
    {
        $subscription = $this->subscription([$this->pharmacy->uuid]);
        $this->actingAs(User::factory()->create());
        $this->postJson(route('portal.licenses.preview', $subscription), ['packages' => []])->assertForbidden();
        $this->post(route('portal.licenses.change', $subscription), ['packages' => [], 'use_credit' => true])->assertForbidden();
        $this->post(route('portal.invoices.credit', $subscription->invoice))->assertForbidden();
    }

    private function subscription(array $uuids): Subscription
    {
        $invoice = app(PackagePurchaseService::class)->purchase($this->customer, $uuids, $this->user);
        $this->pay($invoice, $invoice->total);
        $subscription = Subscription::query()->sole();
        $subscription->update(['starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15)]);
        $subscription->license()->update(['expires_at' => $subscription->ends_at]);

        return $subscription->fresh();
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['customer_id' => $invoice->customer_id, 'amount' => $amount, 'currency' => 'NGN',
            'payment_method' => 'bank_transfer', 'transaction_reference' => (string) str()->uuid(), 'payment_date' => today()], $this->user);
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $amount]], $this->user);
    }
}
