<?php

namespace App\Domain\Licensing\Services;

use RuntimeException;

final class LicenseSecretHasher
{
    /**
     * @return array{hash: string, key_id: string}
     */
    public function hash(string $value): array
    {
        $keyId = (string) config('product_integration.credential_hmac.active_key_id', 'legacy-app-key');
        $key = $this->key($keyId);

        return [
            'hash' => hash_hmac('sha256', $value, $key),
            'key_id' => $keyId,
        ];
    }

    /**
     * @return list<string>
     */
    public function candidateHashes(string $value): array
    {
        $hashes = [];
        foreach ($this->keys() as $key) {
            $hashes[] = hash_hmac('sha256', $value, $key);
        }

        return array_values(array_unique($hashes));
    }

    public function matches(string $value, string $expectedHash, ?string $keyId): bool
    {
        if ($keyId !== null) {
            return hash_equals($expectedHash, hash_hmac('sha256', $value, $this->key($keyId)));
        }

        foreach ($this->keys() as $key) {
            if (hash_equals($expectedHash, hash_hmac('sha256', $value, $key))) {
                return true;
            }
        }

        return false;
    }

    private function key(string $keyId): string
    {
        $configuredKeys = config('product_integration.credential_hmac.keys', []);
        if (is_array($configuredKeys) && isset($configuredKeys[$keyId]) && is_string($configuredKeys[$keyId]) && $configuredKeys[$keyId] !== '') {
            return $configuredKeys[$keyId];
        }

        if ($keyId === 'legacy-app-key' && filled(config('app.key'))) {
            return (string) config('app.key');
        }

        throw new RuntimeException("License credential HMAC key [{$keyId}] is not configured.");
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $configuredKeys = config('product_integration.credential_hmac.keys', []);
        $keys = is_array($configuredKeys)
            ? array_values(array_filter($configuredKeys, fn ($key): bool => is_string($key) && $key !== ''))
            : [];
        $legacyAppKey = config('app.key');
        if (is_string($legacyAppKey) && $legacyAppKey !== '') {
            $keys[] = $legacyAppKey;
        }

        if ($keys === []) {
            throw new RuntimeException('No license credential HMAC keys are configured.');
        }

        return array_values(array_unique($keys));
    }
}
