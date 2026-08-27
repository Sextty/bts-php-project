<?php

// Configurable required/optional document types for a credit application (Part 1 spec,
// section 8: "The exact required document types must be configurable"). Keys are the
// `document_type` values stored on the `documents` table; adding a new type here doesn't
// require a migration.
return [

    'max_size_kb' => env('CREDIT_DOCUMENT_MAX_SIZE_KB', 10240),

    // Keep banking validation strict by default. Local synthetic/demo environments may
    // explicitly disable this when the attachment step is intentionally hidden.
    'require_at_least_one_for_validation' => (bool) env('DOCUMENTS_REQUIRED_FOR_VALIDATION', true),

    'malware_scan' => [
        // Set to "required" in production. "optional" keeps XAMPP development usable when
        // ClamAV is not installed, while recording that the file still needs human controls.
        'mode' => env('DOCUMENT_MALWARE_SCAN', 'optional'),
        'binary' => env('CLAMAV_BINARY'),
        'timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 30),
    ],

    // Every format a client may need to attach: PDF, images, Office documents, CSV/text.
    // The `mimes` rule derives from this list; the AI verification is advisory and best-effort,
    // so non-image files just don't get a verdict instead of blocking the upload.
    'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx'],

    // Content-sniffed MIME allowlist (finfo on the uploaded bytes), keyed by the extension the
    // server derives from the REAL content — the request-level `mimes:` rule only checks the
    // client-declared extension, which is trivially spoofable (a .txt renamed to .pdf passes
    // it). DocumentStorageService re-validates every upload against this map and stores the
    // file under the extension that matches its actual bytes, so the stored name can never
    // lie about the content. The extension in the key is also the extension stored on disk.
    'allowed_mime_types' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
    ],

    'financing_categories' => [
        'eqp' => 'Équipement Professionnel (EQP)',
        'fdr' => 'Fonds de Roulement (FDR)',
        'amg' => 'Aménagement des Locaux (AMG)',
        'chp' => 'Achat de Cheptel (CHP)',
        'epr' => 'Équipement Professionnel (EPR)',
    ],

    'types' => [
        'eqp' => [
            'label' => 'Équipement Professionnel (EQP) — Devis & Facture matériel',
            'category' => 'financing',
            'required' => false,
        ],
        'fdr' => [
            'label' => 'Fonds de Roulement (FDR) — Devis / Facture proforma stock',
            'category' => 'financing',
            'required' => false,
        ],
        'amg' => [
            'label' => 'Aménagement des Locaux (AMG) — Devis travaux / Plans',
            'category' => 'financing',
            'required' => false,
        ],
        'chp' => [
            'label' => 'Achat de Cheptel (CHP) — Facture proforma / Certificat vétérinaire',
            'category' => 'financing',
            'required' => false,
        ],
        'epr' => [
            'label' => 'Équipement Professionnel (EPR) — Facture proforma matériel',
            'category' => 'financing',
            'required' => false,
        ],
        'devis' => [
            'label' => 'Devis & Factures Proforma Fournisseurs',
            'category' => 'financing',
            'required' => false,
        ],
        'contrat_location' => [
            'label' => 'Contrat de Location / Bail Commercial',
            'category' => 'financing',
            'required' => false,
        ],
        'cin' => [
            'label' => 'Carte d\'Identité Nationale (CIN)',
            'required' => false,
        ],
        'diplome' => [
            'label' => 'Diplôme / Certificat de formation professionnelle',
            'required' => false,
        ],
        'passport' => [
            'label' => 'Passeport',
            'required' => false,
        ],
        'carte_sejour' => [
            'label' => 'Carte de Séjour',
            'required' => false,
        ],
        'fiche_paie' => [
            'label' => 'Bulletin de salaire',
            'required' => false,
        ],
        'justificatif_revenus' => [
            'label' => 'Justificatif de revenus',
            'required' => false,
        ],
        'attestation_emploi' => [
            'label' => 'Attestation d\'emploi',
            'required' => false,
        ],
        'releve_bancaire' => [
            'label' => 'Relevé bancaire',
            'required' => false,
        ],
        'justificatif_domicile' => [
            'label' => 'Justificatif de domicile',
            'required' => false,
        ],
        'contrat' => [
            'label' => 'Contrat',
            'required' => false,
        ],
        'facture' => [
            'label' => 'Facture',
            'required' => false,
        ],
        'other' => [
            'label' => 'Autre document',
            'required' => false,
        ],
    ],

    // Which Client (Étape 1) fields the AI should extract from a document and compare against
    // the form data. Document types absent from this map are checked for authenticity only —
    // no field extraction/comparison, so they can never be blocked on a mismatch.
    'ai_comparable_fields' => [
        'cin' => ['nom', 'prenom', 'date_naissance', 'numero_pid', 'date_delivrance_pid'],
        'passport' => ['nom', 'prenom', 'date_naissance', 'numero_pid'],
        'carte_sejour' => ['nom', 'prenom', 'numero_carte_sejour', 'date_delivrance_pid'],
    ],

    // Human-readable labels for the comparable fields, used in AI mismatch messages (client
    // errors and the staff/admin review UI).
    'ai_field_labels' => [
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'date_naissance' => 'Date de naissance',
        'numero_pid' => 'N° de la pièce d\'identité',
        'date_delivrance_pid' => 'Date de délivrance de la pièce',
        'numero_carte_sejour' => 'N° de la carte de séjour',
    ],

];
