<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FinancialAuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_manual_payment_is_pending_until_verified_and_then_updates_invoice(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice();
        $this->actingAs($finance)->post(route('admin.payments.store'), $this->payload())->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertNull($payment->verified_by);
        $this->assertSame('KBT-PAY-'.now()->year.'-000001', $payment->payment_number);
        $this->actingAs($finance)->post(route('admin.payments.verify', $payment), ['allocations' => [['invoice_id' => $invoice->id, 'amount' => '400.00']]])->assertRedirect();
        $payment->refresh();
        $this->assertSame('verified', $payment->status->value);
        $this->assertSame($finance->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('partially_paid', $invoice->fresh()->status->value);
        $this->assertSame('600.00', $invoice->fresh()->balance);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('financial_audit_logs', ['event' => 'payment.verified']);
    }

    public function test_allocation_cannot_exceed_payment_or_invoice_balance(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice();
        $this->actingAs($finance)->post(route('admin.payments.store'), $this->payload());
        $payment = Payment::query()->firstOrFail();
        $this->actingAs($finance)->post(route('admin.payments.verify', $payment), ['allocations' => [['invoice_id' => $invoice->id, 'amount' => '500.00']]])->assertSessionHasErrors('allocations');
        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertSame('1000.00', $invoice->fresh()->balance);
    }

    public function test_verified_payment_cannot_be_deleted_and_refund_is_a_new_record(): void
    {
        $finance = $this->finance();
        $invoice = $this->invoice();
        $this->actingAs($finance)->post(route('admin.payments.store'), $this->payload());
        $payment = Payment::query()->firstOrFail();
        $this->actingAs($finance)->post(route('admin.payments.verify', $payment), ['allocations' => [['invoice_id' => $invoice->id, 'amount' => '400.00']]]);
        try {
            $payment->fresh()->delete();
            $this->fail('Verified payment was deleted.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }
        $this->actingAs($finance)->post(route('admin.payments.refund', $payment->fresh()), ['reason' => 'Bank reversal'])->assertRedirect();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['parent_payment_id' => $payment->id, 'status' => 'refunded']);
        $this->assertSame('1000.00', $invoice->fresh()->balance);
    }

    public function test_financial_audit_logs_are_immutable(): void
    {
        $this->actingAs($this->finance())->post(route('admin.payments.store'), $this->payload());
        $log = FinancialAuditLog::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $log->update(['event' => 'tampered']);
    }

    private function finance(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Finance->value);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::query()->firstOrCreate(['email' => 'accounts@example.com'], ['customer_number' => 'KBT-CUS-000001', 'type' => 'organization', 'name' => 'ABC Academy', 'status' => 'active']);
    }

    private function invoice(): Invoice
    {
        $customer = $this->customer();

        return Invoice::query()->create(['invoice_number' => 'KBT-INV-'.now()->year.'-000001', 'customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => now()->addWeek(), 'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000, 'amount_paid' => 0, 'balance' => 1000, 'currency' => 'NGN', 'status' => 'issued', 'created_by' => $this->finance()->id]);
    }

    private function payload(): array
    {
        return ['customer_id' => $this->customer()->id, 'amount' => '400.00', 'currency' => 'NGN', 'payment_method' => 'bank_transfer', 'transaction_reference' => 'BANK-REF-001', 'payment_date' => today()->toDateString(), 'notes' => 'Transfer evidence received.'];
    }
}
