<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\InvoiceManager;
use App\Models\BillingSetting;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_admin_can_change_vat_and_existing_invoices_keep_their_rate(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $data = ['customer_id' => Customer::factory()->create()->id, 'issue_date' => today(), 'due_date' => today()->addWeek(), 'currency' => 'NGN', 'items' => [['product_id' => null, 'plan_id' => null, 'description' => 'Subscription', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0, 'tax' => 0]]];
        $manager = app(InvoiceManager::class);
        $old = $manager->create($data, $admin);
        $this->get('/admin/billing-settings')->assertOk();
        $this->put('/admin/billing-settings', ['vat_rate' => '10.25'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('10.25', BillingSetting::vatRate());
        $new = $manager->create($data, $admin);
        $this->assertSame('1025.00', $new->tax);
        $this->assertSame('11025.00', $new->total);
        $this->assertSame('10.25', $new->vat_rate);
        $manager->update($old, $data, $admin);
        $this->assertSame('7.50', $old->fresh()->vat_rate);
        $this->assertSame('10750.00', $old->fresh()->total);
        $this->get('/partners')->assertInertia(fn (Assert $p) => $p->where('billing.vat_rate', '10.25'));
        $this->put('/admin/billing-settings', ['vat_rate' => 0])->assertRedirect();
        $zero = $manager->create($data, $admin);
        $this->assertSame('0.00', $zero->tax);
        $this->assertSame('10000.00', $zero->total);
    }

    public function test_non_administrators_cannot_read_or_update_settings(): void
    {
        foreach (['customer', 'agent', 'developer', 'investor', 'sales', 'finance', 'support'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user);
            $this->get('/admin/billing-settings')->assertForbidden();
            $this->put('/admin/billing-settings', ['vat_rate' => 0])->assertForbidden();
        }
        $this->assertSame('7.50', BillingSetting::vatRate());
    }

    public function test_invalid_rates_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([-1, 100.01, '1.234', 'abc', null] as $rate) {
            $this->put('/admin/billing-settings', ['vat_rate' => $rate])->assertSessionHasErrors('vat_rate');
        }
        $this->assertSame('7.50',BillingSetting::vatRate());
    }
}
