<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiClientScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $request->attributes->get('api_client');
        $clientScopes = collect($client?->scopes ?? [])->filter()->values();

        $allowed = collect($scopes)->contains(fn (string $scope): bool => $clientScopes->contains($scope) || $clientScopes->contains($this->wildcard($scope)));
        if (! $allowed) {
            return ApiResponse::error($request, 'insufficient_scope', 'This API credential is not allowed to use this MCP operation.', 403, ['required_scopes' => $scopes]);
        }

        return $next($request);
    }

    private function wildcard(string $scope): string
    {
        $parts = explode(':', $scope, 2);

        return $parts[0].':*';
    }
}
