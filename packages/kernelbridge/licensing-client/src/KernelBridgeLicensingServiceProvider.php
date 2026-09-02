<?php

namespace KernelBridge\LicensingClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use KernelBridge\LicensingClient\Http\Middleware\FeatureMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LicenseMiddleware;
use KernelBridge\LicensingClient\Http\Middleware\LimitMiddleware;
use KernelBridge\LicensingClient\Jobs\VerifyLicenseJob;

final class KernelBridgeLicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kernelbridge-licensing.php', 'kernelbridge-licensing');
        $this->app->singleton(KernelBridgeLicenseClient::class);
    }

    public function boot(Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/kernelbridge-licensing.php' => config_path('kernelbridge-licensing.php')], 'kernelbridge-licensing-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'kernelbridge-licensing-migrations');

        $router->aliasMiddleware('kernelbridge.subscription.active', LicenseMiddleware::class);
        $router->aliasMiddleware('kernelbridge.feature', FeatureMiddleware::class);
        $router->aliasMiddleware('kernelbridge.limit', LimitMiddleware::class);

        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->app->booted(function (): void {
            $minutes = min(59, max(1, (int) config('kernelbridge-licensing.verification_interval_minutes', 15)));
            app(Schedule::class)->job(new VerifyLicenseJob)->cron("*/$minutes * * * *")
                ->name('kernelbridge-license-verification')->withoutOverlapping()->onOneServer();
        });
    }
}
