<?php

namespace KernelBridge\LicensingClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use Symfony\Component\HttpFoundation\Response;

class LicenseMiddleware
{
    public function __construct(protected readonly LicenseCacheService $licenses) {}

    protected function requireActive(): void
    {
        abort_unless($this->licenses->hasUsableLicense(), 402, 'An active KernelBridge subscription is required.');
    }

    protected function requireFeature(string $feature): void
    {
        $this->requireActive();
        abort_unless($this->licenses->hasFeature($feature), 403, "The '$feature' feature is not included in this plan.");
    }

    protected function requireLimit(string $feature, int $required): void
    {
        $this->requireFeature($feature);
        $limit = $this->licenses->limit($feature);
        if ($limit === null || $limit === '' || $limit === 'unlimited') {
            return;
        }
        abort_unless(is_numeric($limit) && (float) $limit >= $required, 403, "The '$feature' plan limit is insufficient.");
    }

    public function handle(Request $request, Closure $next, mixed ...$arguments): Response
    {
        $this->requireActive();

        return $next($request);
    }
}
