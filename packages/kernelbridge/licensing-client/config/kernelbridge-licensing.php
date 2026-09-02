<?php

return [
    'api_url' => env('KERNELBRIDGE_API_URL', 'https://kernelbridge.com/api/v1'),
    'product_code' => env('KERNELBRIDGE_PRODUCT_CODE'),
    'api_token' => env('KERNELBRIDGE_API_TOKEN'),
    'timeout_seconds' => (int) env('KERNELBRIDGE_API_TIMEOUT', 10),
    'connect_timeout_seconds' => (int) env('KERNELBRIDGE_API_CONNECT_TIMEOUT', 3),
    'verification_interval_minutes' => (int) env('KERNELBRIDGE_VERIFICATION_INTERVAL', 15),
    'verification_queue' => env('REDIS_NOTIFICATION_QUEUE', env('REDIS_QUEUE', 'default')),
    'offline_grace_hours' => (int) env('KERNELBRIDGE_OFFLINE_GRACE_HOURS', 72),
    'signature_key' => env('KERNELBRIDGE_CACHE_SIGNING_KEY', env('APP_KEY')),
];
