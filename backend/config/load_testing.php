<?php

return [
    /*
    | The load-test runtime is deliberately fail-closed. It is enabled only by
    | the isolated runner and never has a production override.
    */
    'enabled' => (bool) env('BTS_LOAD_TEST_ENABLED', false),
    'database_pattern' => '/^bts_load_[0-9]{14}(?:_[a-z0-9]+)?$/',
    'marker_directory' => storage_path('app/private/load-testing'),
    'otp_collector_url' => env('BTS_LOAD_TEST_OTP_COLLECTOR_URL'),
    'otp_collector_token' => env('BTS_LOAD_TEST_OTP_COLLECTOR_TOKEN'),
    'max_accounts' => (int) env('BTS_LOAD_TEST_MAX_ACCOUNTS', 500),
    'max_concurrency' => (int) env('BTS_LOAD_TEST_MAX_CONCURRENCY', 100),
    'max_applications_per_account' => (int) env('BTS_LOAD_TEST_MAX_APPLICATIONS_PER_ACCOUNT', 3),
];
