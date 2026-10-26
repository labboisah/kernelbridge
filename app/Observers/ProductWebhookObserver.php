<?php

namespace App\Observers;

use App\Jobs\SendProductWebhook;
use App\Models\LicenseInstallationGrant;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Illuminate\Database\Eloquent\Model;

final class ProductWebhookObserver
{
    public function saved(Model $model): void
    {
        if ($model instanceof SubscriptionEntitlement) {
            $this->notify($model->subscription_id, null, 'subscription.entitlements_changed');
        } elseif ($model instanceof Subscription) {
            if ($model->wasRecentlyCreated || $model->wasChanged(['status', 'plan_id', 'ends_at', 'grace_ends_at'])) {
                $this->notify($model->id, null, 'subscription.updated');
            }
        } elseif ($model instanceof SoftwareLicense) {
            if ($model->wasChanged(['status', 'expires_at'])) {
                $this->notify($model->subscription_id, null, 'license.updated');
            }
        } elseif ($model instanceof LicenseInstallationGrant) {
            if ($model->wasChanged('status') && $model->status === 'revoked') {
                $this->notify(null, $model->software_license_id, 'installation_grant.revoked', ['grant_uuid' => $model->uuid]);
            }
        }
    }

    /** @param array<string, mixed> $extra */
    private function notify(?int $subscriptionId, ?int $licenseId, string $event, array $extra = []): void
    {
        $query = Subscription::query()->join('software_licenses', 'software_licenses.subscription_id', '=', 'subscriptions.id');
        $query = $subscriptionId !== null ? $query->where('subscriptions.id', $subscriptionId) : $query->where('software_licenses.id', $licenseId);
        $row = $query->first(['subscriptions.uuid as subscription_uuid', 'subscriptions.product_id', 'software_licenses.uuid as license_uuid']);

        if (! $row) {
            return;
        }

        $code = strtoupper((string) Product::query()->whereKey($row->product_id)->value('code'));

        if (blank(config('product_integration.webhooks.'.$code.'.url'))) {
            return;
        }

        SendProductWebhook::dispatch($code, $event, [
            'subscription_uuid' => $row->subscription_uuid,
            'license_uuid' => $row->license_uuid,
            ...$extra,
        ])->afterCommit();
    }
}
