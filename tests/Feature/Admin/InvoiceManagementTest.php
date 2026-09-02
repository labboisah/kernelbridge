<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_backend_calculates_invoice_totals_and_number(): void
    {
        $payload = $this->payload();
        $payload += ['subtotal' => 1, 'total' => 1, 'balance' => 1];
        $this->actingAs($this->admin())->post(route('admin.invoices.store'), $payload)->assertRedirect();
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('2050.00', $invoice->total);
        $this->assertSame('2050.00', $invoice->balance);
        $this->assertSame('0.00', $invoice->amount_paid);
        $this->assertSame('KBT-INV-'.now()->year.'-000001', $invoice->invoice_number);
        $this->assertCount(1, $invoice->items);
    }

    public function test_issue_partial_and_full_payment_transitions_are_safe(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.invoices.store'), $this->payload());
        $invoice = Invoice::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.invoices.payments.store', $invoice), $this->payment('100.00', 'EARLY'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.invoices.issue', $invoice))->assertRedirect();
        Notification::assertSentOnDemand(InvoiceIssued::class);
        $this->actingAs($admin)->post(route('admin.invoices.payments.store', $invoice), $this->payment('500.00', 'PARTIAL'))->assertRedirect();
        $this->assertSame('partially_paid', $invoice->fresh()->status->value);
        $this->assertSame('1550.00', $invoice->fresh()->balance);
        $this->actingAs($admin)->post(route('admin.invoices.payments.store', $invoice), $this->payment('1550.00', 'FINAL'))->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status->value);
        $this->assertSame('0.00', $invoice->fresh()->balance);
        $this->actingAs($admin)->post(route('admin.invoices.payments.store', $invoice), $this->payment('1.00', 'EXTRA'))->assertForbidden();
    }

    public function test_overpayment_is_rejected_without_creating_payment(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.invoices.store'), $this->payload());
        $invoice = Invoice::query()->firstOrFail();
        $invoice->update(['status' => 'issued']);
        $this->actingAs($admin)->post(route('admin.invoices.payments.store', $invoice), $this->payment('3000.00', 'TOO-MUCH'))->assertSessionHasErrors('amount');
        $this->assertCount(0, $invoice->payments);
    }

    public function test_command_marks_only_outstanding_past_due_invoices_overdue(): void
    {
        $admin = $this->admin();
        $payload = $this->payload();
        $payload['issue_date'] = now()->subDays(10)->toDateString();
        $payload['due_date'] = now()->subDay()->toDateString();
        $this->actingAs($admin)->post(route('admin.invoices.store'), $payload);
        $invoice = Invoice::query()->firstOrFail();
        $invoice->update(['status' => 'issued']);
        $this->artisan('invoices:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $invoice->fresh()->status->value);
    }

    public function test_customer_invoice_requires_a_valid_signed_link(): void
    {
        $this->actingAs($this->admin())->post(route('admin.invoices.store'), $this->payload());
        $invoice = Invoice::query()->firstOrFail();
        $this->get(route('invoices.customer.show', $invoice))->assertForbidden();
        $this->get(URL::temporarySignedRoute('invoices.customer.show', now()->addHour(), ['invoice' => $invoice]))->assertOk();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);

        return $user;
    }

    private function payload(): array
    {
        $customer = Customer::query()->firstOrCreate(['email' => 'billing@example.com'], ['customer_number' => 'KBT-CUS-000001', 'type' => 'organization', 'name' => 'ABC Academy', 'status' => 'active']);

        return ['customer_id' => $customer->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(14)->toDateString(), 'currency' => 'NGN', 'notes' => 'Payment due.', 'items' => [['product_id' => Product::query()->where('code', 'ALIGNEX')->value('id'), 'plan_id' => null, 'description' => 'Platform license', 'quantity' => 2, 'unit_price' => 1000, 'discount' => 100, 'tax' => 150]]];
    }

    private function payment(string $amount, string $reference): array
    {
        return ['amount' => $amount, 'reference' => $reference, 'method' => 'bank_transfer', 'paid_at' => now()->toDateString()];
    }
}
