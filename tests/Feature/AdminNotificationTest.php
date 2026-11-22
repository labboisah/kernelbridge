<?php

namespace Tests\Feature;

use App\Enums\BusinessNotificationType;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BusinessNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(string $type, string $identifier, array $channels = ['database', 'mail']): array
    {
        return ['subject' => 'Scheduled maintenance', 'message' => "First line\nSecond line", 'audience_type' => $type, 'audience_identifier' => $identifier, 'channels' => $channels];
    }

    public function test_admin_sends_to_individual_with_selected_channels_and_cannot_spoof_sender(): void
    {
        Notification::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $other = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id), 'created_by' => $other->id,
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $this->assertSame($admin->id, $message->created_by);
        $this->assertSame(1, $message->recipient_count);
        Notification::assertSentTo($recipient, BusinessNotification::class, fn ($notification, $channels) => $channels === ['database', 'mail']
            && $notification->businessType === BusinessNotificationType::AdminMessage
            && $notification->toArray($recipient)['message'] === "First line\nSecond line");
        Notification::assertNotSentTo($other, BusinessNotification::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.notification.submitted', 'actor_id' => $admin->id]);
    }

    public function test_real_notification_pipeline_writes_inbox_and_sends_mail_to_array_transport(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->user();
        $recipient = $this->user('partner');

        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame('Scheduled maintenance', $recipient->notifications()->first()->data['subject']);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame($recipient->email, $email->getTo()[0]->getAddress());
        $this->assertSame('Scheduled maintenance', $email->getSubject());
        $customer = Customer::factory()->create(['email' => 'external@example.test']);
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['mail']))->assertSessionHasNoErrors();
        $this->assertCount(2, $messages);
        $this->assertSame('external@example.test', $messages->last()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_selected_channels_are_queued_on_the_notifications_queue(): void
    {
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
            ->assertSessionHasNoErrors();
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->queue === 'notifications' && $job->channels === ['database']);
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->queue === 'notifications' && $job->channels === ['mail']);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    public function test_database_queue_worker_delivers_both_channels(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(0, $recipient->notifications()->count());
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1,
        ]));
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_customer_group_includes_linked_users_and_unique_email_only_customers(): void
    {
        Notification::fake();
        $admin = $this->user();
        $roleCustomer = $this->user('customer');
        $linkedUser = $this->user('partner');
        $linkedCustomer = Customer::factory()->create(['email' => 'linked@example.test']);
        $linkedCustomer->users()->attach($linkedUser);
        Customer::factory()->create(['email' => 'external@example.test']);
        Customer::factory()->create(['email' => 'EXTERNAL@example.test']);
        Customer::factory()->create(['email' => $roleCustomer->email]);
        Customer::factory()->create(['email' => null]);
        Customer::factory()->create(['email' => 'invalid-address']);
        $outsider = $this->user('partner');

        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('role', 'customer'))->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $this->assertSame(3, $message->recipient_count);
        $this->assertSame(1, $message->email_only_count);
        $this->assertSame(2, $message->unreachable_count);
        Notification::assertSentTo($roleCustomer, BusinessNotification::class);
        Notification::assertSentTo($linkedUser, BusinessNotification::class);
        Notification::assertNotSentTo($outsider, BusinessNotification::class);
        Notification::assertSentOnDemandTimes(BusinessNotification::class, 1);
        Notification::assertSentOnDemand(BusinessNotification::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $channels === ['mail'] && strtolower($notifiable->routes['mail']) === 'external@example.test');
    }

    public function test_multiple_roles_preview_and_send_the_same_unique_recipients(): void
    {
        Notification::fake();
        $admin = $this->user();
        $both = $this->user('partner');
        $both->assignRole('sales');
        $sales = $this->user('sales');
        $linked = $this->user('developer');
        $customer = Customer::factory()->create();
        $customer->users()->attach($linked);
        Customer::factory()->create(['email' => 'external@example.test']);
        Customer::factory()->create(['email' => 'EXTERNAL@example.test']);
        Customer::factory()->create(['email' => $both->email]);
        Customer::factory()->create(['email' => null]);
        $outsider = $this->user('finance');
        $roles = ['partner', 'sales', 'customer'];

        $response = $this->actingAs($admin)->getJson(route('admin.notifications.role-recipients', ['roles' => $roles]))
            ->assertOk()->assertJsonCount(4, 'recipients')->assertJsonPath('unreachable_customers', 1)
            ->assertJsonFragment(['name' => $both->name, 'email' => $both->email, 'type' => 'user'])
            ->assertJsonFragment(['name' => $linked->name, 'email' => $linked->email, 'type' => 'user']);
        $this->assertSame(1, collect($response->json('recipients'))->filter(fn ($recipient) => strtolower($recipient['email']) === 'external@example.test')->count());
        $this->assertStringNotContainsString($outsider->email, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->post(route('admin.notifications.store'), [
            ...$this->payload('role', ''), 'roles' => $roles,
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $this->assertSame('partner,sales,customer', $message->audience_identifier);
        $this->assertSame('Roles: partner, sales, customer', $message->audience_label);
        $this->assertSame(4, $message->recipient_count);
        Notification::assertSentToTimes($both, BusinessNotification::class, 1);
        Notification::assertSentToTimes($sales, BusinessNotification::class, 1);
        Notification::assertSentToTimes($linked, BusinessNotification::class, 1);
        Notification::assertNotSentTo($outsider, BusinessNotification::class);
        Notification::assertSentOnDemandTimes(BusinessNotification::class, 1);
    }

    public function test_duplicate_account_email_is_sent_once_but_each_account_gets_in_app_notification(): void
    {
        Notification::fake();
        $admin = $this->user();
        $first = $this->user('partner');
        $first->update(['email' => 'shared@example.test']);
        $second = $this->user('sales');
        $second->update(['email' => 'SHARED@example.test']);
        $roles = ['partner', 'sales'];
        $this->actingAs($admin)->getJson(route('admin.notifications.role-recipients', ['roles' => $roles]))
            ->assertOk()->assertJsonCount(1, 'recipients');
        $this->post(route('admin.notifications.store'), [...$this->payload('role', ''), 'roles' => $roles])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($first, BusinessNotification::class, fn ($notification, $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($second, BusinessNotification::class, fn ($notification, $channels) => $channels === ['database']);
        $this->assertSame(2, AdminNotification::firstOrFail()->recipient_count);
    }

    public function test_multiple_roles_support_email_only_and_in_app_only_delivery(): void
    {
        Notification::fake();
        $admin = $this->user();
        $partner = $this->user('partner');
        $partner->update(['email' => 'shared@example.test']);
        $sales = $this->user('sales');
        $sales->update(['email' => 'SHARED@example.test']);
        $roles = ['partner', 'sales'];
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('role', ''), 'roles' => $roles, 'channels' => ['mail'],
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($partner, BusinessNotification::class, fn ($notification, $channels) => $channels === ['mail']);
        Notification::assertNotSentTo($sales, BusinessNotification::class);
        $this->assertSame(1, AdminNotification::firstOrFail()->recipient_count);

        Notification::fake();
        $this->post(route('admin.notifications.store'), [
            ...$this->payload('role', ''), 'roles' => $roles, 'channels' => ['database'],
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($partner, BusinessNotification::class, fn ($notification, $channels) => $channels === ['database']);
        Notification::assertSentTo($sales, BusinessNotification::class, fn ($notification, $channels) => $channels === ['database']);
        $this->assertSame(2, AdminNotification::latest('id')->firstOrFail()->recipient_count);
    }

    public function test_role_preview_is_complete_restricted_and_reports_invalid_addresses(): void
    {
        $admin = $this->user();
        $unauthorized = $this->user('partner');
        foreach (range(1, 25) as $index) {
            $user = $this->user('sales');
            $user->update(['email' => "sales{$index}@example.test"]);
        }
        $invalid = $this->user('sales');
        $invalid->update(['email' => 'invalid-address']);
        $route = route('admin.notifications.role-recipients', ['roles' => ['sales']]);
        $this->actingAs($unauthorized)->getJson($route)->assertForbidden();
        $this->actingAs($admin)->getJson($route)->assertOk()
            ->assertJsonCount(25, 'recipients')->assertJsonCount(1, 'invalid_users')
            ->assertJsonFragment(['name' => $invalid->name, 'email' => 'invalid-address']);
        $this->getJson(route('admin.notifications.role-recipients'))->assertUnprocessable();
        $this->getJson(route('admin.notifications.role-recipients', ['roles' => ['sales', 'sales']]))->assertUnprocessable();
        $this->getJson(route('admin.notifications.role-recipients', ['roles' => ['unknown']]))->assertUnprocessable();
    }

    public function test_multiple_role_sends_reject_invalid_roles_and_user_emails(): void
    {
        $admin = $this->user();
        $invalid = $this->user('sales');
        $invalid->update(['email' => 'invalid-address']);
        $this->actingAs($admin);
        $this->post(route('admin.notifications.store'), [...$this->payload('role', ''), 'roles' => []])
            ->assertSessionHasErrors();
        $this->post(route('admin.notifications.store'), [...$this->payload('role', ''), 'roles' => ['sales', 'unknown']])
            ->assertSessionHasErrors('roles.1');
        $this->post(route('admin.notifications.store'), [...$this->payload('role', ''), 'roles' => ['sales', 'sales']])
            ->assertSessionHasErrors('roles.1');
        $this->post(route('admin.notifications.store'), [...$this->payload('role', ''), 'roles' => ['sales']])
            ->assertSessionHasErrors('channels');
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_email_only_customer_requires_email_channel_and_can_be_selected(): void
    {
        Notification::fake();
        $admin = $this->user();
        $customer = Customer::factory()->create(['name' => 'External Company', 'email' => 'external@example.test']);
        $this->actingAs($admin)->getJson(route('admin.notifications.recipients', ['search' => 'External']))
            ->assertOk()->assertJsonPath('customers.0.id', $customer->uuid);
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['database']))
            ->assertSessionHasErrors('audience_identifier');
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['mail']))
            ->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(BusinessNotification::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $channels === ['mail'] && $notifiable->routes['mail'] === 'external@example.test');
        $this->assertSame(1, AdminNotification::firstOrFail()->email_only_count);
        $customer->users()->attach($this->user('customer'));
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['mail']))->assertNotFound();
    }

    public function test_validation_and_admin_only_access(): void
    {
        Notification::fake();
        $admin = $this->user();
        $user = $this->user('partner');
        $this->actingAs($user)->get(route('admin.notifications.index'))->assertForbidden();
        $this->getJson(route('admin.notifications.recipients', ['search' => 'abc']))->assertForbidden();
        $this->post(route('admin.notifications.store'), $this->payload('role', 'partner'))->assertForbidden();
        $this->post(route('admin.notification-templates.store'), ['name' => 'x', 'subject' => 'x', 'message' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('role', 'unknown'))->assertSessionHasErrors('audience_identifier');
        $this->post(route('admin.notifications.store'), $this->payload('role', 'partner', []))->assertSessionHasErrors('channels');
        $this->post(route('admin.notifications.store'), $this->payload('role', 'partner', ['mail', 'mail']))->assertSessionHasErrors('channels.1');
        config(['notifications.channels.mail' => false]);
        $this->post(route('admin.notifications.store'), $this->payload('user', $user->public_id))->assertSessionHasErrors('channels');
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_templates_can_be_saved_updated_reused_and_deleted(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->post(route('admin.notification-templates.store'), [
            'name' => 'Maintenance', 'subject' => 'Original', 'message' => 'Original body',
        ])->assertSessionHasNoErrors();
        $template = NotificationTemplate::firstOrFail();
        $this->get(route('admin.notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Notifications/Index')->where('templates.0.subject', 'Original'));
        $this->put(route('admin.notification-templates.update', $template->uuid), [
            'name' => 'Maintenance', 'subject' => 'Updated', 'message' => 'Updated body',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Updated', $template->fresh()->subject);
        $this->delete(route('admin.notification-templates.destroy', $template->uuid))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notification_templates', 0);
    }

    public function test_inbox_and_read_actions_are_scoped_to_the_current_user(): void
    {
        $first = $this->user('partner');
        $other = $this->user('customer');
        $first->notifyNow(new BusinessNotification(BusinessNotificationType::AdminMessage, 'Private one', 'First', channels: ['database']));
        $first->notifyNow(new BusinessNotification(BusinessNotificationType::AdminMessage, 'Private two', 'Second', channels: ['database']));
        $other->notifyNow(new BusinessNotification(BusinessNotificationType::AdminMessage, 'Other private', 'Third', channels: ['database']));
        $ownId = $first->notifications()->first()->id;
        $otherId = $other->notifications()->first()->id;

        $this->actingAs($first)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('notifications.data', 2)->where('auth.unread_notifications', 2));
        $this->put(route('notifications.read', $otherId))->assertNotFound();
        $this->put(route('notifications.read', $ownId))->assertRedirect();
        $this->assertSame(1, $first->unreadNotifications()->count());
        $this->put(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $first->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }
}
