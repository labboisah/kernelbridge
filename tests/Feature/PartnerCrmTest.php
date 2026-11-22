<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\PartnerApplication;
use App\Models\ProspectiveCustomer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PartnerCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function agent(string $status = 'approved', string $type = 'sales_agent'): User
    {
        $user = User::factory()->create();
        $user->assignRole(['partner', \App\Enums\UserRole::forPartnerType($type)]);
        (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => $type, 'status' => $status])->save();

        return $user;
    }

    private function payload(): array
    {
        return ['name' => 'Jane Prospect', 'organization' => 'Acme', 'email' => 'jane@example.test', 'phone' => '123456', 'location' => 'Lagos', 'follow_up_date' => '2026-12-01'];
    }

    private function createLeadThroughPcr(User $agent, array $payload = []): Lead
    {
        $data = array_replace($this->payload(), $payload);
        $this->actingAs($agent)->post(route('partner.pcr.store'), $data)->assertRedirect();
        $prospect = ProspectiveCustomer::query()->where('partner_user_id', $agent->id)->latest('id')->firstOrFail();
        $this->put(route('partner.pcr.contact', $prospect->uuid), [
            'outcome' => 'interested',
            'consent' => true,
            'consent_method' => 'verbal',
            'consented_by_name' => $prospect->name,
        ])->assertRedirect();

        return $prospect->fresh()->convertedLead()->firstOrFail();
    }

    public function test_approved_agent_can_create_edit_and_view_multiple_company_leads(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $lead = $this->createLeadThroughPcr($agent, ['partner_user_id' => $other->id, 'status' => 'won']);
        $this->assertSame($agent->id, $lead->partner_user_id);
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertNotNull($lead->lead_number);
        $this->assertDatabaseHas('lead_stage_history', ['lead_id' => $lead->id, 'status' => 'contacted', 'actor_id' => $agent->id]);
        $this->createLeadThroughPcr($agent, ['name' => 'Second', 'email' => 'second@example.test']);
        $this->get(route('partner.crm.index'))->assertInertia(fn (Assert $page) => $page->component('Partner/Crm/Index')->has('leads.data', 2));
        $this->get(route('partner.crm.show', $lead->uuid))->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Crm/Show')->where('lead.status', 'contacted')->where('nextStatus', 'qualified')->has('lead.stage_history', 1));
        $this->put(route('partner.crm.update', $lead->uuid), array_replace($this->payload(), ['name' => 'Updated', 'partner_user_id' => $other->id, 'status' => 'won']))->assertRedirect();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'name' => 'Updated', 'partner_user_id' => $agent->id, 'status' => 'contacted', 'source' => 'partner_pcr']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('admin.leads.index'))->assertInertia(fn (Assert $page) => $page->has('leads', 2));
        $this->get(route('admin.leads.show', $lead))->assertInertia(fn (Assert $page) => $page->where('lead.partner_owner.name', $agent->name)->has('lead.stage_history', 1));
    }

    public function test_pipeline_records_dates_and_only_allows_next_or_lost(): void
    {
        $agent = $this->agent();
        $this->travelTo(now()->startOfSecond());
        $lead = $this->createLeadThroughPcr($agent);
        $created = now()->toDateTimeString();
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'demo_scheduled'])->assertSessionHasErrors('status');
        $this->travel(1)->days();
        $qualified = now()->toDateTimeString();
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'qualified'])->assertSessionHasNoErrors();
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'qualified'])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('lead_stage_history', ['lead_id' => $lead->id, 'status' => 'contacted', 'entered_at' => $created]);
        $this->assertDatabaseHas('lead_stage_history', ['lead_id' => $lead->id, 'status' => 'qualified', 'entered_at' => $qualified]);
        $this->assertDatabaseCount('lead_stage_history', 2);

        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'lost'])->assertSessionHasNoErrors();
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'qualified'])->assertSessionHasErrors('status');
        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
        $this->assertDatabaseCount('lead_stage_history', 3);
    }

    public function test_every_forward_step_including_won_is_available(): void
    {
        $agent = $this->agent();
        $lead = $this->createLeadThroughPcr($agent);
        foreach (['qualified', 'demo_scheduled', 'demo_completed', 'trial', 'quotation_sent', 'negotiating', 'won'] as $status) {
            $this->put(route('partner.crm.advance', $lead->uuid), ['status' => $status])->assertSessionHasNoErrors();
        }
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'lost'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('lead_stage_history', 8);
        $this->assertDatabaseHas('customers', ['id' => $lead->fresh()->converted_customer_id, 'partner_user_id' => $agent->id]);
        $this->assertDatabaseHas('customers', ['id' => $lead->fresh()->converted_customer_id, 'name' => 'Acme']);
    }

    public function test_staff_status_changes_are_recorded_and_owner_survives_reassignment(): void
    {
        $agent = $this->agent();
        $lead = $this->createLeadThroughPcr($agent);
        $sales = User::factory()->create();
        $sales->assignRole('sales');
        $this->actingAs($sales)->put(route('admin.leads.update', $lead), [
            'status' => 'qualified', 'assigned_to' => $sales->id, 'follow_up_date' => null, 'product_id' => null,
        ])->assertRedirect();
        $this->assertDatabaseHas('lead_stage_history', ['lead_id' => $lead->id, 'status' => 'qualified', 'actor_id' => $sales->id]);
        $this->actingAs($agent)->get(route('partner.crm.show', $lead->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('lead.status', 'qualified')->where('nextStatus', 'demo_scheduled')->has('lead.stage_history', 2));
        $this->actingAs($sales)->put(route('admin.leads.update', $lead), [
            'status' => 'won', 'assigned_to' => $sales->id, 'follow_up_date' => null, 'product_id' => null,
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id' => $lead->fresh()->converted_customer_id,
            'partner_user_id' => $agent->id,
        ]);
    }

    public function test_history_rolls_back_with_lead_status(): void
    {
        $agent = $this->agent();
        $lead = $this->createLeadThroughPcr($agent);
        DB::beginTransaction();
        $lead->update(['status' => 'qualified']);
        DB::rollBack();
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertDatabaseCount('lead_stage_history', 1);
    }

    public function test_unapproved_or_other_agents_cannot_access_or_modify_crm(): void
    {
        $owner = $this->agent();
        $lead = $this->createLeadThroughPcr($owner);
        $other = $this->agent();
        $this->actingAs($other)->get(route('partner.crm.index'))->assertInertia(fn (Assert $page) => $page->has('leads.data', 0));
        $this->get(route('partner.crm.show', $lead->uuid))->assertNotFound();
        $this->put(route('partner.crm.update', $lead->uuid), $this->payload())->assertNotFound();
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'contacted'])->assertNotFound();
        foreach ([['submitted', 'sales_agent'], ['approved', 'developer'], ['approved', 'investor']] as [$status, $type]) {
            $this->actingAs($this->agent($status, $type))->get(route('partner.crm.index'))->assertForbidden();
            $this->get(route('partner.pcr.index'))->assertForbidden();
            $this->post(route('partner.pcr.store'), $this->payload())->assertForbidden();
            $this->get(route('partner.crm.show', $lead->uuid))->assertForbidden();
            $this->put(route('partner.crm.update', $lead->uuid), $this->payload())->assertForbidden();
            $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'contacted'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->get(route('partner.crm.index'))->assertForbidden();
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_invalid_pcr_and_crm_data_are_rejected_without_changing_history(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('partner.pcr.store'), ['name' => '', 'email' => 'invalid'])->assertSessionHasErrors(['name', 'email']);
        $this->assertDatabaseCount('leads', 0);
        $lead = $this->createLeadThroughPcr($agent);
        $this->put(route('partner.crm.update', $lead->uuid), array_replace($this->payload(), ['email' => 'invalid']))->assertSessionHasErrors('email');
        $this->put(route('partner.crm.advance', $lead->uuid), ['status' => 'unknown'])->assertSessionHasErrors('status');
        $this->assertSame('jane@example.test', $lead->fresh()->email);
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertDatabaseCount('lead_stage_history', 1);
    }
}
