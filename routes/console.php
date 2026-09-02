<?php

use App\Domain\Automation\Services\ScheduledAutomationService;
use App\Domain\Demo\Services\DemoModeService;
use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Subscriptions\Services\RenewalNoticeService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Support\Services\SupportTicketService;
use App\Enums\UserRole;
use App\Models\ApiClientCredential;
use App\Models\Customer;
use App\Models\DemoProfile;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('invoices:mark-overdue', function (InvoiceManager $manager): void {
    $this->info($manager->markOverdue().' invoice(s) marked overdue.');
})->purpose('Mark past-due invoices with outstanding balances as overdue');
Schedule::command('invoices:mark-overdue')->dailyAt('00:15')->withoutOverlapping();

Artisan::command('subscriptions:process-lifecycle', function (SubscriptionService $service): void {
    $result = $service->processLifecycle();
    $this->info("{$result['grace']} entered grace; {$result['expired']} expired.");
})->purpose('Move ended subscriptions through grace and expiration');

Artisan::command('subscriptions:send-renewal-notices', function (RenewalNoticeService $service): void {
    $this->info($service->sendDueNotices().' renewal notice(s) queued.');
})->purpose('Queue upcoming subscription renewal notices');

Schedule::command('subscriptions:send-renewal-notices')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('automation:subscriptions', function (ScheduledAutomationService $automation, SubscriptionService $service): void {
    $result = $automation->subscriptions($service);
    $this->info("{$result['grace']} entered grace; {$result['expired']} expired.");
})->purpose('Process subscription expiry, grace periods, and lifecycle notifications');

Artisan::command('automation:licenses', function (ScheduledAutomationService $automation): void {
    $result = $automation->licenses();
    $this->info("{$result['reminded']} reminder(s); {$result['expired']} license(s) expired.");
})->purpose('Send license reminders and expire elapsed licenses');

Artisan::command('automation:demo-cleanup', function (ScheduledAutomationService $automation): void {
    $this->info($automation->cleanupDemoMetadata().' stale demo session(s) finalized.');
})->purpose('Finalize stale demo session metadata without modifying product databases');

Artisan::command('automation:lead-follow-ups', function (ScheduledAutomationService $automation): void {
    $this->info($automation->staleLeadReminders().' lead reminder(s) queued.');
})->purpose('Queue reminders for stale and overdue lead follow-ups');

Schedule::command('automation:subscriptions')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('automation:licenses')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('automation:demo-cleanup')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('automation:lead-follow-ups')->weekdays()->dailyAt('08:30')->withoutOverlapping()->onOneServer();

Artisan::command('support:mark-sla-breaches', function (SupportTicketService $service): void {
    $this->info($service->markSlaBreaches().' ticket(s) marked as SLA breached.');
})->purpose('Mark unresolved support tickets that exceeded SLA deadlines');
Schedule::command('support:mark-sla-breaches')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('api-client:issue {product} {name=primary}', function (ApiCredentialService $service): void {
    $product = Product::query()->where('code', strtoupper($this->argument('product')))->firstOrFail();
    $client = $service->createClient($product, $product->name.' integration');
    $result = $service->issue($client, $this->argument('name'));
    $this->warn('Copy this token now; it will not be shown again.');
    $this->line($result['token']);
})->purpose('Issue a machine credential for a product');

Artisan::command('api-client:rotate {credential} {name=rotated}', function (ApiCredentialService $service): void {
    $credential = ApiClientCredential::query()->with('client')->where('uuid', $this->argument('credential'))->firstOrFail();
    $result = $service->rotate($credential, $this->argument('name'));
    $this->warn('The previous credential is revoked. Copy this token now.');
    $this->line($result['token']);
})->purpose('Rotate and revoke a product API credential');

Artisan::command('api-client:revoke {credential}', function (ApiCredentialService $service): void {
    $credential = ApiClientCredential::query()->where('uuid', $this->argument('credential'))->firstOrFail();
    $service->revoke($credential);
    $this->info('Credential revoked.');
})->purpose('Revoke a product API credential');

Artisan::command('demo:issue-access-token {product} {--uses=} {--hours=24} {--label=public-demo}', function (DemoModeService $service): void {
    $profile = DemoProfile::query()->whereHas('product', fn ($query) => $query->where('code', strtoupper($this->argument('product'))))->firstOrFail();
    $expires = now()->addHours((int) $this->option('hours'));
    $uses = $this->option('uses') !== null ? (int) $this->option('uses') : null;
    $issued = $service->issueToken($profile, $this->option('label'), $uses, $expires);
    $this->warn('Copy this demo access token now; it will not be shown again.');
    $this->line($issued['access_token']);
})->purpose('Issue a limited public demo access token');

Artisan::command('customer:link-user {customer} {email} {--primary}', function (): void {
    $customer = Customer::query()->where('uuid', $this->argument('customer'))->orWhere('customer_number', $this->argument('customer'))->firstOrFail();
    $user = User::query()->where('email', strtolower($this->argument('email')))->firstOrFail();
    DB::transaction(function () use ($customer, $user): void {
        $user->assignRole(UserRole::Customer->value);
        if ($this->option('primary')) {
            DB::table('customer_user')->where('customer_id', $customer->id)->update(['is_primary' => false, 'updated_at' => now()]);
        }
        $customer->users()->syncWithoutDetaching([$user->id => ['is_primary' => (bool) $this->option('primary')]]);
    });
    $this->info("{$user->email} linked to {$customer->customer_number}.");
})->purpose('Link a verified portal login to a customer account');

Artisan::command('licenses:issue {subscription} {--max-activations=1}', function (LicenseService $licenses): void {
    $subscription = Subscription::query()->where('uuid', $this->argument('subscription'))->orWhere('subscription_number', $this->argument('subscription'))->firstOrFail();
    $issued = $licenses->issue($subscription, (int) $this->option('max-activations'));
    $this->warn('Keep this license key private. It is hashed for verification and encrypted for authorized customer retrieval.');
    $this->line($issued['license_key']);
})->purpose('Issue a one-time license key for an eligible subscription');
