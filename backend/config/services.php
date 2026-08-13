<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'log'),
    ],

    'vonage' => [
        'api_key' => env('VONAGE_API_KEY'),
        'api_secret' => env('VONAGE_API_SECRET'),
        // Alphanumeric sender ID shown as the "from" — some destination countries' carriers
        // reject alphanumeric senders and require a real Vonage virtual number instead; if OTP
        // delivery fails silently, that's the first thing to check.
        'brand_name' => env('VONAGE_BRAND_NAME', 'BTSBank'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
    ],

    'otp' => [
        'length' => env('OTP_LENGTH', 6),
        'ttl_minutes' => env('OTP_TTL_MINUTES', 5),
        'max_attempts' => env('OTP_MAX_ATTEMPTS', 5),
        'request_cooldown_seconds' => env('OTP_REQUEST_COOLDOWN_SECONDS', 60),
    ],

    // OpenRouter — one OpenAI-compatible API in front of many providers' free vision models, so
    // swapping which model verifies documents is an env var change, not a code change.
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'nvidia/nemotron-nano-12b-v2-vl:free'),
    ],

];
