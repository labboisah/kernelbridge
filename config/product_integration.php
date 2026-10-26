<?php

return [
    'signing' => [
        // Base64 Ed25519 secret key; generate with `php artisan product-integration:generate-signing-key`.
        'secret_key' => env('KERNELBRIDGE_ENTITLEMENT_SIGNING_KEY'),
        'key_id' => env('KERNELBRIDGE_ENTITLEMENT_KEY_ID', 'kb-1'),
    ],

    // Per product code (uppercase). A product without a url receives no webhooks.
    'webhooks' => [
        'MARKETLENS' => [
            'url' => env('MARKETLENS_WEBHOOK_URL'),
            'secret' => env('MARKETLENS_WEBHOOK_SECRET'),
        ],
    ],
];
