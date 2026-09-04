<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

final class ApiCredentialService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function createClient(Product $product, string $name, array $options = []): ApiClient
    {
        return ApiClient::query()->updateOrCreate(['product_id' => $product->id, 'name' => $name], ['is_active' => $options['is_active'] ?? true, 'rate_limit_per_minute' => $options['rate_limit_per_minute'] ?? 120, 'allowed_ips' => $options['allowed_ips'] ?? null, 'scopes' => $options['scopes'] ?? ['*']]);
    }

    public function issue(ApiClient $client, string $name, ?User $actor = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $secret = Str::random(64);
        $credential = $client->credentials()->create(['name' => $name, 'secret_hash' => $this->digest($secret), 'expires_at' => $expiresAt, 'created_by' => $actor?->id]);
        $this->audit->record('api_credential.issued', $actor, ['client_uuid' => $client->uuid], $credential, after: $credential->getAttributes());

        return ['credential' => $credential, 'token' => "{$client->uuid}.{$credential->uuid}.{$secret}"];
    }

    public function rotate(ApiClientCredential $credential, string $name, ?User $actor = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $result = $this->issue($credential->client, $name, $actor, $expiresAt);
        $credential->update(['revoked_at' => now()]);
        $this->audit->record('api_credential.rotated', $actor, ['replacement_uuid' => $result['credential']->uuid], $credential, before: ['revoked_at' => null], after: ['revoked_at' => $credential->revoked_at]);

        return $result;
    }

    public function revoke(ApiClientCredential $credential, ?User $actor = null): void
    {
        $credential->update(['revoked_at' => now()]);
        $this->audit->record('api_credential.revoked', $actor, entity: $credential, after: ['revoked_at' => $credential->revoked_at]);
    }

    public function digest(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}


