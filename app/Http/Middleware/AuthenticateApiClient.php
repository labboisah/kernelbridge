<?php

namespace App\Http\Middleware;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Models\ApiClientCredential;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateApiClient
{
    public function __construct(private ApiCredentialService $credentials) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $parts = $token ? explode('.', $token, 3) : [];
        if (count($parts) !== 3) {
            return ApiResponse::error($request, 'unauthenticated', 'A valid bearer token is required.', 401);
        }[$clientUuid,$credentialUuid,$secret] = $parts;
        $credential = ApiClientCredential::query()->with('client.product')->where('uuid', $credentialUuid)->whereHas('client', fn ($q) => $q->where('uuid', $clientUuid))->first();
        if (! $credential || ! hash_equals($credential->secret_hash, $this->credentials->digest($secret)) || $credential->revoked_at || ($credential->expires_at && $credential->expires_at->isPast()) || ! $credential->client->is_active) {
            return ApiResponse::error($request, 'invalid_token', 'The API credential is invalid, expired, or revoked.', 401);
        }$ips = $credential->client->allowed_ips;
        if ($ips && ! in_array($request->ip(), $ips, true)) {
            return ApiResponse::error($request, 'ip_not_allowed', 'This source IP is not allowed.', 403);
        }$credential->update(['last_used_at' => now()]);
        $credential->client->update(['last_used_at' => now()]);
        $request->attributes->set('api_client', $credential->client);
        $request->attributes->set('api_credential', $credential);

        if ($credential->client->subscription_id && ! $request->routeIs('api.v1.licenses.activate', 'api.v1.licenses.verify', 'api.v1.licenses.deactivate', 'api.v1.subscriptions.status', 'api.v1.entitlements.index')) {
            return ApiResponse::error($request, 'forbidden', 'This credential is restricted to installation licensing.', 403);
        }

        return $next($request);
    }
}
