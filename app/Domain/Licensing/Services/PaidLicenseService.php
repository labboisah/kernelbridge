<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Partners\PartnerClientNotifications;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class PaidLicenseService
{
    public function __construct(private SubscriptionService $subscriptions, private LicenseService $licenses) {}

    public function complete(Invoice $invoice, User $actor): void
    {
        if ($invoice->status !== InvoiceStatus::Paid || (float) $invoice->balance !== 0.0) {
            return;
        }
        DB::transaction(function () use ($invoice, $actor): void {
            $order = SubscriptionPackageOrder::query()->where('invoice_id', $invoice->id)->first();
            if ($order) {
                app(PackageChangeService::class)->fulfil($order, $actor);

                return;
            }
            $pending = Subscription::query()->where('invoice_id', $invoice->id)->where('status', 'pending')->lockForUpdate()->get();
            foreach ($pending as $subscription) {
                if (! data_get($subscription->metadata, 'selected_packages') && ! data_get($subscription->metadata, 'checkout')) {
                    continue;
                }
                $subscription = $this->subscriptions->activate($subscription, $invoice, $actor);
                $issued = $this->licenses->issue($subscription, 1);
                $activationCode = null;
                $subscription->loadMissing('customer');
                if ($subscription->customer->partner_user_id
                    && filled($subscription->customer->email)
                    && in_array($issued['license']->product()->value('licensing_mode'), ['direct', 'hybrid'], true)) {
                    $activationCode = app(InstallationActivationService::class)->issueCode($issued['license'], $actor);
                }
                app(ClientSetupService::class)->provision($subscription, $actor);
                app(PartnerClientNotifications::class)->installation(
                    $subscription,
                    $activationCode['code'] ?? null,
                    $activationCode['activation_code']->expires_at ?? null,
                );
            }
        });
    }
}
