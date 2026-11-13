<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Domain\Licensing\Services\EntitlementSigner;
use App\Domain\Licensing\Services\InstallationActivationService;
use App\Domain\Licensing\Services\InstallationVerificationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalSyncProofController extends Controller
{
    public function __invoke(
        Request $request,
        InstallationVerificationService $verification,
        InstallationActivationService $activations,
        EntitlementSigner $signer,
    ): JsonResponse {
        abort_unless(config('product_integration.operational_sync_proof_enabled'), 404);
        $data = $request->validate(['challenge' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        try {
            $verification->verify($request->bearerToken());
            $installation = $activations->authenticate((string) $request->bearerToken());
            $link = $installation?->platformAccountLink;
            if (! $installation || ! $link || $link->status !== 'approved'
                || $link->revoked_at || $installation->business_uuid !== $link->business_uuid
                || $installation->software_license_id !== $link->software_license_id
                || $installation->product_id !== $link->product_id) {
                throw new InstallationVerificationException('platform_account_not_approved', 'This installation does not have an approved platform account link.', 403);
            }

            return ApiResponse::success($request, $signer->sign([
                'purpose' => 'mediflow.operational-sync.enrollment',
                'version' => 1,
                'challenge' => $data['challenge'],
                'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'installation_uuid' => $installation->installation_uuid,
                'business_uuid' => $installation->business_uuid,
                'platform_account_link_uuid' => $link->uuid,
                'license_uuid' => $installation->license->uuid,
                'product_code' => strtoupper((string) $installation->product->code),
            ]))->header('Cache-Control', 'no-store, private');
        } catch (InstallationVerificationException $exception) {
            return ApiResponse::error($request, $exception->errorCode, $exception->getMessage(), $exception->status)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
