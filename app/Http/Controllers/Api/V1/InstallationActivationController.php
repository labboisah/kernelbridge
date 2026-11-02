<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Domain\Licensing\Services\InstallationActivationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstallationActivationController extends Controller
{
    public function activate(Request $request, InstallationActivationService $activation): JsonResponse
    {
        $data = $request->validate([
            'activation_code' => ['required', 'string', 'max:64'],
            'product_code' => ['required', 'string', 'max:64'],
            'installation_uuid' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $activation->exchange($data);
        } catch (InstallationVerificationException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result, $result['created'] ? 201 : 200);
    }

    public function deactivate(Request $request, InstallationActivationService $activation): JsonResponse
    {
        try {
            $result = $activation->deactivateWithCredential((string) $request->bearerToken());
        } catch (InstallationVerificationException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success($request, $result);
    }
}
