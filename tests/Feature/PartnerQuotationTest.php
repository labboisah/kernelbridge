<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\Lead;
use App\Models\PartnerApplication;
use App\Models\PartnerQuotation;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProspectiveCustomer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PartnerQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function agent(string $status = 'approved'): User
    {
        $user = User::factory()->create();
        $user->assignRole(['partner', UserRole::forPartnerType('sales_agent')]);
        (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => 'sales_agent', 'status' => $status])->save();

        return $user;
    }

    private function input(Plan $plan): array
    {
        return [
            'recipient_type' => 'manual',
            'recipient_name' => 'Prospect Example',
            'recipient_email' => 'prospect@example.test',
            'product_id' => $plan->product_id,
            'plan_uuid' => $plan->uuid,
            'description' => 'Software package',
            'quantity' => 3,
            'notes' => 'Obsolete note should not appear',
            'terms' => 'Payment due on acceptance',
        ];
    }

    public function test_manual_quotation_uses_catalogue_vat_and_immutable_snapshots_in_pdf(): void
    {
        $partner = $this->agent();
        $product = Product::factory()->create(['status' => 'active', 'name' => 'Catalogue Product']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Gold Plan', 'price' => '10.05', 'currency' => 'NGN']);
        $module = $plan->features()->create(['feature_key' => 'module_inventory', 'feature_name' => 'Inventory module', 'description' => 'Stock controls', 'enabled' => true]);
        $feature = $plan->features()->create(['feature_key' => 'reports', 'feature_name' => 'Reports', 'description' => 'Performance reports', 'enabled' => true, 'limit_value' => '25']);
        $plan->features()->create(['feature_key' => 'disabled', 'feature_name' => 'Unavailable feature', 'enabled' => false]);
        $plan->limits()->create(['limit_key' => 'max_users', 'limit_name' => 'Users', 'limit_value' => '5']);
        BillingSetting::findOrFail(1)->update(['vat_rate' => '7.50']);
        $this->actingAs($partner)->get('/partner/quotations')->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.modules.0.name', 'Inventory module')
            ->where('plans.0.features.0.name', 'Reports')
            ->where('plans.0.features.0.limit', '25')
            ->where('plans.0.features.1.name', 'Users'));
        $this->actingAs($partner)->post('/partner/quotations', [
            ...$this->input($plan), 'unit_price' => '0.01', 'discount' => '9999.00', 'vat_rate' => 0, 'partner_user_id' => 999,
            'plan_modules' => [['name' => 'Spoofed module']], 'plan_features' => [['name' => 'Spoofed feature']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('partner.quotations.index'));
        $quotation = PartnerQuotation::firstOrFail();
        $this->assertSame($partner->id, $quotation->partner_user_id);
        $this->assertSame('10.05', $quotation->unit_price);
        $this->assertSame('30.15', $quotation->subtotal);
        $this->assertSame('2.26', $quotation->tax);
        $this->assertSame('32.41', $quotation->total);
        $this->assertNull($quotation->notes);
        $this->assertSame('Inventory module', $quotation->plan_modules[0]['name']);
        $this->assertSame('Reports', $quotation->plan_features[0]['name']);
        $this->assertCount(2, $quotation->plan_features);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('subscriptions', 0);

        $plan->update(['name' => 'Changed Plan', 'price' => '99.00', 'currency' => 'USD']);
        $product->update(['name' => 'Changed Product']);
        $module->update(['feature_name' => 'Changed module']);
        $feature->update(['feature_name' => 'Changed feature']);
        BillingSetting::findOrFail(1)->update(['vat_rate' => '15.00']);
        $response = $this->get(route('partner.quotations.pdf', $quotation->uuid))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$quotation->quotation_number.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $binary = (new ExecutableFinder)->find('pdftotext');
        if ($binary) {
            $path = tempnam(sys_get_temp_dir(), 'quotation-');
            try {
                file_put_contents($path, $response->getContent());
                $process = new Process([$binary, $path, '-']);
                $process->mustRun();
                $text = $process->getOutput();
            } finally {
                unlink($path);
            }
            foreach (['Prospect Example', 'Catalogue Product', 'Gold Plan', 'monthly', '10.05', '30.15', '7.50%', '2.26', '32.41', 'NGN', 'Inventory module', 'Stock controls', 'Reports', 'Performance reports', 'Users', 'Payment due on acceptance'] as $expected) {
                $this->assertStringContainsString($expected, $text);
            }
            $this->assertStringNotContainsString('Changed Product', $text);
            $this->assertStringNotContainsString('Changed module', $text);
            $this->assertStringNotContainsString('Unavailable feature', $text);
            $this->assertStringNotContainsString('Obsolete note should not appear', $text);
        }
    }

    public function test_edunexa_modules_group_only_included_features(): void
    {
        $partner = $this->agent();
        $product = Product::factory()->create(['status' => 'active', 'code' => 'EDUNEXA']);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $plan->features()->create(['feature_key' => 'module_core-school-operations', 'feature_name' => 'Core School Operations', 'enabled' => true]);
        $plan->features()->create(['feature_key' => 'core-school-operations__user_and_access_management', 'feature_name' => 'User management', 'enabled' => true]);
        $plan->features()->create(['feature_key' => 'core-school-operations__school_dashboard_and_reports', 'feature_name' => 'Disabled report', 'enabled' => false]);
        $plan->features()->create(['feature_key' => 'module_finance-and-fees', 'feature_name' => 'Finance', 'enabled' => false]);
        $plan->features()->create(['feature_key' => 'finance-and-fees__fee_configuration', 'feature_name' => 'Hidden finance feature', 'enabled' => true]);
        $plan->features()->create(['feature_key' => 'max_users', 'feature_name' => 'Users', 'enabled' => true, 'limit_value' => '5']);

        $this->actingAs($partner)->get('/partner/quotations')->assertInertia(fn (Assert $page) => $page
            ->has('plans.0.modules', 1)->where('plans.0.modules.0.name', 'Core School Operations')
            ->has('plans.0.modules.0.features', 1)->where('plans.0.modules.0.features.0.name', 'User management')
            ->has('plans.0.features', 1)->where('plans.0.features.0.name', 'Users'));
        $this->post('/partner/quotations', $this->input($plan))->assertSessionHasNoErrors();
        $quote = PartnerQuotation::firstOrFail();
        $this->assertSame('User management', $quote->plan_modules[0]['features'][0]['name']);
        $this->assertCount(1, $quote->plan_modules);
        $this->assertCount(1, $quote->plan_features);
        $html = view('partner.quotation-pdf', ['quotation' => $quote])->render();
        $this->assertStringContainsString('User management', $html);
        $this->assertStringNotContainsString('Hidden finance feature', $html);
        $this->assertStringNotContainsString('Disabled report', $html);
    }

    public function test_partner_quotation_pdf_embeds_shared_document_images(): void
    {
        Storage::fake('local');
        $stamp = UploadedFile::fake()->image('stamp.png', 30, 30)->store('document-assets', 'local');
        $signature = UploadedFile::fake()->image('signature.png', 60, 20)->store('document-assets', 'local');
        DB::table('document_assets')->where('id', 1)->update(['stamp_path' => $stamp, 'signature_path' => $signature]);
        $partner = $this->agent();
        $plan = Plan::factory()->create(['product_id' => Product::factory()->create(['status' => 'active'])->id]);
        $this->actingAs($partner)->post('/partner/quotations', $this->input($plan))->assertSessionHasNoErrors();
        $response = $this->get(route('partner.quotations.pdf', PartnerQuotation::firstOrFail()->uuid))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '/Subtype /Image'));
    }

    public function test_earlier_quotations_do_not_claim_current_plan_contents_or_show_notes(): void
    {
        $partner = $this->agent();
        $plan = Plan::factory()->create(['product_id' => Product::factory()->create(['status' => 'active'])->id]);
        $this->actingAs($partner)->post('/partner/quotations', $this->input($plan))->assertSessionHasNoErrors();
        $quotation = PartnerQuotation::firstOrFail();
        $quotation->update(['plan_modules' => null, 'plan_features' => null, 'notes' => 'Old private note']);

        $html = view('partner.quotation-pdf', ['quotation' => $quotation])->render();
        $this->assertStringContainsString('Plan details were not captured for this quotation.', $html);
        $this->assertStringNotContainsString('Old private note', $html);
    }

    public function test_mediflow_packages_show_only_enabled_modules_and_limits(): void
    {
        $partner = $this->agent();
        $product = Product::factory()->create(['status' => 'active', 'code' => 'MEDIFLOW']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'pharmacy']);
        $plan->features()->create(['feature_key' => 'pharmacy', 'feature_name' => 'Pharmacy', 'enabled' => true]);
        $plan->features()->create(['feature_key' => 'billing', 'feature_name' => 'Billing', 'enabled' => false]);
        $plan->limits()->create(['limit_key' => 'max_users', 'limit_name' => 'Users', 'limit_value' => '10']);

        $this->actingAs($partner)->get('/partner/quotations')->assertInertia(fn (Assert $page) => $page
            ->has('plans.0.modules', 1)->where('plans.0.modules.0.name', 'Pharmacy')
            ->has('plans.0.features', 1)->where('plans.0.features.0.name', 'Users'));
        $this->post('/partner/quotations', $this->input($plan))->assertSessionHasNoErrors();
        $quote = PartnerQuotation::firstOrFail();
        $this->assertSame('Pharmacy', $quote->plan_modules[0]['name']);
        $this->assertCount(1, $quote->plan_modules);
    }

    public function test_owned_pcr_and_crm_recipients_are_snapshotted_without_other_records(): void
    {
        $partner = $this->agent();
        $plan = Plan::factory()->create(['product_id' => Product::factory()->create(['status' => 'active'])->id]);
        $prospect = new ProspectiveCustomer(['name' => 'PCR person', 'organization' => 'PCR organization']);
        $prospect->forceFill(['partner_user_id' => $partner->id])->save();
        $lead = Lead::factory()->create(['name' => 'CRM person', 'organization' => 'CRM organization']);
        $lead->forceFill(['partner_user_id' => $partner->id])->save();

        foreach (['pcr' => $prospect, 'crm' => $lead] as $type => $record) {
            $this->actingAs($partner)->post('/partner/quotations', [
                ...$this->input($plan), 'recipient_type' => $type, 'recipient_uuid' => $record->uuid,
                'recipient_name' => 'Spoofed name',
            ])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseHas('partner_quotations', ['prospective_customer_id' => $prospect->id, 'recipient_name' => 'PCR person', 'recipient_organization' => 'PCR organization']);
        $this->assertDatabaseHas('partner_quotations', ['lead_id' => $lead->id, 'recipient_name' => 'CRM person', 'recipient_organization' => 'CRM organization']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('prospective_customers', 1);
    }

    public function test_access_and_recipient_selection_are_partner_scoped(): void
    {
        $partner = $this->agent();
        $other = $this->agent();
        $plan = Plan::factory()->create(['product_id' => Product::factory()->create(['status' => 'active'])->id]);
        $prospect = new ProspectiveCustomer(['name' => 'Private prospect']);
        $prospect->forceFill(['partner_user_id' => $other->id])->save();
        $lead = Lead::factory()->create(['name' => 'Private lead']);
        $lead->forceFill(['partner_user_id' => $other->id])->save();
        $this->actingAs($other)->post('/partner/quotations', $this->input($plan))->assertSessionHasNoErrors();
        $quote = PartnerQuotation::firstOrFail();

        $this->actingAs($partner)->get('/partner/quotations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Quotations')->has('quotations.data', 0)->has('prospects', 0)->has('leads', 0));
        $this->get(route('partner.quotations.pdf', $quote->uuid))->assertNotFound();
        foreach (['pcr' => $prospect, 'crm' => $lead] as $type => $record) {
            $this->post('/partner/quotations', [...$this->input($plan), 'recipient_type' => $type, 'recipient_uuid' => $record->uuid])->assertNotFound();
        }
        $pending = $this->agent('submitted');
        $this->actingAs($pending)->get('/partner/quotations')->assertForbidden();
        $this->post('/partner/quotations', $this->input($plan))->assertForbidden();
        $this->get(route('partner.quotations.pdf', $quote->uuid))->assertForbidden();
    }

    public function test_mismatched_or_unavailable_plans_and_invalid_amounts_are_rejected(): void
    {
        $partner = $this->agent();
        $product = Product::factory()->create(['status' => 'active']);
        $other = Product::factory()->create(['status' => 'active']);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $this->actingAs($partner)->post('/partner/quotations', [...$this->input($plan), 'product_id' => $other->id])->assertSessionHasErrors('plan_uuid');
        $plan->update(['is_public' => false]);
        $this->post('/partner/quotations', $this->input($plan))->assertSessionHasErrors('plan_uuid');
        $plan->update(['is_public' => true]);
        $product->update(['status' => 'inactive']);
        $this->post('/partner/quotations', $this->input($plan))->assertSessionHasErrors('plan_uuid');
        $product->update(['status' => 'active']);
        $plan->update(['price' => '1000000000.00']);
        $this->post('/partner/quotations', $this->input($plan))->assertSessionHasErrors('quantity');
        $this->post('/partner/quotations', [...$this->input($plan), 'quantity' => 0])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('partner_quotations', 0);
    }
}
