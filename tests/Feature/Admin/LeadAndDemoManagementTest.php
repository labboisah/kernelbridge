<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DemoRequest;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ProspectDemoConfirmation;
use App\Notifications\SalesNewDemoRequest;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LeadAndDemoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_public_demo_submission_creates_lead_demo_activity_and_notifications(): void
    {
        Notification::fake();
        $sales = $this->userWithRole(UserRole::Sales);
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();

        $this->post(route('demo.store'), $this->demoPayload($product))->assertSessionHasNoErrors()->assertRedirect();
        $lead = Lead::query()->firstOrFail();
        $demo = DemoRequest::query()->firstOrFail();

        $this->assertSame('KBT-LEAD-000001', $lead->lead_number);
        $this->assertSame($lead->id, $demo->lead_id);
        $this->assertSame('pending', $demo->status->value);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'demo_requested']);
        Notification::assertSentTo($sales, SalesNewDemoRequest::class);
        Notification::assertSentOnDemand(ProspectDemoConfirmation::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'prospect@example.com');

        $this->post(route('demo.store'), $this->demoPayload($product))->assertRedirect();
        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(2, DemoRequest::query()->count());
    }

    public function test_crm_supports_table_pipeline_filters_assignment_follow_up_notes_and_demo_scheduling(): void
    {
        Notification::fake();
        $sales = $this->userWithRole(UserRole::Sales);
        $product = Product::query()->where('code', 'EDUNEXA')->firstOrFail();
        $this->post(route('demo.store'), $this->demoPayload($product));
        $lead = Lead::query()->firstOrFail();
        $demo = DemoRequest::query()->firstOrFail();

        $this->actingAs($sales)->get(route('admin.leads.index', ['view' => 'pipeline', 'status' => 'new', 'product_id' => $product->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Leads/Index')->has('leads', 1)->where('filters.view', 'pipeline'));

        $this->actingAs($sales)->put(route('admin.leads.update', $lead), ['status' => 'qualified', 'assigned_to' => $sales->id, 'follow_up_date' => now()->addWeek()->toDateString(), 'product_id' => $product->id])->assertRedirect();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified', 'assigned_to' => $sales->id]);

        $this->actingAs($sales)->post(route('admin.leads.notes.store', $lead), ['body' => 'Decision maker confirmed.', 'is_private' => true])->assertRedirect();
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'author_id' => $sales->id]);

        $scheduledAt = now()->addDays(3)->setTime(10, 0)->format('Y-m-d H:i:s');
        $this->actingAs($sales)->put(route('admin.demo-requests.update', $demo), ['status' => 'scheduled', 'scheduled_at' => $scheduledAt, 'assigned_to' => $sales->id])->assertRedirect();
        $this->assertDatabaseHas('demo_requests', ['id' => $demo->id, 'status' => 'scheduled', 'assigned_to' => $sales->id]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'demo_scheduled']);
    }

    public function test_lead_converts_to_customer_once_without_duplicating_information(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(UserRole::Admin);
        $product = Product::query()->where('code', 'MARKETLENS')->firstOrFail();
        $this->post(route('demo.store'), $this->demoPayload($product));
        $lead = Lead::query()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.leads.convert', $lead))->assertRedirect();
        $customer = Customer::query()->firstOrFail();
        $this->assertSame('Prospect Organization', $customer->name);
        $this->assertSame('prospect@example.com', $customer->email);
        $this->assertTrue($customer->products->contains($product));
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'converted_customer_id' => $customer->id, 'status' => 'won']);

        $this->actingAs($admin)->post(route('admin.leads.convert', $lead->fresh()))->assertRedirect(route('admin.customers.show', $customer));
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_dashboard_exposes_requested_sales_metrics(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(UserRole::Admin);
        $product = Product::query()->where('code', 'MEDIFLOW')->firstOrFail();
        $this->post(route('demo.store'), $this->demoPayload($product));
        $demo = DemoRequest::query()->firstOrFail();
        $demo->update(['status' => 'completed']);
        Lead::query()->firstOrFail()->update(['status' => 'won']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.newLeads', 0)->where('metrics.pendingDemos', 0)->where('metrics.scheduledDemos', 0)
            ->where('metrics.completedDemos', 1)->where('metrics.conversionRate', 100));
    }

    public function test_non_sales_users_cannot_access_crm(): void
    {
        $customer = $this->userWithRole(UserRole::Customer);
        $this->actingAs($customer)->get(route('admin.leads.index'))->assertForbidden();
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /** @return array<string, mixed> */
    private function demoPayload(Product $product): array
    {
        return [
            'name' => 'Prospect User', 'organization' => 'Prospect Organization', 'email' => 'prospect@example.com',
            'phone' => '+2348000000000', 'location' => 'Lagos, Nigeria', 'organization_type' => 'business',
            'product_id' => $product->public_id, 'number_of_users' => 150, 'preferred_demo_date' => now()->addWeek()->toDateString(),
            'preferred_demo_time' => '10:30', 'message' => 'We need a multi-branch walkthrough.', 'source' => 'Website',
        ];
    }
}
