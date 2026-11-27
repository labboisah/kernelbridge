<?php

namespace Tests\Feature;

use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Finance\Services\InvoiceManager;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LicenseActivationCode;
use App\Models\PartnerApplication;
use App\Models\PartnerClientInvitation;
use App\Models\Plan;
use App\Models\SoftwareLicense;
use App\Models\User;
use App\Notifications\PartnerClientMilestone;
use App\Notifications\PartnerClientRegistered;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerClientNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
        Notification::fake();
    }

    private function partner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(['partner', 'agent']);
        (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => 'sales_agent', 'status' => 'approved'])->save();

        return $user;
    }

    private function registerClient(User $partner, string $email = 'client@example.test'): array
    {
        $this->actingAs($partner)->post('/partner/clients', [
            'name' => 'Example Client', 'type' => 'organization', 'email' => $email,
        ])->assertSessionHasNoErrors();
        $client = Customer::where('email', $email)->firstOrFail();
        $invitation = null;
        Notification::assertSentOnDemand(PartnerClientRegistered::class, function ($notification, $channels, $notifiable) use ($email, &$invitation) {
            if (($notifiable->routes['mail'] ?? null) === $email) {
                $invitation = $notification;

                return true;
            }

            return false;
        });
        Notification::assertSentTo($partner, PartnerClientRegistered::class);

        return [$client, basename(parse_url($invitation->url, PHP_URL_PATH))];
    }

    public function test_registration_invites_client_and_token_creates_verified_linked_account_once(): void
    {
        $partner = $this->partner();
        [$client, $token] = $this->registerClient($partner);
        $this->assertDatabaseHas('partner_client_invitations', ['customer_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('partner_client_invitations', ['token_hash' => $token]);
        auth()->logout();
        $url = route('client-invitations.show', $token);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ClientInvitation')->where('existingAccount', false));
        $this->post(route('client-invitations.accept', $token), [
            'name' => 'Client User', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('login.two-factor'));
        $user = User::where('email', 'client@example.test')->firstOrFail();
        $this->verifyLoginCode($user)->assertRedirect(route('portal.services.index'));
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertTrue($user->hasRole('customer'));
        $this->assertTrue($user->customers()->whereKey($client->id)->exists());
        $this->get($url)->assertNotFound();
        $this->post(route('client-invitations.accept', $token))->assertNotFound();
    }

    public function test_invitation_existing_account_requires_matching_verified_sign_in(): void
    {
        $existing = User::factory()->create(['email' => 'client@example.test']);
        [$client, $token] = $this->registerClient($this->partner());
        auth()->logout();
        $url = route('client-invitations.show', $token);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('existingAccount', true)->where('signedIn', false));
        $this->post(route('client-invitations.accept', $token), [
            'name' => 'Other', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('email');
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('client-invitations.accept', $token))->assertForbidden();
        $existing->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($existing)->post(route('client-invitations.accept', $token))->assertForbidden();
        $existing->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($existing)->post(route('client-invitations.accept', $token))->assertRedirect(route('portal.services.index'));
        $this->assertTrue($existing->customers()->whereKey($client->id)->exists());
    }

    public function test_invitation_expires_and_resend_revokes_previous_link(): void
    {
        $partner = $this->partner();
        [$client, $token] = $this->registerClient($partner);
        $this->actingAs($partner)->post(route('partner.customers.invitation', $client->uuid))->assertSessionHasNoErrors();
        $this->assertNotSame(hash('sha256', $token), PartnerClientInvitation::where('customer_id', $client->id)->firstOrFail()->token_hash);
        $this->get(route('client-invitations.show', $token))->assertNotFound();
        $newToken = null;
        Notification::assertSentOnDemand(PartnerClientRegistered::class, function ($notification, $channels, $notifiable) use ($token, &$newToken) {
            $candidate = basename(parse_url($notification->url, PHP_URL_PATH));
            if (($notifiable->routes['mail'] ?? null) === 'client@example.test' && $candidate !== $token) {
                $newToken = $candidate;

                return true;
            }

            return false;
        });
        $this->travel(8)->days();
        $this->get(route('client-invitations.show', $newToken))->assertNotFound();
        $other = $this->partner();
        $this->actingAs($other)->post(route('partner.customers.invitation', $client->uuid))->assertNotFound();
    }

    public function test_invoice_and_payment_notifications_follow_verified_payment_and_provisioning(): void
    {
        $partner = $this->partner();
        [$client, $token] = $this->registerClient($partner);
        $plan = Plan::factory()->create(['price' => '10000.00']);
        $plan->product->update(['installation_guide_markdown' => '# Installation instructions']);
        Notification::fake();
        $this->actingAs($partner)->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        Notification::assertSentTo($partner, PartnerClientMilestone::class, fn ($n) =>
            str_contains($n->subject, $invoice->invoice_number) && $n->url === route('partner.invoices.show', $invoice->uuid));
        $manager = app(PaymentManager::class);
        $payment = $manager->create([
            'customer_id' => $client->id, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'payment_method' => 'cash', 'transaction_reference' => 'TEST-PAYMENT', 'payment_date' => today(),
        ], $partner);
        $this->assertCount(1, Notification::sent($partner, PartnerClientMilestone::class));
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]], $partner);
        $messages = Notification::sent($partner, PartnerClientMilestone::class);
        $this->assertCount(3, $messages);
        $this->assertTrue($messages->contains(fn ($n) => str_contains($n->subject, 'Payment confirmed')));
        $this->assertTrue($messages->contains(fn ($n) => str_contains($n->subject, 'Installation and activation')));
        Notification::assertSentOnDemand(PartnerClientMilestone::class, fn ($n, $channels, AnonymousNotifiable $recipient) =>
            ($recipient->routes['mail'] ?? null) === $client->email && str_contains($n->subject, 'Payment confirmed'));
        Notification::assertSentOnDemand(PartnerClientMilestone::class, fn ($n, $channels, AnonymousNotifiable $recipient) =>
            ($recipient->routes['mail'] ?? null) === $client->email && str_contains($n->subject, 'Installation and activation') && $n->url === route('portal.licenses.index'));
        auth()->logout();
        $this->post(route('client-invitations.accept', $token), [
            'name' => 'Client User', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('login.two-factor'));
        $user = User::where('email', $client->email)->firstOrFail();
        $this->verifyLoginCode($user)->assertRedirect(route('portal.services.index'));
        $subscription = $client->subscriptions()->firstOrFail();
        $this->get(route('portal.licenses.index'))->assertInertia(fn (Assert $page) => $page
            ->where('licenses.0.guide_url', route('portal.services.installation-guide', $subscription)));
        $this->get(route('portal.services.installation-guide', $subscription))->assertOk();
    }

    public function test_partial_payment_notifies_without_installation_and_repeat_verification_does_not_notify(): void
    {
        $partner = $this->partner();
        [$client] = $this->registerClient($partner);
        $plan = Plan::factory()->create(['price' => '10000.00']);
        $this->actingAs($partner)->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $manager = app(PaymentManager::class);
        $payment = $manager->create([
            'customer_id' => $client->id, 'amount' => '1000.00', 'currency' => $invoice->currency,
            'payment_method' => 'cash', 'transaction_reference' => 'PARTIAL-TEST', 'payment_date' => today(),
        ], $partner);
        Notification::fake();
        $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => '1000.00']], $partner);
        $this->assertCount(1, Notification::sent($partner, PartnerClientMilestone::class));
        $this->assertStringContainsString('Payment confirmed', Notification::sent($partner, PartnerClientMilestone::class)->first()->subject);
        try {
            $manager->verify($payment, [['invoice_id' => $invoice->id, 'amount' => '1000.00']], $partner);
            $this->fail('A verified payment should not be verified twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertCount(1, Notification::sent($partner, PartnerClientMilestone::class));
        $this->assertDatabaseCount('software_licenses', 0);
    }

    public function test_direct_invoice_payment_also_sends_payment_and_installation_notifications(): void
    {
        $partner = $this->partner();
        [$client] = $this->registerClient($partner);
        $plan = Plan::factory()->create(['price' => '10000.00']);
        $this->actingAs($partner)->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        Notification::fake();
        app(InvoiceManager::class)->payment($invoice, [
            'amount' => $invoice->balance, 'method' => 'cash', 'reference' => 'LEGACY-TEST', 'paid_at' => now(),
        ], $partner);
        $messages = Notification::sent($partner, PartnerClientMilestone::class);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Payment confirmed', $messages[0]->subject);
        $this->assertStringContainsString('Installation and activation', $messages[1]->subject);
    }

    public function test_paid_partner_subscription_issues_initial_installation_code_for_direct_products(): void
    {
        $partner = $this->partner();
        [$client] = $this->registerClient($partner);
        $plan = Plan::factory()->create(['price' => '10000.00']);
        $plan->product->forceFill(['licensing_mode' => 'direct'])->save();
        $this->actingAs($partner)->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $payment = app(PaymentManager::class)->create([
            'customer_id' => $client->id, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'payment_method' => 'cash', 'transaction_reference' => 'DIRECT-CLIENT-PAYMENT', 'payment_date' => today(),
        ], $partner);

        app(PaymentManager::class)->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]], $partner);

        $license = SoftwareLicense::query()->where('customer_id', $client->id)->sole();
        $code = LicenseActivationCode::query()->where('software_license_id', $license->id)->sole();
        $this->assertSame('issued', $code->status);
        $this->assertSame($plan->product_id, $code->product_id);
        $this->assertNotNull($code->expires_at);
        $this->assertDatabaseHas('client_setup_bundles', ['subscription_id' => $license->subscription_id]);

        $clientCode = null;
        Notification::assertSentOnDemand(PartnerClientMilestone::class, function ($notification, $channels, AnonymousNotifiable $recipient) use ($client, &$clientCode) {
            if (($recipient->routes['mail'] ?? null) !== $client->email || ! str_contains($notification->subject, 'Installation and activation')) {
                return false;
            }
            preg_match('/KBI-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}/', $notification->message, $matches);
            $clientCode = $matches[0] ?? null;

            return $clientCode !== null;
        });
        $this->assertNotNull($clientCode);
        $this->assertTrue(password_verify($clientCode, $code->code_hash));
        Notification::assertSentTo($partner, PartnerClientMilestone::class, fn ($notification) =>
            str_contains($notification->subject, 'Installation and activation')
            && ! str_contains($notification->message, $clientCode));
    }

    public function test_partner_can_regenerate_and_email_code_for_paid_client_subscription(): void
    {
        $partner = $this->partner();
        [$client] = $this->registerClient($partner);
        $plan = Plan::factory()->create(['price' => '10000.00']);
        $plan->product->forceFill(['licensing_mode' => 'direct'])->save();
        $this->actingAs($partner)->post(route('partner.invoices.store'), [
            'customer_uuid' => $client->uuid, 'plan_uuid' => $plan->uuid,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $payment = app(PaymentManager::class)->create([
            'customer_id' => $client->id, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'payment_method' => 'cash', 'transaction_reference' => 'DIRECT-RESEND-PAYMENT', 'payment_date' => today(),
        ], $partner);
        app(PaymentManager::class)->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]], $partner);
        $subscription = $client->subscriptions()->sole();
        $firstCode = LicenseActivationCode::query()->sole();

        $this->actingAs($partner)->get(route('partner.subscriptions'))->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.data.0.can_generate_activation_code', true)
            ->where('subscriptions.data.0.has_issued_activation_code', true));
        $this->post(route('partner.subscriptions.activation-code', $subscription->uuid))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $firstCode->refresh();
        $newCode = LicenseActivationCode::query()->where('status', 'issued')->sole();
        $this->assertSame('revoked', $firstCode->status);
        $this->assertSame($firstCode->id, $newCode->replacement_of_id);

        $emailedCode = null;
        Notification::assertSentOnDemand(PartnerClientMilestone::class, function ($notification, $channels, AnonymousNotifiable $recipient) use ($client, &$emailedCode) {
            if (($recipient->routes['mail'] ?? null) !== $client->email || ! str_contains($notification->subject, 'New installation activation code')) {
                return false;
            }
            preg_match('/KBI-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}/', $notification->message, $matches);
            $emailedCode = $matches[0] ?? null;

            return $emailedCode !== null;
        });
        $this->assertNotNull($emailedCode);
        $this->assertTrue(password_verify($emailedCode, $newCode->code_hash));

        $otherPartner = $this->partner();
        $this->actingAs($otherPartner)->post(route('partner.subscriptions.activation-code', $subscription->uuid))->assertNotFound();
    }
}
