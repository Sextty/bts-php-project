<?php

$production = env('APP_ENV') === 'production';

return [
    'queue' => [
        'max_ready' => (int) env('QUEUE_HEALTH_MAX_PENDING', 10_000),
        'max_ready_age_seconds' => (int) env('QUEUE_HEALTH_MAX_AGE_SECONDS', 300),
        'max_failed' => (int) env('QUEUE_HEALTH_MAX_FAILED', 0),
    ],
    'outbox' => [
        'max_pending' => (int) env('OUTBOX_HEALTH_MAX_PENDING', 10_000),
        'max_pending_age_seconds' => (int) env('OUTBOX_HEALTH_MAX_AGE_SECONDS', 300),
        'max_failed' => (int) env('OUTBOX_HEALTH_MAX_FAILED', 0),
        'processing_timeout_seconds' => (int) env('OUTBOX_PROCESSING_TIMEOUT_SECONDS', 120),
    ],
    'worker' => [
        'required' => (bool) env('QUEUE_WORKER_HEALTH_REQUIRED', $production),
        'heartbeat_key' => env('QUEUE_WORKER_HEARTBEAT_KEY', 'operations:queue-worker:last-seen'),
        'max_age_seconds' => (int) env('QUEUE_WORKER_HEARTBEAT_MAX_AGE', 120),
    ],
    'scheduler' => [
        'required' => (bool) env('SCHEDULER_HEALTH_REQUIRED', $production),
        'heartbeat_key' => env('SCHEDULER_HEARTBEAT_KEY', 'operations:scheduler:last-seen'),
        'max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE', 120),
    ],
    'reverb' => [
        'timeout_seconds' => (float) env('REVERB_HEALTH_TIMEOUT_SECONDS', 2),
    ],
    'storage' => [
        // This check applies only to the local documents disk. Object storage capacity is a
        // provider concern and must be monitored by that provider instead.
        'min_free_bytes' => (int) env('STORAGE_HEALTH_MIN_FREE_BYTES', 1_073_741_824),
    ],
];
