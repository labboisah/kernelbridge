<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Exceptions\PlatformAccountLinkException;
use App\Domain\Licensing\Services\PlatformAccountLinkService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformAccountLinkController extends Controller
{
    public function request(Request $request, PlatformAccountLinkService $links): JsonResponse
    {
        $data = $request->validate([
            'license_uuid' => ['required', 'uuid'],
            'business_uuid' => ['required', 'uuid'],
        ]);

        try {
            $link = $links->request($request->attributes->get('api_client'), $data['license_uuid'], $data['business_uuid']);
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $link, $link['status'] === 'pending' ? 202 : 200);
    }

    public function status(Request $request, string $link, PlatformAccountLinkService $links): JsonResponse
    {
        try {
            $result = $links->status($request->attributes->get('api_client'), $link);
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result);
    }

    public function issueInstallationCode(Request $request, string $link, PlatformAccountLinkService $links): JsonResponse
    {
        $data = $request->validate([
            'installation_uuid' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $links->issueInstallationCode(
                $request->attributes->get('api_client'),
                $link,
                $data['installation_uuid'],
                $data['device_name'] ?? null,
            );
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result, 201);
    }
}
