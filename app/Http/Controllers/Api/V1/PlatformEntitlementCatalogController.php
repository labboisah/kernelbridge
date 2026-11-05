<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Services\PlatformEntitlementCatalogService;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformEntitlementCatalogController extends Controller
{
    private const REQUIRED_SCOPE = 'licensing:entitlement-catalog:write';

    public function publish(Request $request, PlatformEntitlementCatalogService $catalogs): JsonResponse
    {
        $client = $request->attributes->get('api_client');
        if (! $client instanceof ApiClient) {
            return ApiResponse::error($request, 'unauthenticated', 'A valid product API credential is required.', 401);
        }
        if (! in_array(self::REQUIRED_SCOPE, $client->scopes ?? [], true)) {
            return ApiResponse::error(
                $request,
                'insufficient_scope',
                'This API credential must have the exact entitlement catalog scope.',
                403,
                ['required_scopes' => [self::REQUIRED_SCOPE]],
            );
        }

        $result = $catalogs->publish($client, $request->getContent());
        if (! $result['success']) {
            return ApiResponse::error($request, $result['code'], $result['message'], $result['status']);
        }

        return ApiResponse::success($request, $result['data']);
    }
}
