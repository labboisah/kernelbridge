<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Customer;
use App\Models\BillingSetting;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\PartnerNetworkCommission;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCommissionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
    }

    private function rates(): array
    {
        return ['vat_commission_rate' => '7.50', 'developer_commission_rate' => '20.00', 'platform_commission_rate' => '42.50', 'staff_commission_rate' => '10.00', 'partner_commission_rate' => '20.00', 'organization_partner_commission_rate' => '25.00'];
    }

    public function test_all_rates_are_returned_and_only_selected_product_changes(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $this->get('/admin/partner-commissions')->assertInertia(fn (Assert $p) => $p->component('Admin/Partners/Commissions')->has('products', 2)->has('products.0.developer_commission_rate')->has('products.0.platform_commission_rate')->has('products.0.staff_commission_rate')->has('products.0.partner_commission_rate')->has('products.0.organization_partner_commission_rate'));
        $this->put('/admin/partner-commissions/'.$first->slug, $this->rates())->assertSessionHasNoErrors()->assertRedirect();
        foreach ($this->rates() as $field => $rate) {
            $this->assertSame($rate, $first->fresh()->getAttribute($field));
        }
        $this->assertSame('92.50', $second->fresh()->platform_commission_rate);
        $this->assertSame('0.00', $second->fresh()->partner_commission_rate);
    }

    public function test_total_must_be_exactly_one_hundred(): void
    {
        $product = Product::factory()->create();
        foreach (['42.49', '42.51'] as $rate) {
            $data = $this->rates();
            $data['platform_commission_rate'] = $rate;
            $this->put('/admin/partner-commissions/'.$product->slug, $data)->assertSessionHasErrors('allocation');
            $this->assertSame('92.50', $product->fresh()->platform_commission_rate);
        }
        $this->put('/admin/partner-commissions/'.$product->slug, ['vat_commission_rate'=>7.5,'developer_commission_rate' => 30, 'platform_commission_rate' => 30, 'staff_commission_rate' => 16.25, 'partner_commission_rate' => 16.25, 'organization_partner_commission_rate' => 20])->assertSessionHasNoErrors();
    }

    public function test_organization_rate_cannot_exceed_the_available_partner_and_platform_shares(): void
    {
        $product = Product::factory()->create();
        $data = $this->rates();
        $data['organization_partner_commission_rate'] = 63;

        $this->put('/admin/partner-commissions/'.$product->slug, $data)
            ->assertSessionHasErrors('organization_partner_commission_rate');
        $this->assertSame('0.00', $product->fresh()->organization_partner_commission_rate);
    }

    public function test_missing_negative_or_overprecise_shares_are_rejected(): void
    {
        $product = Product::factory()->create();
        foreach ([null, -1, '0.001', 101] as $rate) {
            $data = $this->rates();
            $data['staff_commission_rate'] = $rate;
            $this->put('/admin/partner-commissions/'.$product->slug, $data)->assertSessionHasErrors('staff_commission_rate');
        }
    }

    public function test_generated_totals_are_real_snapshot_amounts_and_exclude_reversals(): void
    {
        $product=Product::factory()->create();$product->forceFill($this->rates())->save();
        $plan=\App\Models\Plan::factory()->create(['product_id'=>$product->id,'price'=>100]);
        $customer=\App\Models\Customer::factory()->create();
        $invoice=app(\App\Domain\Finance\Services\InvoiceManager::class)->create([
            'customer_id'=>$customer->id,'currency'=>'NGN','issue_date'=>today(),'due_date'=>today()->addWeek(),
            'items'=>[['product_id'=>$product->id,'plan_id'=>$plan->id,'description'=>'Subscription','quantity'=>1,'unit_price'=>100,'discount'=>0,'tax'=>0]],
        ],auth()->user());
        $invoice->update(['status'=>'issued']);
        \App\Models\Subscription::factory()->create(['customer_id'=>$customer->id,'plan_id'=>$plan->id,'invoice_id'=>$invoice->id]);
        app(\App\Domain\Finance\Services\InvoiceManager::class)->payment($invoice,['amount'=>'107.50','method'=>'bank_transfer','reference'=>'SHARES','paid_at'=>now()],auth()->user());
        $entry=\App\Models\ProductCommissionAccrual::sole();
        $this->assertEquals(107.50,array_sum($entry->only(['vat_amount','developer_amount','platform_amount','staff_amount','agent_amount'])));
        $this->assertEquals(8.06,$entry->vat_amount);
        $product->forceFill(['platform_commission_rate'=>62.5,'partner_commission_rate'=>0])->save();
        app(\App\Domain\Partners\CommissionService::class)->accrue($invoice->fresh());
        $this->assertDatabaseCount('product_commission_accruals',1);
        $this->assertEquals(21.50,$entry->fresh()->agent_amount);
        $this->get('/admin/partner-commissions')->assertInertia(fn(Assert $p)=>$p->where('products.0.generated.0.currency','NGN')->where('products.0.generated.0.total','99.44')->where('products.0.generated.0.vat','8.06'));
        app(\App\Domain\Partners\CommissionService::class)->reverse($invoice);
        $this->get('/admin/partner-commissions')->assertInertia(fn(Assert $p)=>$p->has('products.0.generated',0));
    }
    public function test_legacy_agent_earnings_are_reported_without_recalculation(): void
    {
        $product=Product::factory()->create();$actor=auth()->user();
        foreach(['NGN','USD'] as $currency){
            $invoice=\App\Models\Invoice::factory()->create(['currency'=>$currency]);
            \App\Models\PartnerCommission::create(['partner_user_id'=>$actor->id,'invoice_id'=>$invoice->id,'product_id'=>$product->id,'rate'=>10,'base_amount'=>1000,'amount'=>100,'currency'=>$currency,'status'=>'earned','earned_at'=>now()]);
        }
        $this->get('/admin/partner-commissions')->assertInertia(fn(Assert $p)=>$p->has('products.0.generated',2)->where('products.0.generated',fn($rows)=>collect($rows)->every(fn($row)=>$row['total']==='100.00' && $row['vat']==='0.00')));
    }

    public function test_network_commissions_use_a_capped_weighted_pool_and_reverse_with_refunds(): void
    {
        $product = Product::factory()->create();
        $product->forceFill($this->rates())->save();
        $sourcePartner = $this->approvedAgent();
        $qualifiedPartner = $this->approvedAgent();
        $customer = $this->activeClient($sourcePartner);
        foreach (range(1, 3) as $_) {
            $this->activeClient($qualifiedPartner);
        }
        BillingSetting::findOrFail(1)->update([
            'partner_network_sharing_enabled' => true,
            'partner_network_min_active_clients' => 1,
            'partner_network_pool_rate' => 10,
            'partner_network_qualified_weight' => 1,
            'partner_network_premium_weight' => 2,
            'partner_network_elite_weight' => 3,
        ]);
        $plan = \App\Models\Plan::factory()->create(['product_id' => $product->id, 'price' => 100]);
        $invoice = app(\App\Domain\Finance\Services\InvoiceManager::class)->create([
            'customer_id' => $customer->id,
            'currency' => 'NGN',
            'issue_date' => today(),
            'due_date' => today()->addWeek(),
            'items' => [['product_id' => $product->id, 'plan_id' => $plan->id, 'description' => 'Subscription', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax' => 0]],
        ], auth()->user());
        $invoice->update(['status' => 'issued']);
        \App\Models\Subscription::factory()->create(['customer_id' => $customer->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id]);
        app(\App\Domain\Finance\Services\InvoiceManager::class)->payment($invoice, ['amount' => '107.50', 'method' => 'bank_transfer', 'reference' => 'NETWORK-SHARE', 'paid_at' => now()], auth()->user());

        $entries = PartnerNetworkCommission::where('invoice_id', $invoice->id)->orderBy('partner_user_id')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(10.75, $entries->sum('amount'));
        $this->assertEquals(10.75, (float) $entries->first()->pool_amount);
        $this->assertLessThanOrEqual((float) $entries->first()->platform_amount, (float) $entries->first()->pool_amount);
        $this->assertSame(3.58, (float) $entries->firstWhere('partner_user_id', $sourcePartner->id)->amount);
        $this->assertSame(7.17, (float) $entries->firstWhere('partner_user_id', $qualifiedPartner->id)->amount);
        $this->assertDatabaseHas('partner_commissions', ['partner_user_id' => $sourcePartner->id, 'invoice_id' => $invoice->id, 'status' => 'earned']);
        $this->assertDatabaseMissing('partner_commissions', ['partner_user_id' => $qualifiedPartner->id, 'invoice_id' => $invoice->id]);

        app(\App\Domain\Partners\CommissionService::class)->accrue($invoice->fresh());
        $this->assertDatabaseCount('partner_network_commissions', 2);
        $this->post('/admin/partner-network-commissions/'.$entries->first()->id.'/paid')->assertRedirect();
        $this->assertDatabaseHas('partner_network_commissions', ['id' => $entries->first()->id, 'status' => 'paid']);

        app(\App\Domain\Partners\CommissionService::class)->reverse($invoice);
        $this->assertDatabaseMissing('partner_network_commissions', ['invoice_id' => $invoice->id, 'status' => 'earned']);
        $this->assertDatabaseHas('partner_network_commissions', ['id' => $entries->first()->id, 'status' => 'reversed']);
        $this->assertNotNull($entries->first()->fresh()->paid_at);
        $this->assertDatabaseHas('partner_commissions', ['partner_user_id' => $sourcePartner->id, 'invoice_id' => $invoice->id, 'status' => 'reversed']);

        $invoice->fresh()->update(['status' => 'issued', 'amount_paid' => 0, 'balance' => $invoice->total, 'paid_at' => null]);
        app(\App\Domain\Finance\Services\InvoiceManager::class)->payment($invoice->fresh(), ['amount' => '107.50', 'method' => 'bank_transfer', 'reference' => 'NETWORK-SHARE-SECOND', 'paid_at' => now()], auth()->user());
        $this->assertDatabaseCount('partner_network_commissions', 4);
        $this->assertDatabaseHas('partner_network_commissions', ['invoice_id' => $invoice->id, 'earning_sequence' => 2, 'status' => 'earned']);
        $this->assertDatabaseHas('partner_commissions', ['partner_user_id' => $sourcePartner->id, 'invoice_id' => $invoice->id, 'status' => 'earned']);
    }

    public function test_admin_configures_network_policy_and_partner_levels_follow_active_clients(): void
    {
        $partner = $this->approvedAgent();
        $this->put('/admin/partner-network-policy', [
            'partner_network_sharing_enabled' => true,
            'partner_network_min_active_clients' => 2,
            'partner_network_pool_rate' => 5,
            'partner_network_qualified_weight' => 1,
            'partner_network_premium_weight' => 2,
            'partner_network_elite_weight' => 4,
        ])->assertRedirect();
        $this->assertTrue(BillingSetting::findOrFail(1)->partner_network_sharing_enabled);
        $this->assertEquals(2, BillingSetting::findOrFail(1)->getRawOriginal('partner_network_min_active_clients'));

        $qualification = app(\App\Domain\Partners\PartnerQualificationService::class);
        $this->assertSame('standard', $qualification->forPartner($partner->id)['level']);
        $first = $this->activeClient($partner);
        $this->assertSame('qualified', $qualification->forPartner($partner->id)['level']);
        $this->assertFalse($qualification->forPartner($partner->id)['network_eligible']);
        $this->activeClient($partner);
        $this->assertTrue($qualification->forPartner($partner->id)['network_eligible']);
        $this->activeClient($partner);
        $this->assertSame('premium', $qualification->forPartner($partner->id)['level']);
        foreach (range(1, 3) as $_) {
            $this->activeClient($partner);
        }
        $this->assertSame('elite', $qualification->forPartner($partner->id)['level']);
        $first->update(['status' => 'inactive']);
        $this->assertSame(5, $qualification->forPartner($partner->id)['active_clients']);
        $this->assertSame('premium', $qualification->forPartner($partner->id)['level']);
    }

    private function approvedAgent(): User
    {
        $partner = User::factory()->create();
        $partner->assignRole(['partner', 'agent']);
        (new PartnerApplication)->forceFill(['user_id' => $partner->id, 'type' => 'sales_agent', 'status' => 'approved'])->save();

        return $partner;
    }

    private function activeClient(User $partner): Customer
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['partner_user_id' => $partner->id])->save();

        return $customer;
    }
}
