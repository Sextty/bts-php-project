<?php

return [
    // One year is the standard HSTS lifetime for a deployed HTTPS API. The
    // middleware only sends it for a secure request, never local HTTP.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),

    'telemetry' => [
        'osquery_binary' => env('OSQUERY_BINARY_PATH'),
        'timeout_seconds' => (int) env('OSQUERY_TIMEOUT_SECONDS', 10),
        'max_rows' => (int) env('OSQUERY_MAX_ROWS', 200),
    ],
];
