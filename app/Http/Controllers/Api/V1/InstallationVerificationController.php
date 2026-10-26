<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Domain\Licensing\Services\InstallationVerificationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstallationVerificationController extends Controller
{
    public function verify(Request $request, InstallationVerificationService $verification): JsonResponse
    {
        try {
            return ApiResponse::success($request, $verification->verify($request->bearerToken()));
        } catch (InstallationVerificationException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }
}
