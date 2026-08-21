<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use DateTimeInterface;

/**
 * Builds the Gemini text prompt for document verification: authenticity assessment plus, for
 * document types listed in config('credit_documents.ai_comparable_fields'), field extraction
 * and comparison against the applicant's Client (Étape 1) form data. Pure class — no I/O,
 * so it is trivially unit-testable.
 */
class DocumentVerificationPromptBuilder
{
    public function build(Document $document, Client $client): string
    {
        $documentType = $document->document_type;
        $typeLabel = config("credit_documents.types.{$documentType}.label", $documentType);
        $comparableFields = config("credit_documents.ai_comparable_fields.{$documentType}", []);
        $fieldLabels = config('credit_documents.ai_field_labels', []);

        $parts = [];
        $parts[] = 'You are reviewing an identity/proof document (e.g. national ID card, '
            .'passport, residence card) submitted as part of a bank credit application. The document '
            ."type claimed by the applicant is \"{$typeLabel}\". "
            .'Assess whether it looks like a genuine, legible, unaltered document of that kind — '
            .'not whether the applicant is creditworthy.';

        $parts[] = 'TASK 1 — AUTHENTICITY: Flag obvious fakes, screenshots of screens, tampering, '
            .'or unreadable/blurry scans. If the uploaded file is not an image (PDF, Word, etc.), '
            .'text may not be readable — say so in the comment and set confidence to "low".';

        if ($comparableFields) {
            $parts[] = 'TASK 2 — FIELD EXTRACTION: Extract the following fields if they are visible '
                .'on the document (use null when not legible):';

            foreach ($comparableFields as $field) {
                $parts[] = '  - '.$field.' ('.($fieldLabels[$field] ?? $field).')';
            }

            $parts[] = 'TASK 3 — COMPARISON: Compare each extracted field against the expected '
                .'applicant values below. Compare names case-insensitively and ignoring extra '
                .'whitespace; compare dates in ISO yyyy-mm-dd format. For every difference, add a '
                .'"mismatch" entry. Mark a mismatch as "critical" when it is a substantial identity '
                .'discrepancy (different name, different document number, different birth date); use '
                .'"warning" for minor formatting or partial differences.';

            $parts[] = 'Expected applicant values (from the credit application form):';
            foreach ($comparableFields as $field) {
                $parts[] = '  - '.$field.': "'.$this->clientValue($client, $field).'"';
            }
        } else {
            $parts[] = 'No field comparison is required for this document type: do not attempt to '
                .'extract or compare any fields, and return mismatches as an empty array.';
        }

        $parts[] = 'Respond with ONLY a JSON object matching this exact schema: '
            .'{"is_valid": boolean, "confidence": "high"|"medium"|"low", '
            .'"comment": "one short sentence explaining the verdict", '
            .'"extracted_fields": {"field_name": "value or null"}, '
            .'"mismatches": [{"field": "field_name", "expected": "value", "extracted": "value", '
            .'"severity": "critical"|"warning"}]}. '
            .'is_valid must be false when the document looks inauthentic, tampered, or like a '
            .'screenshot, OR when any mismatch has severity "critical". extracted_fields and '
            .'mismatches may be empty objects/arrays when nothing is visible or comparable.';

        return implode(' ', $parts);
    }

    private function clientValue(Client $client, string $field): string
    {
        $value = $client->{$field};

        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
