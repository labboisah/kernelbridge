<?php

namespace KernelBridge\LicensingClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitMiddleware extends LicenseMiddleware
{
    public function handle(Request $request, Closure $next, mixed ...$arguments): Response
    {
        $feature = (string) ($arguments[0] ?? '');
        $required = $arguments[1] ?? 1;
        abort_if($feature === '', 500, 'A KernelBridge limit key is required.');
        $this->requireLimit($feature, max(1, (int) $required));

        return $next($request);
    }
}
