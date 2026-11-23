<?php

namespace Tests\Feature;

use App\Domain\Partners\CommissionService;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PartnerApplication;
use App\Models\PartnerOrganizationUpgrade;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCommissionAccrual;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PartnerOrganizationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    private function approvedAgent(): array
    {
        $user = User::factory()->create();
        $user->assignRole(['partner', UserRole::Agent->value]);
        $application = new PartnerApplication;
        $application->forceFill([
            'user_id' => $user->id,
            'type' => 'sales_agent',
            'status' => 'approved',
            'partner_category' => 'individual',
        ])->save();

        return [$user, $application];
    }

    private function upgradeData(array $overrides = []): array
    {
        return array_replace([
            'business_legal_name' => 'Northwind Technologies Ltd',
            'registration_number' => 'RC-102938',
            'registration_country' => 'Nigeria',
            'business_type' => 'private_limited',
            'incorporation_date' => '2021-05-10',
            'tax_id' => 'TIN-123456',
            'website' => 'https://northwind.example',
            'business_email' => 'business@northwind.example',
            'business_phone' => '+2348000000000',
            'registered_address' => '12 Marina Road',
            'city' => 'Lagos',
            'state_region' => 'Lagos',
            'country' => 'Nigeria',
            'representative_name' => 'Amina Representative',
            'representative_title' => 'Managing Director',
            'representative_email' => 'amina@northwind.example',
            'representative_phone' => '+2348000000001',
            'ownership_details' => [
                ['full_name' => 'Amina Owner', 'role' => 'Director and shareholder', 'ownership_percentage' => 60, 'country' => 'Nigeria'],
                ['full_name' => 'Bola Owner', 'role' => 'Shareholder', 'ownership_percentage' => 40, 'country' => 'Nigeria'],
            ],
            'business_activity' => 'Business software implementation and support.',
            'partnership_goals' => 'Provide regional sales and implementation services.',
        ], $overrides);
    }

    private function documents(): array
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=', true);

        return [
            'registration_proof' => UploadedFile::fake()->createWithContent('registration.pdf', "%PDF-1.4\nregistration"),
            'owner_identity' => UploadedFile::fake()->createWithContent('identity.pdf', "%PDF-1.4\nidentity"),
            'address_proof' => UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\nbill"),
            'office_photo_one' => UploadedFile::fake()->createWithContent('office1.png', $image),
            'office_photo_two' => UploadedFile::fake()->createWithContent('office2.png', $image),
        ];
    }

    public function test_individual_sales_agent_can_submit_corporate_upgrade_and_admin_approval_changes_category(): void
    {
        [$agent, $application] = $this->approvedAgent();
        $this->actingAs($agent)->get('/partner/organization-upgrade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Partner/OrganizationUpgrade')->where('readOnly', false));

        $this->from('/partner/organization-upgrade')->actingAs($agent)
            ->post('/partner/organization-upgrade', [...$this->upgradeData(), ...$this->documents()])
            ->assertSessionHasNoErrors()->assertRedirect('/partner/organization-upgrade');
        $upgrade = PartnerOrganizationUpgrade::query()->sole();
        $this->assertSame('submitted', $upgrade->status);
        $this->assertSame('individual', $application->fresh()->partner_category);
        $this->assertSame(100, collect($upgrade->ownership_details)->sum('ownership_percentage'));
        foreach (['registration_proof_path', 'owner_identity_path', 'address_proof_path', 'office_photo_one_path', 'office_photo_two_path'] as $path) {
            Storage::disk('local')->assertExists($upgrade->$path);
        }
        $this->get('/partner/organization-upgrade')->assertInertia(fn (Assert $page) => $page->where('readOnly', true));

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $this->actingAs($admin)->get('/admin/partners')->assertInertia(fn (Assert $page) => $page
            ->has('organizationUpgrades.data', 1)
            ->where('organizationUpgrades.data.0.business_legal_name', 'Northwind Technologies Ltd')
            ->where('organizationUpgrades.data.0.documents.registration', true)
            ->where('organizationUpgrades.data.0.documents.office_photo_one', true)
            ->where('organizationUpgrades.data.0.documents.office_photo_two', true)
            ->missing('organizationUpgrades.data.0.office_photo_one_path')
            ->missing('organizationUpgrades.data.0.office_photo_two_path')
            ->missing('organizationUpgrades.data.0.registration_proof_path'));
        $this->delete(route('admin.partners.destroy', $application))->assertSessionHasErrors('application_delete');
        $this->assertDatabaseHas('partner_applications', ['id' => $application->id]);
        $this->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/registration')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_logs', ['event' => 'partner.organization_document_downloaded', 'actor_id' => $admin->id]);
        $this->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/unknown')->assertNotFound();
        $this->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/office_photo_one')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($agent)->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/identity')->assertForbidden();
        $this->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/office_photo_two')->assertForbidden();
        $this->actingAs($admin);
        $this->put('/admin/organization-upgrades/'.$upgrade->id, [
            'status' => 'approved',
            'review_note' => 'Corporate details verified and approved.',
        ])->assertRedirect();

        $this->assertSame('approved', $upgrade->fresh()->status);
        $this->assertSame('organization', $application->fresh()->partner_category);
        $this->actingAs($agent)->get('/partner/organization-upgrade')->assertNotFound();
    }

    public function test_upgrade_requires_complete_ownership_and_only_approved_sales_agents_can_apply(): void
    {
        [$agent] = $this->approvedAgent();
        $this->actingAs($agent)->post('/partner/organization-upgrade', [...$this->upgradeData([
            'ownership_details' => [
                ['full_name' => 'One Owner', 'role' => 'Shareholder', 'ownership_percentage' => 75],
            ],
        ]), ...$this->documents()])->assertSessionHasErrors('ownership_details');

        $pending = User::factory()->create();
        $pending->assignRole(['partner', UserRole::Agent->value]);
        (new PartnerApplication)->forceFill(['user_id' => $pending->id, 'type' => 'sales_agent', 'status' => 'submitted'])->save();
        $this->actingAs($pending)->get('/partner/organization-upgrade')->assertForbidden();
        $this->post('/partner/organization-upgrade', $this->upgradeData())->assertForbidden();
    }

    public function test_upgrade_documents_are_required_and_reject_unsupported_or_oversized_files(): void
    {
        [$agent] = $this->approvedAgent();
        $this->actingAs($agent)->post('/partner/organization-upgrade', $this->upgradeData())
            ->assertSessionHasErrors(['registration_proof', 'owner_identity', 'address_proof', 'office_photo_one', 'office_photo_two']);
        $documents = $this->documents();
        $documents['owner_identity'] = UploadedFile::fake()->createWithContent('identity.txt', 'not an identity document');
        $documents['address_proof'] = UploadedFile::fake()->create('bill.pdf', 5121, 'application/pdf');
        $this->post('/partner/organization-upgrade', [...$this->upgradeData(), ...$documents])
            ->assertSessionHasErrors(['owner_identity', 'address_proof']);
        $this->assertDatabaseCount('partner_organization_upgrades', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('partner-organization-documents'));
    }

    public function test_office_photos_must_both_be_images_within_the_size_limit(): void
    {
        [$agent] = $this->approvedAgent();
        $files = $this->documents();
        unset($files['office_photo_two']);
        $this->actingAs($agent)->post('/partner/organization-upgrade', [...$this->upgradeData(), ...$files])
            ->assertSessionHasErrors('office_photo_two');
        $files = $this->documents();
        $files['office_photo_one'] = UploadedFile::fake()->createWithContent('office.pdf', "%PDF-1.4\nnot an image");
        $files['office_photo_two'] = UploadedFile::fake()->create('large.png', 5121, 'image/png');
        $this->post('/partner/organization-upgrade', [...$this->upgradeData(), ...$files])
            ->assertSessionHasErrors(['office_photo_one', 'office_photo_two']);
        $this->assertDatabaseCount('partner_organization_upgrades', 0);
    }

    public function test_legacy_requests_remain_reviewable_and_declined_requests_require_new_documents(): void
    {
        [$agent, $application] = $this->approvedAgent();
        $upgrade = PartnerOrganizationUpgrade::create([
            ...$this->upgradeData(),
            'user_id' => $agent->id,
            'partner_application_id' => $application->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/partners')->assertInertia(fn (Assert $page) => $page
            ->where('organizationUpgrades.data.0.documents.registration', false)
            ->where('organizationUpgrades.data.0.documents.identity', false)
            ->where('organizationUpgrades.data.0.documents.address', false)
            ->where('organizationUpgrades.data.0.documents.office_photo_one', false)
            ->where('organizationUpgrades.data.0.documents.office_photo_two', false));
        $this->get('/admin/organization-upgrades/'.$upgrade->id.'/documents/address')->assertNotFound();
        $this->put('/admin/organization-upgrades/'.$upgrade->id, ['status' => 'declined', 'review_note' => 'Please submit documents.'])->assertRedirect();
        $this->actingAs($agent)->post('/partner/organization-upgrade', $this->upgradeData())->assertSessionHasErrors(['registration_proof', 'owner_identity', 'address_proof']);
        $this->post('/partner/organization-upgrade', [...$this->upgradeData(), ...$this->documents()])->assertSessionHasNoErrors();
        $this->assertSame(2, PartnerOrganizationUpgrade::count());
    }

    public function test_organization_commission_uses_configured_rate_and_rebalances_platform_share(): void
    {
        [$agent, $application] = $this->approvedAgent();
        $upgrade = PartnerOrganizationUpgrade::query()->create([
            ...$this->upgradeData(),
            'user_id' => $agent->id,
            'partner_application_id' => $application->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $this->actingAs($admin)->put('/admin/organization-upgrades/'.$upgrade->id, [
            'status' => 'approved',
            'review_note' => 'Approved.',
        ])->assertRedirect();

        $product = Product::factory()->create();
        $product->forceFill([
            'vat_commission_rate' => 7.5,
            'developer_commission_rate' => 0,
            'platform_commission_rate' => 82.5,
            'staff_commission_rate' => 0,
            'partner_commission_rate' => 10,
            'organization_partner_commission_rate' => 20,
        ])->save();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $customer = Customer::factory()->create();
        $customer->forceFill(['partner_user_id' => $agent->id])->save();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => '1000.00',
            'amount_paid' => '1000.00',
            'balance' => '0.00',
            'status' => 'paid',
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'description' => 'Organization partner subscription',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000,
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'invoice_id' => $invoice->id,
            'status' => 'active',
        ]);
        $invoice->payments()->create([
            'amount' => '1000.00',
            'currency' => 'NGN',
            'reference' => 'ORG-COMMISSION-PAYMENT',
            'method' => 'bank_transfer',
            'status' => 'successful',
            'paid_at' => now(),
            'recorded_by' => $admin->id,
        ]);

        app(CommissionService::class)->accrue($invoice);

        $this->assertDatabaseHas('partner_commissions', [
            'partner_user_id' => $agent->id,
            'invoice_id' => $invoice->id,
            'rate' => 20,
            'amount' => 200,
        ]);
        $accrual = ProductCommissionAccrual::query()->sole();
        $this->assertSame('20.00', $accrual->rates['agent']);
        $this->assertSame('72.50', $accrual->rates['platform']);
        $this->assertEquals(1000, array_sum($accrual->only(['vat_amount', 'developer_amount', 'platform_amount', 'staff_amount', 'agent_amount'])));
    }
}
