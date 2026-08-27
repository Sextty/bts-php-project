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
        'e2e_otp_file' => env('E2E_OTP_FILE'),
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
        // Off by default: emailing the OTP synchronously keeps the current behaviour without a
        // queue worker. With OTP_QUEUE_DELIVERY=true + a running worker
        // (QUEUE_CONNECTION=database, `php artisan queue:work`), every OTP email is sent from
        // DeliverOtpEmailJob instead of the HTTP request. See DeliverOtpEmailJob.
        'queue_delivery' => env('OTP_QUEUE_DELIVERY', false),
    ],

    // Password-reset emails (User::sendPasswordResetNotification). Same opt-in queuing story as
    // OTP delivery: off by default, SendPasswordResetEmailJob when enabled + worker running.
    'password_reset' => [
        'queue_delivery' => env('PASSWORD_RESET_QUEUE_DELIVERY', false),
    ],

    // Local-only prevents any external document transfer. Cloud adapters are explicit opt-ins.
    'document_verification' => [
        'provider' => env('DOCUMENT_VERIFICATION_PROVIDER', 'local'),
        'max_payload_bytes' => (int) env('DOCUMENT_AI_MAX_PAYLOAD_BYTES', 15 * 1024 * 1024),
        // One HTTP request can contain several documents. Keep the complete advisory AI pass
        // below PHP/web-server limits; documents not reached remain available for Staff review.
        'validation_budget_seconds' => (int) env('DOCUMENT_AI_VALIDATION_BUDGET_SECONDS', 20),
    ],

    // OpenRouter OpenAI-compatible adapter. MiniMax M3 is multimodal; PDF input uses
    // OpenRouter's free Cloudflare parser so the selected free model can inspect PDF content.
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'minimax/minimax-m3:free'),
        'http_referer' => env('OPENROUTER_HTTP_REFERER', env('APP_URL')),
        'app_title' => env('OPENROUTER_APP_TITLE', 'BTS Bank Development'),
        'connect_timeout_seconds' => (int) env('OPENROUTER_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 45),
        'max_retries' => (int) env('OPENROUTER_MAX_RETRIES', 1),
        'retry_delay_ms' => (int) env('OPENROUTER_RETRY_DELAY_MS', 500),
        'max_output_tokens' => (int) env('OPENROUTER_MAX_OUTPUT_TOKENS', 768),
        'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.1),
        'reasoning_enabled' => (bool) env('OPENROUTER_REASONING_ENABLED', true),
        'pdf_engine' => env('OPENROUTER_PDF_ENGINE', 'cloudflare-ai'),
    ],

    // Optional Gemini adapter for advisory document authenticity checks.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        // Transport resilience: connect timeout, overall request timeout, bounded retries with
        // exponential backoff for 429/5xx/network failures, and the file size cap the AI is
        // willing to look at. A permanently failing API degrades to "not yet checked" — it
        // never blocks an application (see CreditApplicationValidationService).
        'connect_timeout_seconds' => (int) env('GEMINI_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT_SECONDS', 25),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 1),
        'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 250),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 512),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.1),
        'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 0),
        'max_payload_bytes' => (int) env('GEMINI_MAX_PAYLOAD_BYTES', 15 * 1024 * 1024),
    ],

    // Notification delivery: which channels each notification type is sent through. 'in-app'
    // persists the notification and broadcasts it over Reverb; 'email' sends a plain-text
    // email; 'sms' sends via the configured SMS provider (Vonage) and silently skips when no
    // provider is configured. Types absent from this map default to ['in-app'].
    'notifications' => [
        'channels' => [
            'application.submitted' => ['in-app'],
            'document.rejected' => ['in-app', 'email'],
            'staff.approved' => ['in-app'],
            'staff.rejected' => ['in-app', 'email'],
            'admin.approved' => ['in-app', 'email'],
            'admin.rejected' => ['in-app', 'email'],
            // The final approval email already contains the first appointment details.
            'appointment.created' => ['in-app'],
            'appointment.changed' => ['in-app', 'email'],
            'report.message' => ['in-app'],
        ],
    ],

];
