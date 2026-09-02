<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);
        DB::table('api_request_logs')->insert(['request_id' => $request->attributes->get('request_id'), 'api_client_id' => $request->attributes->get('api_client')?->id, 'method' => $request->method(), 'path' => $request->path(), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 65535), 'status_code' => $response->getStatusCode(), 'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000), 'error_code' => $response->getStatusCode() >= 400 ? (json_decode($response->getContent(), true)['error']['code'] ?? null) : null, 'created_at' => now()]);

        return $response;
    }
}
