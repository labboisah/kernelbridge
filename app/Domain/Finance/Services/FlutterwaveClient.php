<?php

namespace App\Domain\Finance\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FlutterwaveClient
{
    /** @param array<string, mixed> $payload */
    public function createPayment(array $payload): string
    {
        $response = $this->request()->post('/payments', $payload)->throw()->json();
        $link = data_get($response, 'data.link');
        if (($response['status'] ?? null) !== 'success' || ! is_string($link)) {
            throw new RuntimeException('Flutterwave did not return a checkout link.');
        }

        return $link;
    }

    /** @return array<string, mixed> */
    public function verify(string $transactionId): array
    {
        $response = $this->request()->get('/transactions/'.rawurlencode($transactionId).'/verify')->throw()->json();
        if (($response['status'] ?? null) !== 'success' || ! is_array($response['data'] ?? null)) {
            throw new RuntimeException('Flutterwave transaction verification failed.');
        }

        return $response['data'];
    }

    private function request(): PendingRequest
    {
        $secret = (string) config('services.flutterwave.secret_key');
        if ($secret === '') {
            throw new RuntimeException('Flutterwave is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.flutterwave.base_url'), '/'))->withToken($secret)->acceptJson()->asJson()->timeout(20)->retry(2, 250);
    }
}
