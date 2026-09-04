<?php

namespace KernelBridge\LicensingClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;

final class KernelBridgeLicenseClient
{
    public function activate(string $licenseKey, string $installationIdentifier, ?string $deviceName = null): array
    {
        return $this->request('post', '/licenses/activate', array_filter(['license_key' => $licenseKey, 'device_id' => $installationIdentifier, 'device_name' => $deviceName]));
    }

    public function verify(string $licenseKey, string $installationIdentifier): array
    {
        return $this->request('post', '/licenses/verify', ['license_key' => $licenseKey, 'device_id' => $installationIdentifier]);
    }

    public function deactivate(string $licenseKey, string $installationIdentifier): array
    {
        return $this->request('post', '/licenses/deactivate', ['license_key' => $licenseKey, 'device_id' => $installationIdentifier]);
    }

    public function getEntitlements(string $subscriptionIdentifier): array
    {
        return $this->request('get', '/entitlements', ['subscription_uuid' => $subscriptionIdentifier]);
    }

    public function getSubscription(string $subscriptionIdentifier): array
    {
        return $this->request('get', '/subscriptions/status', ['subscription_uuid' => $subscriptionIdentifier]);
    }

    private function request(string $method, string $path, array $data): array
    {
        try {
            $response = $method === 'get'
                ? $this->http()->get($path, $data)
                : $this->http()->send(strtoupper($method), $path, ['json' => $data]);
        } catch (ConnectionException $exception) {
            throw new KernelBridgeUnavailableException('KernelBridge licensing is temporarily unavailable.', previous: $exception);
        }

        return $this->data($response);
    }

    private function http(): PendingRequest
    {
        $url = rtrim((string) config('kernelbridge-licensing.api_url'), '/');
        $token = (string) config('kernelbridge-licensing.api_token');
        $code = (string) config('kernelbridge-licensing.product_code');
        if ($url === '' || $token === '' || $code === '') {
            throw new \LogicException('KernelBridge licensing configuration is incomplete.');
        }

        return Http::baseUrl($url)->withToken($token)->acceptJson()->asJson()
            ->withHeaders(['X-KernelBridge-Product' => strtoupper($code)])
            ->connectTimeout((int) config('kernelbridge-licensing.connect_timeout_seconds', 3))
            ->timeout((int) config('kernelbridge-licensing.timeout_seconds', 10));
    }

    private function data(Response $response): array
    {
        if ($response->serverError()) {
            throw new KernelBridgeUnavailableException('KernelBridge licensing is temporarily unavailable.');
        }
        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('data'))) {
            throw new KernelBridgeApiException(
                (string) ($response->json('error.message') ?: 'KernelBridge rejected the licensing request.'),
                (string) ($response->json('error.code') ?: 'license_rejected'),
                $response->status(),
            );
        }

        return $response->json('data');
    }
}
