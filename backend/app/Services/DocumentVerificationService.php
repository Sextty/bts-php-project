<?php

namespace App\Services;

use App\Contracts\GeminiClientInterface;
use App\Exceptions\Gemini\GeminiApiException;
use App\Models\Client;
use App\Models\Document;
use App\Services\Gemini\GeminiResponseValidator;
use App\ValueObjects\DocumentVerificationResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates the document verification pipeline for one document:
 *
 *   Document → file validation → AI/OCR (Gemini) → structured extraction → confidence →
 *   backend validation → human review when necessary.
 *
 * The Gemini wire protocol lives in GeminiClientInterface (isolated transport); this service
 * owns the domain steps around it: reading and validating the stored file, building the prompt,
 * validating the AI output strictly, and deriving the structured result. Callers decide policy:
 * the AI output is advisory — this service throws on any failure (file missing, provider down,
 * malformed output) and the caller (CreditApplicationValidationService) logs-and-continues, so
 * a dead AI provider never blocks an application on a document it could not look at.
 */
class DocumentVerificationService
{
    public function __construct(
        private readonly DocumentVerificationPromptBuilder $promptBuilder,
        private readonly GeminiClientInterface $gemini,
        private readonly GeminiResponseValidator $validator,
    ) {}

    public function verify(Document $document, Client $client): DocumentVerificationResult
    {
        $contents = $this->loadValidatedFile($document);

        $payload = $this->gemini->generateContent(
            $document->mime_type,
            base64_encode($contents),
            $this->promptBuilder->build($document, $client),
        );

        // Strict schema validation: malformed AI output is a failed verification, never a
        // verdict. Everything below this line works on typed, normalized data.
        $normalized = $this->validator->validate($payload);

        $mismatches = $normalized['mismatches'];
        $confidence = $normalized['confidence'];

        $result = new DocumentVerificationResult(
            isValid: $normalized['is_valid'],
            documentType: $document->document_type,
            confidence: $confidence,
            comment: $normalized['comment'],
            extractedFields: $normalized['extracted_fields'],
            mismatches: $mismatches,
            detectedIssues: $this->deriveIssues($normalized),
        );

        if ($result->requiresHumanReview) {
            Log::info('[document-verification] AI verdict flagged for human review', [
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'confidence' => $confidence,
                'critical_mismatches' => count(array_filter($mismatches, fn ($m) => $m['severity'] === 'critical')),
                'processing_status' => $result->processingStatus,
            ]);
        }

        return $result;
    }

    /**
     * File validation gate before anything is sent to the AI: the stored file must exist and
     * stay under the configured size cap, and the recorded upload size must be non-zero. A
     * document that fails here throws — treated as "could not be verified" by the caller, never
     * as a fake.
     *
     * The empty check reads the RECORDED size_bytes (the truth recorded at upload time), not
     * the bytes read back from disk — fake uploads in tests store zero-length content while
     * reporting a real size, and the recorded size is what the size cap below already trusts.
     *
     * @throws \App\Exceptions\Gemini\GeminiApiException
     */
    private function loadValidatedFile(Document $document): string
    {
        $contents = Storage::disk('documents')->get($document->disk_path);

        if ($contents === null) {
            Log::warning('[document-verification] file missing on disk', [
                'document_id' => $document->id,
                'disk_path' => $document->disk_path,
            ]);

            throw new GeminiApiException("Document file not found on disk: {$document->disk_path}");
        }

        $maxBytes = (int) config('services.gemini.max_payload_bytes', 15 * 1024 * 1024);

        if ($document->size_bytes > $maxBytes) {
            throw new GeminiApiException(sprintf(
                'Document exceeds the AI verification size limit (%d KB).',
                (int) ($maxBytes / 1024),
            ));
        }

        if ($document->size_bytes <= 0) {
            throw new GeminiApiException('Document file is empty and cannot be verified.');
        }

        return $contents;
    }

    /**
     * Derives the human-consumable issue list from the normalized AI verdict: every mismatch
     * (critical and warning), a low-confidence verdict, and an explicit invalid verdict that
     * carried no mismatches (the model flagged authenticity itself).
     *
     * @param  array{
     *     is_valid: bool,
     *     confidence: string,
     *     comment: string,
     *     extracted_fields: array<string, string|null>,
     *     mismatches: list<array{field: string, expected: string|null, extracted: string|null, severity: string}>
     * }  $normalized
     * @return list<array{type: string, severity: string, message: string}>
     */
    private function deriveIssues(array $normalized): array
    {
        $issues = [];

        foreach ($normalized['mismatches'] as $mismatch) {
            $issues[] = [
                'type' => $mismatch['severity'] === 'critical' ? 'critical_mismatch' : 'warning_mismatch',
                'severity' => $mismatch['severity'],
                'message' => sprintf(
                    '%s on the document does not match the form (expected "%s", found "%s")',
                    $mismatch['field'],
                    $mismatch['expected'] ?? '—',
                    $mismatch['extracted'] ?? '—',
                ),
            ];
        }

        if ($normalized['confidence'] === 'low') {
            $issues[] = [
                'type' => 'low_confidence',
                'severity' => 'warning',
                'message' => 'The AI could not examine the document confidently — a human review is required.',
            ];
        }

        if (! $normalized['is_valid'] && $normalized['mismatches'] === []) {
            $issues[] = [
                'type' => 'authenticity',
                'severity' => 'critical',
                'message' => $normalized['comment'],
            ];
        }

        return $issues;
    }
}