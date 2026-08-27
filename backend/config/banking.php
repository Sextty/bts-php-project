<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Internal transfer controls
    |--------------------------------------------------------------------------
    |
    | Amounts are Tunisian millimes (1 TND = 1,000 millimes). These conservative
    | defaults protect a new installation; the bank must approve and set its own
    | product and risk-policy limits before any production launch.
    |
    */
    'transfers' => [
        'max_per_transfer_millimes' => (int) env('BANKING_TRANSFER_MAX_PER_TRANSFER_MILLIMES', 50_000_000),
        'daily_limit_per_source_millimes' => (int) env('BANKING_TRANSFER_DAILY_LIMIT_PER_SOURCE_MILLIMES', 100_000_000),
        'daily_limit_timezone' => env('BANKING_TRANSFER_LIMIT_TIMEZONE', 'Africa/Tunis'),
    ],
];
