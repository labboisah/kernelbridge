<?php

namespace Tests\Feature;

use App\Domain\Automation\Services\ScheduledAutomationService;
use App\Enums\BusinessNotificationType;
use App\Enums\UserRole;
use App\Models\DemoProfile;
use App\Models\DemoSession;
use App\Models\Lead;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\User;
use App\Notifications\BusinessNotification;
use Database\Seeders\DemoProfileSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class]);
    }

    public function test_business_notifications_are_queued_for_database_and_email(): void
    {
        $notification = new BusinessNotification(BusinessNotificationType::PaymentVerified, 'Payment verified', 'Your payment was verified.');

        $this->assertSame(['database', 'mail'], $notification->via(User::factory()->make()));
        $this->assertContains(ShouldQueue::class, class_implements($notification));
        $this->assertSame('payment_verified', $notification->toArray(User::factory()->make())['type']);
    }

    public function test_stale_lead_reminders_are_idempotent_per_day(): void
    {
        Notification::fake();
        $sales = User::factory()->create();
        $sales->assignRole(UserRole::Sales->value);
        Lead::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Stale Prospect', 'email' => 'stale@example.test',
            'status' => 'contacted', 'assigned_to' => $sales->id, 'follow_up_date' => today()->subDay(),
        ]);

        $service = app(ScheduledAutomationService::class);
        $this->assertSame(1, $service->staleLeadReminders());
        $this->assertSame(0, $service->staleLeadReminders());
        $this->assertDatabaseCount('automation_notifications', 1);
        Notification::assertSentToTimes($sales, BusinessNotification::class, 1);
    }

    public function test_demo_cleanup_only_finalizes_stale_open_sessions(): void
    {
        $product = Product::query()->firstOrFail();
        $session = DemoSession::query()->create([
            'product_id' => $product->id, 'demo_profile_id' => DemoProfile::query()->where('product_id', $product->id)->value('id'),
            'anonymous_identifier_hash' => hash('sha256', 'demo'),
            'started_at' => now()->subDays(2), 'last_activity_at' => now()->subDays(2),
        ]);

        $service = app(ScheduledAutomationService::class);
        $this->assertSame(1, $service->cleanupDemoMetadata());
        $this->assertSame(0, $service->cleanupDemoMetadata());
        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('stale_session', $session->fresh()->metadata['cleanup']['reason']);
    }

    public function test_license_expiration_is_idempotent(): void
    {
        $license = SoftwareLicense::factory()->create(['status' => 'active', 'expires_at' => now()->subMinute()]);
        $service = app(ScheduledAutomationService::class);

        $this->assertSame(1, $service->licenses()['expired']);
        $this->assertSame('expired', $license->fresh()->status);
        $this->assertSame(0, $service->licenses()['expired']);
    }
}
