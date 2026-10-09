<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Models\ClientSetupBundle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientSetupService
{
    public function provision(Subscription $subscription, User $actor): void
    {
        DB::transaction(function () use ($subscription, $actor): void {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            if (ClientSetupBundle::where('subscription_id', $subscription->id)->exists()) {
                return;
            }
            $service = app(ApiCredentialService::class);
            $client = $service->createClient($subscription->product, 'installation-'.$subscription->uuid, ['scopes' => ['licensing:client']]);
            $client->forceFill(['subscription_id' => $subscription->id])->save();
            $issued = $service->issue($client, 'Client installation', $actor);
            ClientSetupBundle::create(['subscription_id' => $subscription->id, 'secrets' => ['api_token' => $issued['token'], 'cache_signing_key' => Str::random(64)]]);
        });
    }

    public function environment(Subscription $subscription): string
    {
        $secrets = ClientSetupBundle::where('subscription_id', $subscription->id)->firstOrFail()->secrets;
        $license = $subscription->license;
        abort_unless($license && $license->status === 'active' && in_array($subscription->status->value, ['active', 'grace'], true), 403);

        return implode("\n", ['# Merge these settings into the installed application .env.', '# Keep the existing APP_KEY and database settings. Do not commit this file.',
            'APP_MODE=client',
            ...($subscription->product->code === 'MEDIFLOW' ? ['MEDIFLOW_CENTRAL_LICENSING=true'] : []),
            'KERNELBRIDGE_API_URL='.rtrim(config('app.url'), '/').'/api/v1',
            'KERNELBRIDGE_PRODUCT_CODE='.$subscription->product->code, 'KERNELBRIDGE_API_TOKEN='.$secrets['api_token'],
            'KERNELBRIDGE_LICENSE_KEY='.app(LicenseService::class)->key($license),
            'KERNELBRIDGE_CACHE_SIGNING_KEY='.$secrets['cache_signing_key'],
            'KERNELBRIDGE_VERIFICATION_INTERVAL=15', 'KERNELBRIDGE_OFFLINE_GRACE_HOURS=72',
            ...(in_array($subscription->product->code, ['EDUNEXA', 'MEDIFLOW'], true) ? ['KERNELBRIDGE_LICENSE_REDIRECT_AFTER_ACTIVATION=/configuration'] : []),
            ...($subscription->product->code === 'MEDIFLOW' ? [
                'KERNELBRIDGE_LICENSE_BRAND_NAME=MediFlow',
                'KERNELBRIDGE_LICENSE_BRAND_SUBTITLE=Healthcare Management',
                'KERNELBRIDGE_LICENSE_HEADING=Secure access for your care team.',
                'KERNELBRIDGE_LICENSE_DESCRIPTION=Activate your MediFlow license to protect patient and operational data on this installation.',
                'KERNELBRIDGE_LICENSE_SHOW_LOGO=false',
            ] : []), '']);
    }
}
