<?php

namespace Tests\Feature\Admin;

use App\Domain\Subscriptions\Services\EntitlementService;
use App\Domain\Subscriptions\Services\RenewalNoticeService;
use App\Domain\Subscriptions\Services\SubscriptionProrationService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Enums\BillingCycle;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionRenewalNotice;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SubscriptionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, PlanSeeder::class]);
    }

    public function test_subscription_activates_only_after_matching_invoice_is_paid_and_calculates_term(): void
    {
        Carbon::setTestNow('2026-01-31 10:00:00');
        $service = app(SubscriptionService::class);
        $actor = $this->actor();
        $plan = $this->basic();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $actor, 'issued');
        $subscription = $service->create($customer, $plan, $invoice, $actor);
        $this->assertSame('pending', $subscription->status->value);
        try {
            $service->activate($subscription, $invoice, $actor);
            $this->fail('Unpaid invoice activated a subscription.');
        } catch (ValidationException) {
            $this->assertSame('pending', $subscription->fresh()->status->value);
        }
        $invoice->update(['status' => 'paid', 'amount_paid' => $invoice->total, 'balance' => 0]);
        $subscription = $service->activate($subscription, $invoice->fresh(), $actor);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('2027-01-31', $subscription->ends_at->toDateString());
        $this->assertSame($plan->price, $subscription->price);
        $this->assertTrue(app(EntitlementService::class)->contains($subscription, 'max_users'));
        $this->assertSame(5, app(EntitlementService::class)->limit($subscription, 'max_users'));
        Carbon::setTestNow();
    }

    public function test_proration_calculates_remaining_term_credit_and_debit(): void
    {
        $old = new Subscription(['starts_at' => '2026-01-01', 'ends_at' => '2026-02-01', 'price' => 100, 'billing_cycle' => BillingCycle::Monthly]);
        $new = new Plan(['price' => 200]);
        $result = app(SubscriptionProrationService::class)->calculate($old, $new, Carbon::parse('2026-01-16'));
        $this->assertSame('51.61', $result['credit']);
        $this->assertSame('103.23', $result['debit']);
        $this->assertSame('51.62', $result['net_amount']);
    }

    public function test_plan_change_snapshots_price_entitlements_and_immutable_history(): void
    {
        Carbon::setTestNow('2026-01-01');
        $service = app(SubscriptionService::class);
        $actor = $this->actor();
        $customer = $this->customer();
        $basic = $this->basic();
        $professional = Plan::query()->where('slug', 'professional')->firstOrFail();
        $invoice = $this->invoice($customer, $actor, 'paid');
        $subscription = $service->activate($service->create($customer, $basic, $invoice, $actor), $invoice, $actor);
        Carbon::setTestNow('2026-07-02');
        $result = $service->changePlan($subscription, $professional, $actor);
        $subscription = $result['subscription'];
        $this->assertSame($professional->price, $subscription->price);
        $this->assertGreaterThan(0, (float) $result['credit']);
        $this->assertGreaterThan((float) $result['credit'], (float) $result['debit']);
        $this->assertSame(25, app(EntitlementService::class)->limit($subscription, 'max_users'));
        $change = $subscription->changes()->where('change_type', 'upgraded')->firstOrFail();
        $this->assertSame($basic->price, $change->before['price']);
        $professional->update(['price' => 999999]);
        $this->assertSame('180000.00', $subscription->fresh()->price);
        $this->expectException(\LogicException::class);
        $change->update(['reason' => 'tampered']);
        Carbon::setTestNow();
    }

    public function test_lifecycle_enters_grace_then_expires_and_records_both_changes(): void
    {
        Carbon::setTestNow('2026-01-01');
        $service = app(SubscriptionService::class);
        $actor = $this->actor();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $actor, 'paid');
        $subscription = $service->activate($service->create($customer, $this->basic(), $invoice, $actor), $invoice, $actor);
        $subscription->update(['ends_at' => now()->subMinute()]);
        $service->processLifecycle();
        $this->assertSame('grace', $subscription->fresh()->status->value);
        Carbon::setTestNow(now()->addDays(8));
        $service->processLifecycle();
        $this->assertSame('expired', $subscription->fresh()->status->value);
        $this->assertSame(['expired', 'grace_entered'], $subscription->changes()->whereIn('change_type', ['grace_entered', 'expired'])->pluck('change_type')->all());
        Carbon::setTestNow();
    }

    public function test_renewal_notice_is_queued_once(): void
    {
        Notification::fake();
        $actor = $this->actor();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $actor, 'paid');
        $subscription = app(SubscriptionService::class)->activate(app(SubscriptionService::class)->create($customer, $this->basic(), $invoice, $actor), $invoice, $actor);
        $subscription->update(['ends_at' => now()->addDays(10)]);
        $this->assertSame(1, app(RenewalNoticeService::class)->sendDueNotices());
        $this->assertSame(0, app(RenewalNoticeService::class)->sendDueNotices());
        Notification::assertSentOnDemand(SubscriptionRenewalNotice::class);
    }

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);

        return $user;
    }

    private function basic(): Plan
    {
        return Plan::query()->where('slug', 'basic')->firstOrFail();
    }

    private function customer(): Customer
    {
        return Customer::query()->firstOrCreate(['email' => 'subscription@example.com'], ['customer_number' => 'KBT-CUS-000001', 'type' => 'organization', 'name' => 'ABC Academy', 'status' => 'active']);
    }

    private function invoice(Customer $customer, User $actor, string $status): Invoice
    {
        $invoice = Invoice::query()->create(['invoice_number' => 'KBT-INV-'.now()->format('YmdHis').'-'.str()->random(5), 'customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => now()->addWeek(), 'subtotal' => 75000, 'discount' => 0, 'tax' => 0, 'total' => 75000, 'amount_paid' => $status === 'paid' ? 75000 : 0, 'balance' => $status === 'paid' ? 0 : 75000, 'currency' => 'NGN', 'status' => $status, 'created_by' => $actor->id]);
        $plan = $this->basic();
        $invoice->items()->create(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'product' => $plan->product->name, 'plan' => $plan->name, 'description' => 'Subscription term', 'quantity' => 1, 'unit_price' => 75000, 'discount' => 0, 'tax' => 0, 'total' => 75000, 'sort_order' => 0]);

        return $invoice;
    }
}
