<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | supports_credentials must be true and allowed_origins must be an exact
    | origin (never '*') for Sanctum's cookie-based SPA auth to work — the
    | browser refuses to send/accept credentials on a wildcard-origin response.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3001,http://localhost:3002,http://localhost:3003,http://127.0.0.1:3000,http://127.0.0.1:3001,http://127.0.0.1:3002,http://127.0.0.1:3003'
    ))))),

    'allowed_origins_patterns' => env('CORS_ALLOW_LOCAL_DEVELOPMENT', env('APP_ENV') !== 'production')
        ? ['#^http://(localhost|127\.0\.0\.1):(3000|3001|3002|3003)$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
