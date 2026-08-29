<?php

use App\Models\CreditApplication;

return [
    'generator_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Synthetic namespace
    |--------------------------------------------------------------------------
    |
    | Every generated identifier carries this namespace. The reserved .invalid
    | domain and the +2161 test range ensure generated contacts cannot be used
    | as real delivery targets.
    |
    */
    'namespace' => 'bts-synthetic',
    'email_domain' => 'synthetic.bts.invalid',
    'default_seed' => '20260823',
    'anchor_date' => '2026-08-23 12:00:00',

    'profiles' => [
        'small' => [
            'customers' => 1_000,
            'applications' => 1_300,
            'years' => 2,
            'chunk' => 250,
            'materialize_documents' => true,
        ],
        'medium' => [
            'customers' => 50_000,
            'applications' => 65_000,
            'years' => 3,
            'chunk' => 750,
            'materialize_documents' => false,
        ],
        'large' => [
            'customers' => 500_000,
            'applications' => 650_000,
            'years' => 5,
            'chunk' => 1_500,
            'materialize_documents' => false,
        ],
        'massive' => [
            'customers' => 1_000_000,
            'applications' => 1_300_000,
            'years' => 7,
            'chunk' => 2_000,
            'materialize_documents' => false,
        ],
    ],

    'limits' => [
        // Hard caps keep custom runs inside the memory and slot-allocation design.
        // The massive profile remains sufficient for serious database stress tests.
        'customers' => 2_000_000,
        'applications' => 3_000_000,
        'applications_per_customer' => 3,
        'chunk_min' => 10,
        'chunk_max' => 2_000,
        'insert_batch' => 500,
    ],

    /* Stable, deliberately non-uniform workload mix. Total weight is 100. */
    'status_weights' => [
        CreditApplication::STATUS_DRAFT => 12,
        CreditApplication::STATUS_STEP_1_COMPLETED => 6,
        CreditApplication::STATUS_STEP_2_COMPLETED => 6,
        CreditApplication::STATUS_STEP_3_COMPLETED => 1,
        CreditApplication::STATUS_READY_FOR_VALIDATION_1 => 10,
        CreditApplication::STATUS_VALIDATION_1_COMPLETED => 7,
        CreditApplication::STATUS_VALIDATION_2 => 2,
        CreditApplication::STATUS_FINAL_LOCKED => 1,
        CreditApplication::STATUS_SUBMITTED => 15,
        CreditApplication::STATUS_STAFF_APPROVED => 10,
        CreditApplication::STATUS_STAFF_REJECTED => 6,
        CreditApplication::STATUS_APPROVED => 1,
        CreditApplication::STATUS_REJECTED => 6,
        CreditApplication::STATUS_APPOINTMENT_PROPOSED => 6,
        CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 7,
        CreditApplication::STATUS_APPOINTMENT_LOCKED => 2,
        CreditApplication::STATUS_CANCELLED => 2,
    ],

    'year_weights' => [45, 25, 15, 8, 4, 2, 1],
    'month_weights' => [8, 7, 8, 9, 11, 12, 9, 7, 8, 9, 7, 5],

    'checkpoint_directory' => storage_path('app/private/synthetic-data/checkpoints'),
    // Matches DocumentService and the application's download/verification path.
    'document_disk' => 'documents',
    'document_directory' => 'synthetic-data',

    /*
    | Production execution requires every switch below, plus all three command
    | options. Keep disabled and token unset in normal environments.
    */
    'production' => [
        'enabled' => (bool) env('SYNTHETIC_DATA_ALLOW_PRODUCTION', false),
        'token' => (string) env('SYNTHETIC_DATA_PRODUCTION_TOKEN', ''),
        'confirmation' => 'GENERATE_SYNTHETIC_TEST_DATA',
    ],
];
