<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SendProductWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $uniqueFor = 60;

    public string $eventId;

    /** @param array<string, mixed> $data */
    public function __construct(public string $productCode, public string $event, public array $data)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return $this->productCode.'|'.$this->event.'|'.($this->data['subscription_uuid'] ?? '');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600, 3600];
    }

    public function handle(): void
    {
        $endpoint = config('product_integration.webhooks.'.$this->productCode);

        if (blank($endpoint['url'] ?? null) || blank($endpoint['secret'] ?? null)) {
            return;
        }

        $body = json_encode([
            'id' => $this->eventId,
            'type' => $this->event,
            'created_at' => now()->toIso8601String(),
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $endpoint['secret']);

        Http::timeout(10)
            ->withHeaders(['X-KernelBridge-Signature' => 't='.$timestamp.',v1='.$signature])
            ->withBody($body, 'application/json')
            ->post((string) $endpoint['url'])
            ->throw();
    }
}
