<?php

// Configurable required/optional document types for a credit application (Part 1 spec,
// section 8: "The exact required document types must be configurable"). Keys are the
// `document_type` values stored on the `documents` table; adding a new type here doesn't
// require a migration.
return [

    'max_size_kb' => env('CREDIT_DOCUMENT_MAX_SIZE_KB', 10240),

    'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],

    'types' => [
        'cin' => [
            'label' => 'Carte d\'Identité Nationale',
            'required' => true,
        ],
        'passport' => [
            'label' => 'Passeport',
            'required' => false,
        ],
        'carte_sejour' => [
            'label' => 'Carte de Séjour',
            'required' => false,
        ],
        'other' => [
            'label' => 'Autre document',
            'required' => false,
        ],
    ],

];
