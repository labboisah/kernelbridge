<?php

namespace Tests\Feature;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\BusinessNotificationType;
use App\Jobs\DeliverAdminNotification;
use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BusinessNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_html_markup_in_plain_business_notification_is_rendered_in_the_email(): void
    {
        config(['mail.default' => 'array']);
        $recipient = $this->user('partner');

        Notification::sendNow($recipient, new BusinessNotification(
            BusinessNotificationType::AdminMessage,
            'Onboarding reminder',
            '<p>Dear Partner,</p><p>Please complete your onboarding application.</p>',
            channels: ['mail'],
        ));

        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('<p>Dear Partner,</p>', $email->getHtmlBody());
        $this->assertStringContainsString('Please complete your onboarding application.', $email->getTextBody());
        $this->assertStringNotContainsString('<p>', $email->getTextBody());
    }

    private function payload(string $type, string $identifier, array $channels = ['database', 'mail']): array
    {
        return ['subject' => 'Scheduled maintenance', 'message' => "First line\nSecond line", 'audience_type' => $type, 'audience_identifier' => $identifier, 'channels' => $channels];
    }

    public function test_admin_sends_to_individual_with_selected_channels_and_cannot_spoof_sender(): void
    {
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $other = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id), 'created_by' => $other->id,
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $this->assertSame($admin->id, $message->created_by);
        $this->assertSame(1, $message->recipient_count);
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $recipient->id && $job->channel === 'database');
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $recipient->id && $job->channel === 'mail');
        Queue::assertNotPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $other->id);
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
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->queue === 'notifications' && $job->channel === 'database');
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->queue === 'notifications' && $job->channel === 'mail');
        Queue::assertPushed(DeliverAdminNotification::class, 2);
    }

    public function test_progress_requests_do_not_consume_the_notification_send_limit(): void
    {
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin);
        $url = route('admin.notifications.progress', ['messages' => [(string) Str::uuid()]]);
        for ($i = 0; $i < 12; $i++) {
            $this->getJson($url)->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))->assertStatus(429);
        $this->assertDatabaseCount('admin_notifications', 10);
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

    public function test_in_app_only_role_delivery_appears_in_recipient_inbox_after_queue_processing(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('role', '', ['database']), 'roles' => ['partner'],
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $this->getJson(route('admin.notifications.progress', ['messages' => [$message->uuid]]))
            ->assertJsonPath("progress.{$message->uuid}.database.sent", 1);
        $this->actingAs($recipient)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_notifications', 1)
            ->where('notifications.data.0.data.subject', 'Scheduled maintenance')
            ->where('notifications.data.0.data.message', "First line\nSecond line"));
    }

    public function test_individual_search_result_and_composer_payload_deliver_to_selected_account(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $result = $this->actingAs($admin)->getJson(route('admin.notifications.recipients', ['search' => $recipient->email]))
            ->assertOk()->json('users.0');
        $this->assertSame($recipient->public_id, $result['id']);
        $this->post(route('admin.notifications.store'), [
            ...$this->payload($result['type'], $result['id'], ['database']), 'roles' => [],
        ])->assertSessionHasNoErrors();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $this->actingAs($recipient)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_notifications', 1)
            ->where('notifications.data.0.data.subject', 'Scheduled maintenance'));
    }

    public function test_formatted_message_and_attachments_reach_email_and_authorized_inbox(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $outsider = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id),
            'message_format' => 'html',
            'message' => '<h2>News</h2><p><strong>Important</strong> <a href="javascript:alert(1)">link</a><script>alert(1)</script></p>',
            'attachments' => [UploadedFile::fake()->createWithContent('notice.txt', 'private notice')],
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::with('attachments')->firstOrFail();
        $attachment = $message->attachments->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringContainsString('<strong>Important</strong>', $message->message);
        $this->assertStringNotContainsString('javascript:', $message->message);
        $this->assertStringNotContainsString('<script', $message->message);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('<strong>Important</strong>', $email->getHtmlBody());
        $this->assertStringNotContainsString('javascript:', $email->getHtmlBody());
        $this->assertStringContainsString('Important', $email->getTextBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('notice.txt', $email->getAttachments()[0]->getFilename());
        $url = route('notifications.attachments.download', $attachment->uuid);
        $preview = route('notifications.attachments.preview', $attachment->uuid);
        $this->get($url)->assertOk();
        $this->get($preview)->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox")
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', 'inline; filename=notice.txt');
        $this->actingAs($outsider)->get($url)->assertNotFound();
        $this->get($preview)->assertNotFound();
        $this->actingAs($recipient)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_notifications', 1)
            ->where('notifications.data.0.formatted_message', $message->message)
            ->where('notifications.data.0.attachments.0.uuid', $attachment->uuid)
            ->missing('notifications.data.0.attachments.0.path'));
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($preview)->assertOk();
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp'] as $mime) {
            $attachment->update(['mime_type' => $mime]);
            $this->get($preview)->assertOk()->assertHeader('Content-Type', $mime);
        }
        $attachment->update(['mime_type' => 'text/html']);
        $this->get($preview)->assertStatus(415);
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=notice.txt');
        Storage::disk('local')->delete($attachment->path);
        $this->get($preview)->assertNotFound();
        $this->get(route('notifications.attachments.download', (string) Str::uuid()))->assertNotFound();
    }

    public function test_inbox_uses_linked_format_for_older_deliveries_without_guessing_plain_text(): void
    {
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id, ['database']),
            'message_format' => 'html',
            'message' => '<h2>News</h2><p>Important</p>',
        ])->assertSessionHasNoErrors();
        $submission = AdminNotification::firstOrFail();
        $notification = $recipient->notifications()->firstOrFail();
        $data = $notification->data;
        unset($data['context']['message_format']);
        $data['message'] = '<h2>News</h2><script>alert(1)</script><p>Important</p>';
        $notification->update(['data' => $data]);

        $this->actingAs($recipient)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.formatted_message', '<h2>News</h2><p>Important</p>'));

        $data['context']['message_format'] = 'plain';
        $notification->update(['data' => $data]);
        $this->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->missing('notifications.data.0.formatted_message')
            ->where('notifications.data.0.data.message', $data['message']));

        unset($data['context']['message_format']);
        $notification->update(['data' => $data]);
        $submission->update(['message_format' => 'plain']);
        $this->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->missing('notifications.data.0.formatted_message')
            ->where('notifications.data.0.data.message', $data['message']));
    }

    public function test_notification_formatting_and_attachment_validation_and_templates(): void
    {
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id),
            'message_format' => 'html', 'message' => '<p><br></p>',
        ])->assertSessionHasErrors('message');
        $this->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id),
            'attachments' => [UploadedFile::fake()->create('bad.zip', 2, 'application/zip')],
        ])->assertSessionHasErrors('attachments.0');
        $this->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id),
            'attachments' => [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')],
        ])->assertSessionHasErrors('attachments.0');
        $files = array_map(fn ($i) => UploadedFile::fake()->createWithContent("notice{$i}.txt", 'notice'), range(1, 6));
        $this->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id), 'attachments' => $files,
        ])->assertSessionHasErrors('attachments');
        $this->post(route('admin.notification-templates.store'), [
            'name' => 'Formatted', 'subject' => 'Hello', 'message_format' => 'html',
            'message' => '<p><em>Styled</em><img src="https://example.test/a.png" onerror="alert(1)"></p>',
        ])->assertSessionHasNoErrors();
        $template = NotificationTemplate::firstOrFail();
        $this->assertSame('html', $template->message_format);
        $this->assertStringContainsString('<em>Styled</em>', $template->message);
        $this->assertStringNotContainsString('<img', $template->message);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_email_only_customer_receives_notification_attachment(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $admin = $this->user();
        $customer = Customer::factory()->create(['email' => 'external@example.test']);
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('customer', $customer->uuid, ['mail']),
            'attachments' => [UploadedFile::fake()->createWithContent('notice.txt', 'private notice')],
        ])->assertSessionHasNoErrors();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('external@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('notice.txt', $email->getAttachments()[0]->getFilename());
    }

    public function test_missing_attachment_fails_delivery_instead_of_sending_incomplete_email(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('user', $recipient->public_id, ['mail']),
            'attachments' => [UploadedFile::fake()->createWithContent('notice.txt', 'private notice')],
        ])->assertSessionHasNoErrors();
        $message = AdminNotification::with('attachments')->firstOrFail();
        Storage::disk('local')->delete($message->attachments->sole()->path);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $this->getJson(route('admin.notifications.progress', ['messages' => [$message->uuid]]))
            ->assertJsonPath("progress.{$message->uuid}.mail.failed", 1);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_attachment_is_removed_when_notification_transaction_rolls_back(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed'));
        $this->app->instance(AuditRecorder::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.notifications.store'), [
                ...$this->payload('user', $recipient->public_id),
                'attachments' => [UploadedFile::fake()->createWithContent('notice.txt', 'private notice')],
            ]);
            $this->fail('The failed transaction should be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('admin_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('admin-notification-attachments'));
    }

    public function test_progress_tracks_each_channel_failure_and_retry(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'missing-transport']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
            ->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $url = route('admin.notifications.progress', ['messages' => [$message->uuid]]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath("progress.{$message->uuid}.database.pending", 1)
            ->assertJsonPath("progress.{$message->uuid}.mail.pending", 1);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath("progress.{$message->uuid}.database.sent", 1)
            ->assertJsonPath("progress.{$message->uuid}.mail.failed", 1);
        $this->assertDatabaseCount('failed_jobs', 1);
        config(['mail.default' => 'array']);
        Artisan::call('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]]);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath("progress.{$message->uuid}.database.sent", 1)
            ->assertJsonPath("progress.{$message->uuid}.mail.sent", 1)
            ->assertJsonPath("progress.{$message->uuid}.mail.failed", 0);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->actingAs($recipient)->getJson($url)->assertForbidden();
    }

    public function test_progress_identifies_untracked_historical_submissions(): void
    {
        Queue::fake();
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id))
            ->assertSessionHasNoErrors();
        $message = AdminNotification::firstOrFail();
        $message->update(['delivery_tracking' => false]);
        $this->getJson(route('admin.notifications.progress', ['messages' => [$message->uuid]]))
            ->assertOk()->assertJsonPath("progress.{$message->uuid}", null);
        $this->getJson(route('admin.notifications.progress', ['messages' => ['invalid']]))->assertUnprocessable();
    }

    public function test_suppressed_and_repeated_deliveries_are_not_reported_as_new_successes(): void
    {
        Queue::fake();
        config(['mail.default' => 'array']);
        $admin = $this->user();
        $recipient = $this->user('partner');
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->payload('user', $recipient->public_id, ['mail']))
            ->assertSessionHasNoErrors();
        $job = Queue::pushed(DeliverAdminNotification::class)->first();
        Event::listen(NotificationSending::class, fn () => false);
        try {
            $job->handle();
            $this->fail('A suppressed notification was reported as sent.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The notification was not sent by its channel.', $exception->getMessage());
            $this->assertDatabaseHas('admin_notification_deliveries', ['id' => $job->deliveryId, 'status' => 'pending']);
            $job->failed($exception);
            $this->assertDatabaseHas('admin_notification_deliveries', ['id' => $job->deliveryId, 'status' => 'failed']);
        } finally {
            Event::forget(NotificationSending::class);
        }
        $job->handle();
        $job->handle();
        $this->assertDatabaseHas('admin_notification_deliveries', ['id' => $job->deliveryId, 'status' => 'sent']);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_customer_group_includes_linked_users_and_unique_email_only_customers(): void
    {
        Queue::fake();
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
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $roleCustomer->id);
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $linkedUser->id);
        Queue::assertNotPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $outsider->id);
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->channel === 'mail' && strtolower($job->email ?? '') === 'external@example.test');
        Queue::assertPushed(DeliverAdminNotification::class, 5);
    }

    public function test_multiple_roles_preview_and_send_the_same_unique_recipients(): void
    {
        Queue::fake();
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
        foreach ([$both, $sales, $linked] as $user) {
            Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $user->id && $job->channel === 'database');
            Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $user->id && $job->channel === 'mail');
        }
        Queue::assertNotPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $outsider->id);
        Queue::assertPushed(DeliverAdminNotification::class, 7);
    }

    public function test_duplicate_account_email_is_sent_once_but_each_account_gets_in_app_notification(): void
    {
        Queue::fake();
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
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $first->id && $job->channel === 'mail');
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $second->id && $job->channel === 'database');
        Queue::assertNotPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $second->id && $job->channel === 'mail');
        $this->assertSame(2, AdminNotification::firstOrFail()->recipient_count);
    }

    public function test_multiple_roles_support_email_only_and_in_app_only_delivery(): void
    {
        Queue::fake();
        $admin = $this->user();
        $partner = $this->user('partner');
        $partner->update(['email' => 'shared@example.test']);
        $sales = $this->user('sales');
        $sales->update(['email' => 'SHARED@example.test']);
        $roles = ['partner', 'sales'];
        $this->actingAs($admin)->post(route('admin.notifications.store'), [
            ...$this->payload('role', ''), 'roles' => $roles, 'channels' => ['mail'],
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $partner->id && $job->channel === 'mail');
        Queue::assertNotPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $sales->id);
        $this->assertSame(1, AdminNotification::firstOrFail()->recipient_count);

        $this->post(route('admin.notifications.store'), [
            ...$this->payload('role', ''), 'roles' => $roles, 'channels' => ['database'],
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $partner->id && $job->channel === 'database');
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->userId === $sales->id && $job->channel === 'database');
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
        Queue::fake();
        $admin = $this->user();
        $customer = Customer::factory()->create(['name' => 'External Company', 'email' => 'external@example.test']);
        $this->actingAs($admin)->getJson(route('admin.notifications.recipients', ['search' => 'External']))
            ->assertOk()->assertJsonPath('customers.0.id', $customer->uuid);
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['database']))
            ->assertSessionHasErrors('audience_identifier');
        $this->post(route('admin.notifications.store'), $this->payload('customer', $customer->uuid, ['mail']))
            ->assertSessionHasNoErrors();
        Queue::assertPushed(DeliverAdminNotification::class, fn ($job) => $job->channel === 'mail' && $job->email === 'external@example.test');
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
