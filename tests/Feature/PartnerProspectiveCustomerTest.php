<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PartnerApplication;
use App\Models\PartnerPointEntry;
use App\Models\ProspectiveCustomer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PartnerProspectiveCustomerTest extends TestCase
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
        $user->assignRole(['partner', \App\Enums\UserRole::Agent->value]);
        (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => 'sales_agent', 'status' => $status])->save();

        return $user;
    }

    private function payload(): array
    {
        return [
            'name' => 'Amina Contact',
            'organization' => 'Northwind Clinic',
            'email' => 'amina@northwind.example',
            'phone' => '+2348000000000',
            'location' => 'Lagos',
            'organization_type' => 'healthcare',
            'number_of_users' => 24,
            'follow_up_date' => today()->addWeek()->toDateString(),
            'initial_notes' => 'Asked about clinic operations software.',
        ];
    }

    public function test_pcr_register_captures_identifying_business_details_and_is_partner_scoped(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $this->actingAs($agent)->get(route('partner.pcr.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Pcr/Index')
            ->has('prospects.data', 0));
        $this->post(route('partner.pcr.store'), ['name' => 'Name only', 'organization_type' => 'other'])->assertRedirect();
        $nameOnly = ProspectiveCustomer::query()->where('name', 'Name only')->firstOrFail();
        $this->assertNull($nameOnly->email);
        $this->assertNull($nameOnly->phone);
        $this->assertSame('other', $nameOnly->organization_type);
        $this->post(route('partner.pcr.store'), $this->payload() + ['partner_user_id' => $other->id, 'status' => 'converted'])->assertRedirect();

        $prospect = ProspectiveCustomer::query()->where('organization', 'Northwind Clinic')->firstOrFail();
        $this->assertSame($agent->id, $prospect->partner_user_id);
        $this->assertSame('prospective', $prospect->status);
        $this->assertDatabaseHas('partner_point_entries', ['prospective_customer_id' => $prospect->id, 'event_type' => 'pcr_registered', 'points' => 1]);
        $this->assertDatabaseCount('leads', 0);
        $this->get(route('partner.pcr.index'))->assertInertia(fn (Assert $page) => $page
            ->has('prospects.data', 2)
            ->where('prospects.data.1.name', 'Amina Contact'));
        $this->get(route('partner.pcr.show', $prospect->uuid))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Partner/Pcr/Show')->where('prospect.status', 'prospective'));
        $this->actingAs($other)->get(route('partner.pcr.show', $prospect->uuid))->assertNotFound();
    }

    public function test_interest_requires_explicit_consent_before_creating_a_crm_record(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('partner.pcr.store'), $this->payload())->assertRedirect();
        $prospect = ProspectiveCustomer::query()->firstOrFail();

        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'interested',
            'consent_method' => 'email',
            'consented_by_name' => 'Amina Contact',
        ])->assertSessionHasErrors('consent');
        $this->assertSame('prospective', $prospect->fresh()->status);
        $this->assertDatabaseCount('leads', 0);

        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'consent_pending',
            'contact_notes' => 'Interested; requested time to review the consent information.',
        ])->assertRedirect(route('partner.pcr.index'));
        $this->assertSame('interested_pending_consent', $prospect->fresh()->status);
        $this->assertNotNull($prospect->fresh()->interested_at);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseHas('partner_point_entries', ['prospective_customer_id' => $prospect->id, 'event_type' => 'visit_recorded', 'points' => 2]);

        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'interested',
            'consent' => true,
            'consent_method' => 'email',
            'consented_by_name' => 'Amina Contact',
        ])->assertRedirect();
        $this->assertSame('converted', $prospect->fresh()->status);
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame(8, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
        $this->assertSame(1, PartnerPointEntry::query()->where('prospective_customer_id', $prospect->id)->where('event_type', 'visit_recorded')->count());
    }

    public function test_not_interested_prospect_is_not_added_to_crm(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('partner.pcr.store'), $this->payload())->assertRedirect();
        $prospect = ProspectiveCustomer::query()->firstOrFail();
        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'not_interested',
            'contact_notes' => 'Contacted; not looking for a solution this year.',
        ])->assertRedirect(route('partner.pcr.index'));
        $this->assertSame('not_interested', $prospect->fresh()->status);
        $this->assertNotNull($prospect->fresh()->contacted_at);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_consent_converts_pcr_to_contacted_crm_lead_and_won_adds_owned_client(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('partner.pcr.store'), $this->payload())->assertRedirect();
        $prospect = ProspectiveCustomer::query()->firstOrFail();
        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'interested',
            'consent' => true,
            'consent_method' => 'email',
            'consented_by_name' => 'Amina Contact',
            'consent_details' => 'Consent email received on '.today()->toDateString(),
            'contact_notes' => 'Interested in a product walkthrough.',
        ])->assertRedirect();

        $lead = Lead::query()->firstOrFail();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame($agent->id, $lead->partner_user_id);
        $this->assertSame('Northwind Clinic', $lead->organization);
        $this->assertSame($prospect->id, $prospect->fresh()->convertedLead->id);
        $this->assertNotNull($prospect->fresh()->consent_at);
        $this->assertSame('email', $prospect->fresh()->consent_method);
        $this->assertDatabaseHas('lead_stage_history', ['lead_id' => $lead->id, 'status' => 'contacted', 'actor_id' => $agent->id]);

        foreach (['qualified', 'demo_scheduled', 'demo_completed', 'trial', 'quotation_sent', 'negotiating', 'won'] as $status) {
            $this->put(route('partner.crm.advance', $lead->uuid), ['status' => $status])->assertSessionHasNoErrors();
        }

        $lead->refresh();
        $client = Customer::query()->findOrFail($lead->converted_customer_id);
        $this->assertSame($agent->id, $client->partner_user_id);
        $this->assertSame('Northwind Clinic', $client->name);
        $this->assertSame('active', $client->status->value);
        $this->assertSame(18, (int) PartnerPointEntry::query()->where('partner_user_id', $agent->id)->where('status', 'earned')->sum('points'));
        $this->assertDatabaseHas('partner_point_entries', ['lead_id' => $lead->id, 'event_type' => 'opportunity_won', 'points' => 10]);
        $this->get(route('partner.customers'))->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.uuid', $client->uuid));
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'converted']);
    }

    public function test_prospect_without_email_or_phone_can_progress_and_unapproved_users_cannot_use_pcr(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('partner.pcr.store'), ['name' => 'In-person Contact'])->assertRedirect();
        $prospect = ProspectiveCustomer::query()->firstOrFail();
        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'interested',
            'consent' => true,
            'consent_method' => 'verbal',
            'consented_by_name' => 'Phone Contact',
        ])->assertRedirect();
        $lead = Lead::query()->firstOrFail();
        $this->assertNull($lead->email);
        foreach (['qualified', 'demo_scheduled', 'demo_completed', 'trial', 'quotation_sent', 'negotiating', 'won'] as $status) {
            $this->put(route('partner.crm.advance', $lead->uuid), ['status' => $status])->assertSessionHasNoErrors();
        }
        $client = Customer::query()->findOrFail($lead->fresh()->converted_customer_id);
        $this->assertNull($client->phone);
        $this->assertNull($client->email);

        $unapproved = $this->agent('submitted');
        $this->actingAs($unapproved)->get(route('partner.pcr.index'))->assertForbidden();
        $this->post(route('partner.pcr.store'), $this->payload())->assertForbidden();
        $this->put(route('partner.pcr.contact', $prospect->uuid), ['outcome' => 'not_interested'])->assertForbidden();
    }
}
