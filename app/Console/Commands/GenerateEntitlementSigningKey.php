<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class GenerateEntitlementSigningKey extends Command
{
    protected $signature = 'product-integration:generate-signing-key {--write : Write the secret key to .env when none is set}';

    protected $description = 'Generate the Ed25519 key pair used to sign installation entitlement snapshots.';

    public function handle(): int
    {
        $keyId = (string) config('product_integration.signing.key_id');

        if (! $keyId) {
            $this->error('KERNELBRIDGE_ENTITLEMENT_KEY_ID must be configured before generating trusted client key metadata.');

            return self::FAILURE;
        }

        $configuredSecret = config('product_integration.signing.secret_key');
        $isConfigured = is_string($configuredSecret) && $configuredSecret !== '';

        if ($isConfigured) {
            $secret = base64_decode($configuredSecret, true);

            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $this->error('The configured entitlement signing key is not a valid base64 Ed25519 secret key.');

                return self::FAILURE;
            }

            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
            $this->info("Using the active signing key with key ID [$keyId]; no key material was changed.");
        } else {
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);

            if ($this->option('write')) {
                $path = base_path('.env');
                $env = is_file($path) ? (string) file_get_contents($path) : '';

                if (preg_match('/^KERNELBRIDGE_ENTITLEMENT_SIGNING_KEY=.+$/m', $env)) {
                    $this->error('A signing key is already set in .env; refusing to overwrite it.');

                    return self::FAILURE;
                }

                file_put_contents($path, rtrim($env)."\nKERNELBRIDGE_ENTITLEMENT_SIGNING_KEY=".base64_encode($secret)."\n");
                $this->info('Signing key written to .env.');
            } else {
                $this->warn('This is a new key and is not active until configured in KernelBridge.');
                $this->line('KERNELBRIDGE_ENTITLEMENT_SIGNING_KEY='.base64_encode($secret));
            }
        }

        $this->info('Trusted client public-key map (KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS):');
        $this->line(json_encode([$keyId => base64_encode($public)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
