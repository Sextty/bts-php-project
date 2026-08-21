<?php

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiMalformedResponseException;

/**
 * Strict schema validation for Gemini's JSON verdict. The AI output is untrusted input: every
 * field is type-checked and value-checked, and anything off-spec is rejected as a malformed
 * response rather than silently coerced — a wrong-typed confidence or an unknown severity must
 * never reach the domain as if it were a real verdict.
 *
 * Returns a normalized array with exactly these keys:
 *   is_valid (bool), confidence ('high'|'medium'|'low'), comment (non-empty string),
 *   extracted_fields (array<string, string|null>), mismatches (list of
 *   {field, expected|null, extracted|null, severity: 'critical'|'warning'}).
 */
class GeminiResponseValidator
{
    private const CONFIDENCE_LEVELS = ['high', 'medium', 'low'];

    private const SEVERITY_LEVELS = ['critical', 'warning'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     is_valid: bool,
     *     confidence: 'high'|'medium'|'low',
     *     comment: string,
     *     extracted_fields: array<string, string|null>,
     *     mismatches: list<array{field: string, expected: string|null, extracted: string|null, severity: 'critical'|'warning'}>
     * }
     *
     * @throws GeminiMalformedResponseException
     */
    public function validate(array $payload): array
    {
        $required = ['is_valid', 'confidence', 'comment'];

        $missing = array_values(array_diff($required, array_keys($payload)));

        if ($missing) {
            throw new GeminiMalformedResponseException(
                'Gemini response JSON is missing expected field(s): '.implode(', ', $missing).'.',
            );
        }

        if (! is_bool($payload['is_valid'])) {
            throw new GeminiMalformedResponseException('Gemini response field "is_valid" must be a boolean.');
        }

        if (! is_string($payload['confidence']) || ! in_array($payload['confidence'], self::CONFIDENCE_LEVELS, true)) {
            throw new GeminiMalformedResponseException('Gemini response field "confidence" must be one of: high, medium, low.');
        }

        if (! is_string($payload['comment']) || trim($payload['comment']) === '') {
            throw new GeminiMalformedResponseException('Gemini response field "comment" must be a non-empty string.');
        }

        $rawExtracted = $payload['extracted_fields'] ?? [];

        if (! is_array($rawExtracted)) {
            throw new GeminiMalformedResponseException('Gemini response field "extracted_fields" must be an object.');
        }

        $extractedFields = [];

        foreach ($rawExtracted as $field => $value) {
            if (! is_string($field) || $field === '') {
                throw new GeminiMalformedResponseException('Gemini response "extracted_fields" keys must be non-empty strings.');
            }

            $extractedFields[$field] = $value === null ? null : (string) $value;
        }

        $rawMismatches = $payload['mismatches'] ?? [];

        if (! is_array($rawMismatches)) {
            throw new GeminiMalformedResponseException('Gemini response field "mismatches" must be an array.');
        }

        $mismatches = [];

        foreach ($rawMismatches as $mismatch) {
            if (! is_array($mismatch) || ! isset($mismatch['field']) || ! is_string($mismatch['field']) || $mismatch['field'] === '') {
                throw new GeminiMalformedResponseException('Gemini response "mismatches" entries must have a non-empty "field".');
            }

            $severity = $mismatch['severity'] ?? 'warning';

            if (! is_string($severity) || ! in_array($severity, self::SEVERITY_LEVELS, true)) {
                throw new GeminiMalformedResponseException('Gemini response mismatch "severity" must be one of: critical, warning.');
            }

            $mismatches[] = [
                'field' => $mismatch['field'],
                'expected' => isset($mismatch['expected']) && $mismatch['expected'] !== null ? (string) $mismatch['expected'] : null,
                'extracted' => isset($mismatch['extracted']) && $mismatch['extracted'] !== null ? (string) $mismatch['extracted'] : null,
                'severity' => $severity,
            ];
        }

        return [
            'is_valid' => $payload['is_valid'],
            'confidence' => $payload['confidence'],
            'comment' => trim($payload['comment']),
            'extracted_fields' => $extractedFields,
            'mismatches' => $mismatches,
        ];
    }
}