<?php

namespace App\Domain\Demo\Services;

use App\Models\ApiClient;
use App\Models\DemoAccessToken;
use App\Models\DemoProfile;
use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DemoModeService
{
    public function config(ApiClient $client): array
    {
        $profile = $this->profile($client);

        return ['profile_uuid' => $profile->uuid, 'product' => ['uuid' => $client->product->uuid, 'code' => $client->product->code], 'public_demo_url' => $profile->public_demo_url, 'active' => $profile->is_active, 'requires_access_token' => $profile->requires_access_token, 'config_version' => $profile->config_version, 'restrictions' => collect(['create', 'edit', 'delete', 'export', 'email', 'sms', 'payment', 'configuration', 'user_management'])->mapWithKeys(fn ($key) => [$key => (bool) $profile->{'allow_'.$key}])->all(), 'hard_blocks' => ['core_demo_credentials' => true, 'api_credentials' => true, 'protected_demo_administrators' => true, 'production_sms' => true, 'production_email' => true, 'real_financial_transactions' => true, 'cross_organization_access' => true, 'server_configuration' => true], 'protected_roles' => $profile->protected_roles ?? ['super_admin', 'demo_admin'], 'protected_accounts' => $profile->protected_accounts ?? [], 'configuration' => $profile->configuration ?? [], 'reset' => ['enabled' => $profile->reset_enabled, 'schedule' => $profile->reset_schedule, 'instructions' => $profile->reset_instructions, 'instruction_version' => $profile->reset_instruction_version, 'next_at' => $profile->next_reset_at?->toISOString()]];
    }

    public function issueToken(DemoProfile $profile, ?string $label = null, ?int $maxUses = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $raw = 'kbd_'.Str::random(64);
        $token = $profile->tokens()->create(['token_lookup' => $this->lookup($raw), 'token_hash' => Hash::make($raw), 'label' => $label, 'max_uses' => $maxUses, 'expires_at' => $expiresAt]);

        return ['token' => $token, 'access_token' => $raw];
    }

    public function startSession(ApiClient $client, array $data): DemoSession
    {
        return DB::transaction(function () use ($client, $data) {
            $profile = $this->profile($client);
            $token = null;
            if ($profile->requires_access_token || ! empty($data['access_token'])) {
                $token = $this->resolveToken($profile, (string) ($data['access_token'] ?? ''));
                $token->increment('uses');
                $token->update(['last_used_at' => now()]);
            }$anonymous = hash_hmac('sha256', $data['anonymous_identifier'], (string) config('app.key'));

            return $profile->sessions()->create(['product_id' => $client->product_id, 'demo_access_token_id' => $token?->id, 'anonymous_identifier_hash' => $anonymous, 'organization_identifier' => $data['organization_identifier'] ?? null, 'started_at' => now(), 'last_activity_at' => now(), 'entry_url' => $data['entry_url'] ?? null, 'referrer' => $data['referrer'] ?? null, 'metadata' => $data['metadata'] ?? null]);
        });
    }

    public function recordEvent(ApiClient $client, DemoSession $session, array $data): string
    {
        if ($session->product_id !== $client->product_id || $session->ended_at) {
            throw ValidationException::withMessages(['session_uuid' => 'The demo session is invalid or closed.']);
        }
        $existing = $session->events()->where('external_event_id', $data['external_event_id'])->first();
        if ($existing) {
            return $existing->uuid;
        }
        $event = $session->events()->create(['product_id' => $client->product_id, ...$data]);
        $session->update(['last_activity_at' => now()]);

        return $event->uuid;
    }

    public function endSession(ApiClient $client, DemoSession $session): void
    {
        if ($session->product_id !== $client->product_id) {
            throw ValidationException::withMessages(['session_uuid' => 'The demo session is invalid.']);
        }$session->update(['ended_at' => $session->ended_at ?? now(), 'last_activity_at' => now()]);
    }

    public function acknowledgeReset(ApiClient $client, int $version): void
    {
        $profile = $this->profile($client);
        if (! $profile->reset_enabled || $version !== $profile->reset_instruction_version) {
            throw ValidationException::withMessages(['instruction_version' => 'The reset instruction version is not current.']);
        }$profile->update(['last_reset_acknowledged_at' => now(), 'last_acknowledged_version' => $version]);
    }

    private function profile(ApiClient $client): DemoProfile
    {
        return DemoProfile::query()->where('product_id', $client->product_id)->where('is_active', true)->firstOrFail();
    }

    private function resolveToken(DemoProfile $profile, string $raw): DemoAccessToken
    {
        $token = $profile->tokens()->lockForUpdate()->where('token_lookup', $this->lookup($raw))->first();
        if (! $token || ! Hash::check($raw, $token->token_hash) || $token->revoked_at || ($token->expires_at && $token->expires_at->isPast()) || ($token->max_uses !== null && $token->uses >= $token->max_uses)) {
            throw ValidationException::withMessages(['access_token' => 'The demo access token is invalid, expired, or exhausted.']);
        }

        return $token;
    }

    private function lookup(string $raw): string
    {
        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }
}
