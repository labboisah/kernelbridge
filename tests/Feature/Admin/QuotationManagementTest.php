<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\QuotationSent;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class QuotationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_backend_calculates_totals_and_ignores_browser_totals(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $payload = $this->payload($customer);
        $payload += ['subtotal' => '1.00', 'total' => '1.00'];
        $this->actingAs($admin)->post(route('admin.quotations.store'), $payload)->assertRedirect();
        $q = Quotation::query()->firstOrFail();
        $this->assertSame('2000.00', $q->subtotal);
        $this->assertSame('100.00', $q->discount);
        $this->assertSame('150.00', $q->tax);
        $this->assertSame('2050.00', $q->total);
        $this->assertSame('KBT-QTN-'.now()->year.'-000001', $q->quotation_number);
        $this->assertSame('2050.00', $q->items->first()->total);
    }

    public function test_discount_cannot_exceed_gross_amount(): void
    {
        $p = $this->payload($this->customer());
        $p['items'][0]['discount'] = 3000;
        $this->actingAs($this->admin())->post(route('admin.quotations.store'), $p)->assertSessionHasErrors('items.0.discount');
        $this->assertSame(0, Quotation::query()->count());
    }

    public function test_send_accept_and_convert_status_transitions_are_enforced(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $customer = $this->customer();
        $this->actingAs($admin)->post(route('admin.quotations.store'), $this->payload($customer));
        $q = Quotation::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.quotations.send', $q))->assertRedirect();
        $this->assertSame('sent', $q->fresh()->status->value);
        Notification::assertSentOnDemand(QuotationSent::class);
        $accept = URL::temporarySignedRoute('quotations.customer.accept', now()->addHour(), ['quotation' => $q]);
        $this->post($accept)->assertRedirect();
        $this->assertSame('accepted', $q->fresh()->status->value);
        $this->post($accept)->assertSessionHasErrors('status');
        $this->actingAs($admin)->post(route('admin.quotations.convert', $q))->assertRedirect();
        $this->assertSame('converted', $q->fresh()->status->value);
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('2042.50', $invoice->total);
        $this->assertSame('7.50', $invoice->vat_rate);
        $this->assertSame('142.50', $invoice->tax);
        $this->assertSame('KBT-INV-'.now()->year.'-000001', $invoice->invoice_number);
        $this->assertCount(1, $invoice->items);
        $this->actingAs($admin)->post(route('admin.quotations.convert', $q->fresh()))->assertForbidden();
    }

    public function test_customer_can_reject_sent_quotation_using_signed_link(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.quotations.store'), $this->payload($this->customer()));
        $q = Quotation::query()->firstOrFail();
        $q->update(['status' => 'sent']);
        $url = URL::temporarySignedRoute('quotations.customer.reject', now()->addHour(), ['quotation' => $q]);
        $this->post($url)->assertRedirect();
        $this->assertSame('rejected', $q->fresh()->status->value);
        $this->get(route('quotations.customer.show', $q))->assertForbidden();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole(UserRole::Admin->value);

        return $u;
    }

    private function customer(): Customer
    {
        return Customer::query()->create(['customer_number' => 'KBT-CUS-000001', 'type' => 'organization', 'name' => 'ABC Academy', 'email' => 'billing@example.com', 'status' => 'active']);
    }

    private function payload(Customer $c): array
    {
        return ['customer_id' => $c->id, 'issue_date' => now()->toDateString(), 'expiry_date' => now()->addDays(14)->toDateString(), 'currency' => 'NGN', 'notes' => 'Thank you.', 'terms' => 'Payment due as agreed.', 'items' => [['product_id' => Product::query()->where('code', 'ALIGNEX')->value('id'), 'plan_id' => null, 'description' => 'Annual platform license', 'quantity' => 2, 'unit_price' => 1000, 'discount' => 100, 'tax' => 150, 'total' => 1]]];
    }
}
