<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SupportManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_customer_creates_numbered_ticket_with_sla_attachment_and_notification(): void
    {
        Notification::fake();
        Storage::fake('local');
        Carbon::setTestNow('2026-08-31 09:00:00');
        [$customer, $user, $product] = $this->customerAccount();
        $support = $this->supportAgent();
        $response = $this->actingAs($user)->post(route('support.store'), ['product_uuid' => $product->uuid, 'subject' => 'Cannot publish examination results', 'category' => 'technical', 'priority' => 'normal', 'message' => 'Publishing results returns an unexpected validation error.', 'attachments' => [UploadedFile::fake()->image('screen.png')]]);
        $ticket = SupportTicket::query()->firstOrFail();
        $response->assertRedirect(route('support.show', $ticket));
        $this->assertSame('KBT-SUP-000001', $ticket->ticket_number);
        $this->assertSame('2026-08-31 17:00:00', $ticket->first_response_due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 09:00:00', $ticket->resolution_due_at->format('Y-m-d H:i:s'));
        $attachment = $ticket->messages->first()->attachments->first();
        $this->assertNotNull($attachment);
        Storage::disk('local')->assertExists($attachment->path);
        Notification::assertSentTo($support, SupportTicketReply::class);
        Carbon::setTestNow();
    }

    public function test_support_agent_assigns_replies_notes_closes_and_reopens_ticket(): void
    {
        Notification::fake();
        [$customer, $customerUser, $product] = $this->customerAccount();
        $agent = $this->supportAgent();
        $ticket = $this->ticket($customer, $customerUser, $product);
        $this->actingAs($agent)->put(route('admin.support.update', $ticket), ['assigned_to' => $agent->id, 'priority' => 'high', 'status' => 'in_progress'])->assertRedirect();
        $this->actingAs($agent)->post(route('admin.support.messages.store', $ticket), ['body' => 'We are investigating the deployment logs.'])->assertRedirect();
        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_to);
        $this->assertNotNull($ticket->first_responded_at);
        $this->assertSame('in_progress', $ticket->status->value);
        Notification::assertSentOnDemand(SupportTicketReply::class);
        Notification::fake();
        $this->actingAs($agent)->post(route('admin.support.messages.store', $ticket), ['body' => 'Private diagnostic detail.', 'internal_note' => true]);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('support_messages', ['body' => 'Private diagnostic detail.', 'is_customer_visible' => false]);
        $this->actingAs($agent)->put(route('admin.support.update', $ticket), ['status' => 'resolved']);
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->actingAs($agent)->put(route('admin.support.update', $ticket), ['status' => 'closed']);
        $this->assertNotNull($ticket->fresh()->closed_at);
        $this->actingAs($agent)->put(route('admin.support.update', $ticket), ['status' => 'open']);
        $this->assertNull($ticket->fresh()->closed_at);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_customer_isolation_and_internal_notes_are_enforced(): void
    {
        [$customer, $owner, $product] = $this->customerAccount();
        $ticket = $this->ticket($customer, $owner, $product);
        [, $other] = $this->customerAccount('KBT-CUS-000002', 'other@example.com');
        $this->actingAs($other)->get(route('support.show', $ticket))->assertForbidden();
        $this->actingAs($owner)->post(route('support.messages.store', $ticket), ['body' => 'Attempted private note', 'internal_note' => true])->assertForbidden();
    }

    public function test_filters_dashboard_counts_and_sla_breach_job_work(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        [$customer, $owner, $product] = $this->customerAccount();
        $ticket = $this->ticket($customer, $owner, $product);
        $ticket->update(['priority' => 'urgent', 'first_response_due_at' => now()->subMinute(), 'resolution_due_at' => now()->addHour()]);
        $agent = $this->supportAgent();
        $this->actingAs($agent)->get(route('admin.support.index', ['priority' => 'urgent']))->assertOk();
        $this->artisan('support:mark-sla-breaches')->assertSuccessful();
        $this->assertNotNull($ticket->fresh()->sla_breached_at);
        Carbon::setTestNow();
    }

    private function supportAgent(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Support->value);

        return $user;
    }

    public function test_support_manager_creates_edits_members_and_deletes_teams(): void
    {
        $manager = $this->supportAgent();
        $member = $this->supportAgent();

        $this->actingAs($manager)->post(route('admin.support-teams.store'), [
            'name' => 'Customer Success', 'slug' => 'customer-success', 'description' => 'Customer onboarding requests.', 'is_active' => true, 'sort_order' => 20,
        ])->assertRedirect();

        $team = SupportTeam::query()->where('slug', 'customer-success')->firstOrFail();
        $this->post(route('admin.support-teams.members.store', $team), ['user_public_id' => $member->public_id])->assertRedirect();
        $this->assertTrue($team->members()->whereKey($member->id)->exists());

        $this->put(route('admin.support-teams.update', $team), [
            'name' => 'Customer Experience', 'slug' => 'customer-experience', 'description' => 'Customer onboarding and adoption.', 'is_active' => false, 'sort_order' => 21,
        ])->assertRedirect();
        $this->assertDatabaseHas('support_teams', ['id' => $team->id, 'name' => 'Customer Experience', 'is_active' => false]);

        $this->delete(route('admin.support-teams.members.destroy', [$team, $member->public_id]))->assertRedirect();
        $this->assertFalse($team->members()->whereKey($member->id)->exists());
        $this->delete(route('admin.support-teams.destroy', $team))->assertRedirect();
        $this->assertDatabaseMissing('support_teams', ['id' => $team->id]);
    }

    private function customerAccount(string $number = 'KBT-CUS-000001', string $email = 'customer@example.com'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(UserRole::Customer->value);
        $customer = Customer::query()->create(['customer_number' => $number, 'type' => 'organization', 'name' => $number, 'email' => $email, 'status' => 'active']);
        $customer->users()->attach($user->id, ['is_primary' => true]);
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $customer->products()->attach($product->id);

        return [$customer, $user, $product];
    }

    private function ticket(Customer $customer, User $user, Product $product): SupportTicket
    {
        $ticket = SupportTicket::query()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'subject' => 'Deployment assistance', 'category' => 'deployment', 'priority' => 'normal', 'status' => 'open', 'opened_by' => $user->id, 'opened_at' => now(), 'first_response_due_at' => now()->addHours(8), 'resolution_due_at' => now()->addHours(72), 'last_customer_reply_at' => now()]);
        $ticket->forceFill(['ticket_number' => 'KBT-SUP-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->save();
        $ticket->messages()->create(['sender_id' => $user->id, 'type' => 'reply', 'body' => 'Please assist with deployment.', 'is_customer_visible' => true]);

        return $ticket;
    }
}
