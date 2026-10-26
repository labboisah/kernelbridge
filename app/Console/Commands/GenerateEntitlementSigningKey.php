<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class GenerateEntitlementSigningKey extends Command
{
    protected $signature = 'product-integration:generate-signing-key {--write : Write the secret key to .env when none is set}';

    protected $description = 'Generate the Ed25519 key pair used to sign installation entitlement snapshots.';

    public function handle(): int
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        if ($this->option('write')) {
            $path = base_path('.env');
            $env = is_file($path) ? (string) file_get_contents($path) : '';

            if (preg_match('/^KERNELBRIDGE_ENTITLEMENT_SIGNING_KEY=.+$/m', $env)) {
                $this->error('A signing key is already set; refusing to overwrite it.');

                return self::FAILURE;
            }

            file_put_contents($path, rtrim($env)."\nKERNELBRIDGE_ENTITLEMENT_SIGNING_KEY=".$secret."\n");
            $this->info('Signing key written to .env.');
        } else {
            $this->line('KERNELBRIDGE_ENTITLEMENT_SIGNING_KEY='.$secret);
        }

        $this->info('Public key for Business Server (KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEY):');
        $this->line($public);

        return self::SUCCESS;
    }
}
