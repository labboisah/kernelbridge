<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceVatAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function data(): array
    {
        return ['customer_id' => Customer::factory()->create()->id, 'currency' => 'NGN', 'issue_date' => today(), 'due_date' => today()->addWeek(),
            'items' => [['product_id' => null, 'plan_id' => null, 'description' => 'Discounted subscription', 'quantity' => 2, 'unit_price' => '10000.00', 'discount' => '1000.00', 'tax' => 0]]];
    }

    public function test_server_adds_vat_after_discount_and_ignores_forged_rate_and_totals(): void
    {
        $data = $this->data() + ['vat_rate' => 0, 'total' => 1, 'tax' => 0];
        $invoice = app(InvoiceManager::class)->create($data, User::factory()->create());
        $this->assertSame('20000.00', $invoice->subtotal);
        $this->assertSame('1425.00', $invoice->tax);
        $this->assertSame('20425.00', $invoice->total);
        $this->assertSame($invoice->total, $invoice->balance);
        $this->assertSame('7.50', $invoice->vat_rate);
        $this->assertSame('1425.00', $invoice->items()->first()->tax);
    }

    public function test_draft_edit_recalculates_vat_without_double_taxing(): void
    {
        $actor = User::factory()->create();
        $data = $this->data();
        $manager = app(InvoiceManager::class);
        $invoice = $manager->create($data, $actor);
        $data['items'][0]['tax'] = 1425;
        $data['vat_rate'] = 0;
        $manager->update($invoice, $data, $actor);
        $this->assertSame('20425.00', $invoice->fresh()->total);
        $this->assertSame('7.50', $invoice->fresh()->vat_rate);
        $data['items'][0]['unit_price'] = '20000.00';
        $manager->update($invoice, $data, $actor);
        $this->assertSame('2925.00', $invoice->fresh()->tax);
        $this->assertSame('41925.00', $invoice->fresh()->total);
    }

    public function test_rounding_and_zero_taxable_lines(): void
    {
        $data = $this->data();
        $data['items'][0] = ['product_id' => null, 'plan_id' => null, 'description' => 'Small item', 'quantity' => 1, 'unit_price' => '0.10', 'discount' => 0, 'tax' => 99];
        $manager = app(InvoiceManager::class);
        $actor = User::factory()->create();
        $this->assertSame('0.11', $manager->create($data, $actor)->total);
        $data['items'][0]['discount'] = '0.10';
        $this->assertSame('0.00', $manager->create($data, $actor)->total);
    }

    public function test_legacy_invoices_keep_original_tax_basis(): void
    {
        $invoice = Invoice::factory()->create(['vat_rate' => null, 'status' => 'draft']);
        $data = $this->data();
        $data['items'][0]['tax'] = '500.00';
        app(InvoiceManager::class)->update($invoice, $data, User::factory()->create());
        $this->assertNull($invoice->fresh()->vat_rate);
        $this->assertSame('19500.00', $invoice->fresh()->total);
    }

    public function test_requested_roles_appear_in_access_control_without_default_staff_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/access-control')->assertOk()->assertInertia(function (Assert $p) {
            $p->where('roles', fn ($roles) => collect($roles)->pluck('name')->intersect(['investor', 'agent', 'developer', 'customer'])->count() === 4);
        });
        foreach (['investor', 'agent', 'developer', 'customer'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertFalse($user->can('access.manage'));
            $this->actingAs($user)->get('/admin')->assertForbidden();
        }
    }

    public function test_partner_registration_assigns_specific_role(): void
    {
        foreach (['sales_agent' => 'agent', 'developer' => 'developer', 'investor' => 'investor'] as $type => $role) {
            auth()->logout();
            $this->post('/partners/'.$type.'/register', ['name' => 'Partner', 'email' => $type.'@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect();
            $this->assertTrue(auth()->user()->hasRole($role));
            $this->assertFalse(auth()->user()->hasRole('sales'));
        }
    }
}
