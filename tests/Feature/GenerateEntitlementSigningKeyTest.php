<?php

namespace Tests\Feature;

use Tests\TestCase;

final class GenerateEntitlementSigningKeyTest extends TestCase
{
    public function test_it_outputs_the_public_key_for_the_active_signing_key(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $keyId = 'active-test-key';

        config([
            'product_integration.signing.secret_key' => base64_encode($secretKey),
            'product_integration.signing.key_id' => $keyId,
        ]);

        $this->artisan('product-integration:generate-signing-key')
            ->expectsOutputToContain('Using the active signing key')
            ->expectsOutputToContain(json_encode([$keyId => base64_encode($publicKey)]))
            ->assertExitCode(0);
    }
}
