<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LimitApiClientRequests
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('api_client');
        $key = 'product-api:'.$client->uuid.':'.$request->ip();
        $limit = $client->rate_limit_per_minute;
        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return ApiResponse::error($request, 'rate_limit_exceeded', 'Too many requests.', 429, ['retry_after' => $this->limiter->availableIn($key)]);
        } $this->limiter->hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $this->limiter->remaining($key, $limit));

        return $response;
    }
}
