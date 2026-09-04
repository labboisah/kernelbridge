<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Services\LicenseService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LicenseActivation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class McpController extends Controller
{
    private const TOOL_LIMITS = [
        'kernelbridge_find_customer' => 60,
        'kernelbridge_list_customer_services' => 60,
        'kernelbridge_get_service_setup' => 30,
        'kernelbridge_explain_activation_issue' => 60,
        'kernelbridge_list_license_activations' => 60,
        'kernelbridge_issue_license' => 5,
        'kernelbridge_deactivate_license_activation' => 10,
    ];

    private const SENSITIVE_METADATA_KEYS = ['api_token', 'authorization', 'bearer', 'cache_signing_key', 'credential_secret', 'license_key', 'password', 'secret', 'token'];

    public function customers(Request $request): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_find_customer')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $data = $request->validate(['query' => ['nullable', 'string', 'max:255'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $this->audit($request, 'kernelbridge_find_customer', false, 'customer_search', null, ['query' => $data['query'] ?? null]);
        $customers = Customer::query()->search($data['query'] ?? null)
            ->whereHas('subscriptions', fn ($query) => $query->where('product_id', $client->product_id))
            ->withCount(['subscriptions as product_services_count' => fn ($query) => $query->where('product_id', $client->product_id)])
            ->orderBy('name')->limit((int) ($data['limit'] ?? 10))
            ->get(['id', 'uuid', 'customer_number', 'name', 'email', 'status']);

        return ApiResponse::success($request, $customers->map(fn (Customer $customer): array => [
            'uuid' => $customer->uuid,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'email' => $customer->email,
            'status' => $customer->status->value,
            'product_services_count' => $customer->product_services_count,
        ])->values());
    }

    public function customerServices(Request $request, string $customer): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_list_customer_services')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $customerModel = Customer::query()->where('uuid', $customer)->firstOrFail();
        $this->audit($request, 'kernelbridge_list_customer_services', false, 'customer', $customerModel->uuid);
        $services = Subscription::query()->where('customer_id', $customerModel->id)->where('product_id', $client->product_id)
            ->with(['product:id,uuid,name,code', 'plan:id,uuid,name', 'license.activations'])->latest()->get();

        return ApiResponse::success($request, $services->map(fn (Subscription $subscription): array => $this->servicePayload($subscription))->values());
    }

    public function serviceSetup(Request $request, string $subscription, LicenseService $licenses): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_get_service_setup')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $data = $request->validate(['runtime' => ['required', 'string', 'in:laravel,typescript,spring-boot,django']]);
        $subscriptionModel = Subscription::query()->where('uuid', $subscription)->where('product_id', $client->product_id)->with(['product', 'license'])->firstOrFail();
        $license = $subscriptionModel->license;
        $this->audit($request, 'kernelbridge_get_service_setup', false, 'subscription', $subscriptionModel->uuid, ['runtime' => $data['runtime']]);

        return ApiResponse::success($request, [
            'runtime' => $data['runtime'],
            'subscription_uuid' => $subscriptionModel->uuid,
            'product_code' => $subscriptionModel->product->code,
            'api_url' => rtrim((string) config('app.url'), '/').'/api/v1',
            'api_token' => 'replace-with-product-machine-token',
            'cache_signing_key' => $license ? $licenses->cacheSigningKey($license) : 'issue-license-first',
            'license_uuid' => $license?->uuid,
            'has_license' => (bool) $license,
        ]);
    }

    public function explainActivationIssue(Request $request): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_explain_activation_issue')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $data = $request->validate(['license_uuid' => ['nullable', 'uuid'], 'activation_uuid' => ['nullable', 'uuid']]);
        $license = null;
        if (! empty($data['activation_uuid'])) {
            $activation = LicenseActivation::query()->where('uuid', $data['activation_uuid'])->whereHas('license', fn ($query) => $query->where('product_id', $client->product_id))->with('license.subscription', 'license.activations')->firstOrFail();
            $license = $activation->license;
            $this->audit($request, 'kernelbridge_explain_activation_issue', false, 'activation', $activation->uuid);
        } elseif (! empty($data['license_uuid'])) {
            $license = SoftwareLicense::query()->where('uuid', $data['license_uuid'])->where('product_id', $client->product_id)->with(['subscription', 'activations'])->firstOrFail();
            $this->audit($request, 'kernelbridge_explain_activation_issue', false, 'license', $license->uuid);
        }
        if (! $license) {
            throw ValidationException::withMessages(['license_uuid' => 'Provide license_uuid or activation_uuid.']);
        }

        return ApiResponse::success($request, $this->activationIssuePayload($license));
    }

    public function activations(Request $request, string $license): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_list_license_activations')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $licenseModel = SoftwareLicense::query()->where('uuid', $license)->where('product_id', $client->product_id)->with('activations')->firstOrFail();
        $this->audit($request, 'kernelbridge_list_license_activations', false, 'license', $licenseModel->uuid);

        return ApiResponse::success($request, $licenseModel->activations->map(fn (LicenseActivation $activation): array => [
            'uuid' => $activation->uuid,
            'device_name' => $activation->device_name ?: 'Unnamed installation',
            'status' => $activation->status,
            'activated_at' => $activation->activated_at?->toISOString(),
            'last_verified_at' => $activation->last_verified_at?->toISOString(),
            'deactivated_at' => $activation->deactivated_at?->toISOString(),
        ])->values());
    }

    public function issueLicense(Request $request, LicenseService $licenses): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_issue_license')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $data = $request->validate([
            'subscription_uuid' => ['required', 'uuid'],
            'max_activations' => ['nullable', 'integer', 'min:1', 'max:100'],
            'confirmed_by' => ['required', 'string', 'max:255'],
            'confirmation_note' => ['required', 'string', 'max:1000'],
        ]);
        $subscription = Subscription::query()->where('uuid', $data['subscription_uuid'])->where('product_id', $client->product_id)->firstOrFail();
        $confirmationUuid = $this->recordConfirmation($request, 'kernelbridge_issue_license', 'subscription', $subscription->uuid, $data['confirmed_by'], $data['confirmation_note'], 'high');
        $issued = $licenses->issue($subscription, (int) ($data['max_activations'] ?? 1));
        /** @var SoftwareLicense $license */
        $license = $issued['license'];
        $this->alertMutation($request, 'kernelbridge_issue_license', 'subscription', $subscription->uuid, $confirmationUuid);
        $this->audit($request, 'kernelbridge_issue_license', true, 'subscription', $subscription->uuid, ['license_uuid' => $license->uuid, 'confirmation_uuid' => $confirmationUuid, 'risk_level' => 'high']);

        return ApiResponse::success($request, [
            'license_uuid' => $license->uuid,
            'license_key' => $issued['license_key'],
            'status' => $license->status,
            'max_activations' => $license->max_activations,
            'expires_at' => $license->expires_at?->toISOString(),
            'confirmation_uuid' => $confirmationUuid,
        ], $license->wasRecentlyCreated ? 201 : 200);
    }

    public function deactivateActivation(Request $request, string $activation): JsonResponse
    {
        if ($limited = $this->enforceToolLimit($request, 'kernelbridge_deactivate_license_activation')) {
            return $limited;
        }
        $client = $request->attributes->get('api_client');
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'confirmed_by' => ['required', 'string', 'max:255'],
            'confirmation_note' => ['required', 'string', 'max:1000'],
        ]);
        $activationModel = LicenseActivation::query()->where('uuid', $activation)->whereHas('license', fn ($query) => $query->where('product_id', $client->product_id))->with('license')->firstOrFail();
        if ($activationModel->status !== 'active') {
            throw ValidationException::withMessages(['activation' => 'This installation is already inactive.']);
        }
        $confirmationUuid = $this->recordConfirmation($request, 'kernelbridge_deactivate_license_activation', 'activation', $activationModel->uuid, $data['confirmed_by'], $data['confirmation_note'], 'high');
        $metadata = $activationModel->metadata ?? [];
        $metadata['mcp_deactivation_reason'] = $data['reason'] ?? 'MCP operator request';
        $metadata['mcp_confirmation_uuid'] = $confirmationUuid;
        $activationModel->update(['status' => 'deactivated', 'deactivated_at' => now(), 'metadata' => $metadata]);
        $this->alertMutation($request, 'kernelbridge_deactivate_license_activation', 'activation', $activationModel->uuid, $confirmationUuid);
        $this->audit($request, 'kernelbridge_deactivate_license_activation', true, 'activation', $activationModel->uuid, ['license_uuid' => $activationModel->license->uuid, 'confirmation_uuid' => $confirmationUuid, 'risk_level' => 'high']);

        return ApiResponse::success($request, ['license_uuid' => $activationModel->license->uuid, 'activation_uuid' => $activationModel->uuid, 'status' => 'deactivated', 'confirmation_uuid' => $confirmationUuid]);
    }

    private function enforceToolLimit(Request $request, string $tool): ?JsonResponse
    {
        $maxAttempts = self::TOOL_LIMITS[$tool] ?? 30;
        $clientUuid = $request->attributes->get('api_client')?->uuid ?? 'unknown-client';
        $credentialUuid = $request->attributes->get('api_credential')?->uuid ?? 'unknown-credential';
        $key = 'mcp-tool:'.$clientUuid.':'.$credentialUuid.':'.$tool.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return ApiResponse::error($request, 'mcp_tool_rate_limited', 'This MCP tool has reached its per-minute limit.', 429, ['tool' => $tool, 'retry_after' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    private function recordConfirmation(Request $request, string $tool, string $targetType, string $targetUuid, string $confirmedBy, string $note, string $riskLevel): string
    {
        $uuid = (string) str()->uuid();
        DB::table('mcp_tool_confirmations')->insert([
            'uuid' => $uuid,
            'request_id' => $request->attributes->get('request_id'),
            'api_client_id' => $request->attributes->get('api_client')?->id,
            'api_client_credential_id' => $request->attributes->get('api_credential')?->id,
            'tool_name' => $tool,
            'target_type' => $targetType,
            'target_uuid' => $targetUuid,
            'confirmed_by' => $confirmedBy,
            'confirmation_note' => $note,
            'risk_level' => $riskLevel,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'created_at' => now(),
        ]);

        return $uuid;
    }

    private function alertMutation(Request $request, string $tool, string $targetType, string $targetUuid, string $confirmationUuid): void
    {
        Log::warning('High-risk MCP mutation executed.', [
            'request_id' => $request->attributes->get('request_id'),
            'api_client_uuid' => $request->attributes->get('api_client')?->uuid,
            'api_credential_uuid' => $request->attributes->get('api_credential')?->uuid,
            'tool_name' => $tool,
            'target_type' => $targetType,
            'target_uuid' => $targetUuid,
            'confirmation_uuid' => $confirmationUuid,
        ]);
    }

    private function audit(Request $request, string $tool, bool $mutation, ?string $targetType = null, ?string $targetUuid = null, array $metadata = []): void
    {
        DB::table('mcp_audit_logs')->insert([
            'uuid' => (string) str()->uuid(),
            'request_id' => $request->attributes->get('request_id'),
            'api_client_id' => $request->attributes->get('api_client')?->id,
            'api_client_credential_id' => $request->attributes->get('api_credential')?->id,
            'tool_name' => $tool,
            'mutation' => $mutation,
            'target_type' => $targetType,
            'target_uuid' => $targetUuid,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'metadata' => $metadata === [] ? null : json_encode($this->redactMetadata($metadata), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function redactMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_METADATA_KEYS, true)) {
                $metadata[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $metadata[$key] = $this->redactMetadata($value);
            }
        }

        return $metadata;
    }

    private function servicePayload(Subscription $subscription): array
    {
        $license = $subscription->license;

        return [
            'subscription_uuid' => $subscription->uuid,
            'subscription_number' => $subscription->subscription_number,
            'status' => $subscription->status->value,
            'product' => ['uuid' => $subscription->product?->uuid, 'code' => $subscription->product?->code, 'name' => $subscription->product?->name],
            'plan' => ['uuid' => $subscription->plan?->uuid, 'name' => $subscription->plan?->name],
            'starts_at' => $subscription->starts_at?->toISOString(),
            'ends_at' => $subscription->ends_at?->toISOString(),
            'license' => $license ? [
                'uuid' => $license->uuid,
                'status' => $license->status,
                'max_activations' => $license->max_activations,
                'active_activations' => $license->activations->where('status', 'active')->count(),
                'expires_at' => $license->expires_at?->toISOString(),
            ] : null,
        ];
    }

    private function activationIssuePayload(SoftwareLicense $license): array
    {
        $activeActivations = $license->activations->where('status', 'active')->count();
        $reason = 'license_active';
        $message = 'This license can activate another installation.';
        if ($license->status !== 'active') {
            $reason = 'license_inactive';
            $message = 'The license is not active.';
        } elseif ($license->expires_at?->isPast()) {
            $reason = 'license_expired';
            $message = 'The license has expired.';
        } elseif (! in_array($license->subscription->status->value, ['trial', 'active', 'grace'], true)) {
            $reason = 'subscription_inactive';
            $message = 'The subscription is not trial, active, or in grace.';
        } elseif ($activeActivations >= $license->max_activations) {
            $reason = 'activation_limit_reached';
            $message = 'This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.';
        }

        return [
            'license_uuid' => $license->uuid,
            'status' => $license->status,
            'subscription_status' => $license->subscription->status->value,
            'active_activations' => $activeActivations,
            'max_activations' => $license->max_activations,
            'reason' => $reason,
            'message' => $message,
        ];
    }
}
