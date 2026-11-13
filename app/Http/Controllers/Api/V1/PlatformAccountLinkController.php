<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Exceptions\PlatformAccountLinkException;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Services\PlatformAccountLinkService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformAccountLinkController extends Controller
{
    public function request(Request $request, PlatformAccountLinkService $links, LicenseService $licenses): JsonResponse
    {
        $data = $request->validate([
            'license_uuid' => ['nullable', 'uuid', 'required_without:activation_code'],
            'activation_code' => ['nullable', 'string', 'max:255', 'required_without:license_uuid'],
            'business_uuid' => ['required', 'uuid'],
        ]);
        if (filled($data['activation_code'] ?? null) === filled($data['license_uuid'] ?? null)) {
            return ApiResponse::error(
                $request,
                'invalid_account_link_request',
                'Provide either a license UUID or an activation code, but not both.',
                422,
            );
        }

        try {
            $client = $request->attributes->get('api_client');
            $link = isset($data['activation_code'])
                ? $links->requestWithActivationCode($client, $licenses, $data['activation_code'], $data['business_uuid'])
                : $links->request($client, $data['license_uuid'], $data['business_uuid']);
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

    public function unlink(Request $request, string $link, PlatformAccountLinkService $links): JsonResponse
    {
        try {
            $result = $links->unlink($request->attributes->get('api_client'), $link);
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result);
    }

    public function installations(Request $request, string $link, PlatformAccountLinkService $links): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        try {
            $result = $links->installations($request->attributes->get('api_client'), $link, (int) ($data['page'] ?? 1));
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result)->header('Cache-Control', 'no-store, private');
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

    public function revokeInstallation(Request $request, string $link, string $installation, PlatformAccountLinkService $links): JsonResponse
    {
        try {
            $result = $links->revokeInstallation(
                $request->attributes->get('api_client'),
                $link,
                $installation,
            );
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result);
    }

    public function issueEnrollmentCode(Request $request, string $link, PlatformAccountLinkService $links): JsonResponse
    {
        try {
            $result = $links->issueEnrollmentCode($request->attributes->get('api_client'), $link);
        } catch (PlatformAccountLinkException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result, 201)->header('Cache-Control', 'no-store, private');
    }
}
