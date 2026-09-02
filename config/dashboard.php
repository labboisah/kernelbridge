<?php

return [
    'cache_store' => env('DASHBOARD_CACHE_STORE', env('CACHE_STORE', 'redis')),
    'cache_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL', 300),
];
