<?php

return [
    // Existing protected rows are tagged with legacy-app-key by the upgrade migration.
    // Keep AUDIT_HMAC_LEGACY_KEY available until those rows no longer need verification.
    'active_key_version' => env('AUDIT_HMAC_KEY_VERSION', 'v1'),

    'keys' => [
        'legacy-app-key' => env('AUDIT_HMAC_LEGACY_KEY') ?: env('APP_KEY'),
        'v1' => env('AUDIT_HMAC_KEY') ?: env('APP_KEY'),
    ],
];
