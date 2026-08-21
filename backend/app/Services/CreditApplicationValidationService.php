<?php

namespace App\Services;

use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\User;
use App\Models\ValidationStep;
use App\ValueObjects\DocumentVerificationResult;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Validation-1 checks (Part 1 spec, Étape 4): required sections present, at least one
 * supporting document uploaded, and — when Gemini is configured — every unverified document
 * passes the AI authenticity/field-matching check. FormRequests already enforce field-level
 * "required" at the moment each step is saved (Client/CreditRequest/Project can't be persisted
 * incomplete), so this service's job is confirming all three sections and a document actually
 * exist before the customer is allowed to lock the application — not re-validating field-by-field.
 */
class CreditApplicationValidationService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly DocumentVerificationService $documentVerification,
        private readonly CreditApplicationStateMachine $stateMachine,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array<string> Empty array means validation passed. */
    public function checkRequiredSections(CreditApplication $application): array
    {
        $errors = [];

        if (! $application->client) {
            $errors[] = 'Client information (Étape 1) is not complete.';
        }

        if (! $application->creditRequest) {
            $errors[] = 'Credit request information (Étape 2) is not complete.';
        }

        if (! $application->project) {
            $errors[] = 'Project information (Étape 3) is not complete.';
        }

        // One upload zone, any type: the customer attaches whatever documents they need, so the
        // only requirement is that at least one document exists before locking.
        if ($application->documents()->count() === 0) {
            $errors[] = 'At least one supporting document is required.';
        }

        return $errors;
    }

    /**
     * Runs validation-1, records the attempt (pass or fail) in validation_steps, and — only on
     * success — advances the application's status.
     */
    public function runValidationOne(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        $errors = $this->checkRequiredSections($application);

        if (! $errors) {
            $errors = $this->verifyDocuments($application);
        }

        ValidationStep::create([
            'credit_application_id' => $application->id,
            'step' => ValidationStep::STEP_VALIDATION_1,
            'status' => $errors ? ValidationStep::STATUS_FAILED : ValidationStep::STATUS_PASSED,
            'errors' => $errors ?: null,
        ]);

        if ($errors) {
            $this->auditLog->log('credit_application.validation_1_failed', $application->user, newState: ['errors' => $errors], ipAddress: $ip, userAgent: $userAgent, application: $application);

            return $errors;
        }

        $this->stateMachine->apply($application, CreditApplication::STATUS_VALIDATION_1_COMPLETED, $actor, ip: $ip, userAgent: $userAgent);
        $this->auditLog->log('credit_application.validation_1_passed', $application->user, ipAddress: $ip, userAgent: $userAgent, application: $application);

        return [];
    }

    /**
     * AI authenticity + field-matching check, one HTTP call per unverified document.
     *
     * Blocking rules: an explicit `is_valid: false` verdict (fake/tampered document or critical
     * field mismatch) produces a human-readable error per document, which blocks validation-1.
     * A provider failure (Gemini down, no API key configured, malformed response) is logged and
     * leaves ai_verified_at null instead of blocking — staff simply see "not yet checked" for
     * that document instead of a verdict. Documents already checked (ai_verified_at set) are
     * skipped.
     *
     * @return array<string> Empty array means every checked document passed.
     */
    private function verifyDocuments(CreditApplication $application): array
    {
        $errors = [];
        $client = $application->client;

        foreach ($application->documents()->whereNull('ai_verified_at')->get() as $document) {
            try {
                $result = $this->documentVerification->verify($document, $client);

                $document->update([
                    'ai_verified_at' => now(),
                    'ai_is_valid' => $result->isValid,
                    'ai_confidence' => $result->confidence,
                    'ai_comment' => $result->comment,
                    'ai_extracted_fields' => $result->extractedFields,
                    'ai_mismatches' => $result->mismatches,
                    'ai_processing_status' => $result->processingStatus,
                    'ai_detected_issues' => $result->detectedIssues,
                    'ai_requires_human_review' => $result->requiresHumanReview,
                ]);

                if (! $result->isValid) {
                    $errors[] = $this->documentError($document, $result);

                    $this->notifications->notifyUser(
                        $application->user,
                        'document.rejected',
                        'Document flagged for review',
                        'Your document "'.$document->original_filename.'" could not be confirmed as genuine. Please check it and upload a replacement.',
                        ['application_id' => $application->id, 'document_id' => $document->id],
                        dedupeKey: 'document-'.$document->id,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[document-verification] check failed', [
                    'document_id' => $document->id,
                    'exception' => $e->getMessage(),
                ]);

                // The provider failed or the response was unusable: persist the fact so staff
                // can distinguish "checked and needs a human" from "never got a verdict" on the
                // review surface, while leaving ai_verified_at null to mark it unverified.
                $document->forceFill(['ai_processing_status' => 'failed'])->save();
            }
        }

        return $errors;
    }

    private function documentError(Document $document, DocumentVerificationResult $result): string
    {
        $labels = config('credit_documents.ai_field_labels', []);
        $details = [];

        foreach ($result->mismatches as $mismatch) {
            if ($mismatch['severity'] !== 'critical') {
                continue;
            }

            $label = $labels[$mismatch['field']] ?? $mismatch['field'];
            $details[] = sprintf(
                '%s on the document does not match the form (expected "%s", found "%s")',
                $label,
                $mismatch['expected'] ?? '—',
                $mismatch['extracted'] ?? '—',
            );
        }

        if (! $details) {
            $details[] = $result->comment ?: 'document could not be confirmed as genuine.';
        }

        return sprintf('Document "%s": AI verification failed — %s.', $document->original_filename, implode('; ', $details));
    }
}
