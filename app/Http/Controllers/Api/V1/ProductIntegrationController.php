<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Services\PackageCatalog;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductIntegrationController extends Controller
{
    public function product(Request $request, string $code): JsonResponse
    {
        $client = $request->attributes->get('api_client');
        abort_unless(strtoupper($code) === $client->product->code, 404);
        $product = $client->product;

        return ApiResponse::success($request, ['uuid' => $product->uuid, 'code' => $product->code, 'name' => $product->name, 'status' => $product->status->value, 'short_description' => $product->short_description, 'website_url' => $product->website_url, 'demo_url' => $product->demo_url]);
    }

    public function activate(Request $request, LicenseService $licenses): JsonResponse
    {
        $data = $this->licenseData($request, true);

        return ApiResponse::success($request, $licenses->activate($request->attributes->get('api_client'), $data, (string) $request->ip()), 201);
    }

    public function verify(Request $request, LicenseService $licenses): JsonResponse
    {
        $data = $this->licenseData($request);

        return ApiResponse::success($request, $licenses->verify($request->attributes->get('api_client'), $data));
    }

    public function deactivate(Request $request, LicenseService $licenses): JsonResponse
    {
        $data = $this->licenseData($request);

        return ApiResponse::success($request, $licenses->deactivate($request->attributes->get('api_client'), $data));
    }

    public function subscription(Request $request): JsonResponse
    {
        $data = $request->validate(['subscription_uuid' => ['required', 'uuid']]);
        $subscription = $this->subscriptionForClient($request, $data['subscription_uuid']);

        return ApiResponse::success($request, ['uuid' => $subscription->uuid, 'subscription_number' => $subscription->subscription_number, 'status' => $subscription->status->value, 'product_code' => $subscription->product->code, 'plan' => ['uuid' => $subscription->plan->uuid, 'name' => $subscription->plan->name], 'starts_at' => $subscription->starts_at?->toISOString(), 'ends_at' => $subscription->ends_at?->toISOString(), 'trial_ends_at' => $subscription->trial_ends_at?->toISOString(), 'grace_ends_at' => $subscription->grace_ends_at?->toISOString(), 'configuration' => app(PackageCatalog::class)->configuration($subscription), 'auto_renew' => $subscription->auto_renew, 'selected_packages' => data_get($subscription->metadata, 'selected_packages', []), 'primary_package' => data_get($subscription->metadata, 'primary_package')]);
    }

    public function entitlements(Request $request): JsonResponse
    {
        $data = $request->validate(['subscription_uuid' => ['required', 'uuid']]);
        $subscription = $this->subscriptionForClient($request, $data['subscription_uuid']);
        $items = $subscription->entitlements()->whereNull('effective_until')->orderBy('feature_key')->get()->map(fn ($e) => ['key' => $e->feature_key, 'name' => $e->feature_name, 'enabled' => $e->enabled, 'limit' => $e->limit_value])->values();

        return ApiResponse::success($request, ['subscription_uuid' => $subscription->uuid, 'status' => $subscription->status->value, 'entitlements' => $items]);
    }

    public function demoEvent(Request $request): JsonResponse
    {
        $client = $request->attributes->get('api_client');
        $data = $request->validate(['event_id' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, \Closure $fail) use ($client): void {
            if (DB::table('demo_product_events')->where('api_client_id', $client->id)->where('event_id', $value)->exists()) {
                $fail('This event has already been submitted.');
            }
        }], 'event_type' => ['required', 'string', 'max:100'], 'occurred_at' => ['required', 'date'], 'metadata' => ['nullable', 'array']]);
        $uuid = (string) str()->uuid();
        DB::table('demo_product_events')->insert(['uuid' => $uuid, 'api_client_id' => $client->id, 'product_id' => $client->product_id, ...$data, 'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_THROW_ON_ERROR) : null, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($request, ['event_uuid' => $uuid, 'accepted' => true], 202);
    }

    private function licenseData(Request $request, bool $name = false): array
    {
        $rules = ['license_key' => ['required', 'string', 'max:255'], 'device_id' => ['required', 'string', 'max:255']];
        $rules['machine_fingerprint'] = ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'];
        if ($name) {
            $rules['device_info'] = ['nullable', 'array:hostname,os,architecture,client_version,platform_url'];
            foreach (['hostname', 'os', 'architecture', 'client_version'] as $field) {
                $rules['device_info.'.$field] = ['nullable', 'string', 'max:255'];
            }
            $rules['device_info.platform_url'] = ['nullable', 'url', 'max:2048'];
            $rules['device_name'] = ['nullable', 'string', 'max:255'];
        }

        return $request->validate($rules);
    }

    private function subscriptionForClient(Request $request, string $uuid): Subscription
    {
        $client = $request->attributes->get('api_client');

        return Subscription::query()->with(['product', 'plan'])->where('uuid', $uuid)->where('product_id', $client->product_id)->when($client->subscription_id, fn ($q) => $q->whereKey($client->subscription_id))->firstOrFail();
    }
}
