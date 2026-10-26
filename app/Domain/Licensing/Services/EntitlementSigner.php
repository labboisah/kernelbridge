<?php

namespace App\Domain\Licensing\Services;

use RuntimeException;

final class EntitlementSigner
{
    public function configured(): bool
    {
        return filled(config('product_integration.signing.secret_key'));
    }

    /**
     * The signature covers the exact bytes of payload_json, so verifiers must not re-serialize it.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload_json: string, signature: string, algorithm: string, key_id: string}
     */
    public function sign(array $payload): array
    {
        $secret = base64_decode((string) config('product_integration.signing.secret_key'), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Entitlement signing key is not configured.');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'payload_json' => $json,
            'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)),
            'algorithm' => 'ed25519',
            'key_id' => (string) config('product_integration.signing.key_id'),
        ];
    }
}
