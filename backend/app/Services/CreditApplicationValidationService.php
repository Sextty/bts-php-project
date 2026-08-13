<?php

namespace App\Services;

use App\Models\CreditApplication;
use App\Models\ValidationStep;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Validation-1 checks (Part 1 spec, Étape 4): required sections present, required
 * documents present. FormRequests already enforce field-level "required" at the moment each
 * step is saved (Client/CreditRequest/Project can't be persisted incomplete), so this service's
 * job is confirming all three sections and every required document actually exist before the
 * customer is allowed to lock the application — not re-validating field-by-field.
 */
class CreditApplicationValidationService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly DocumentVerificationService $documentVerification,
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

        foreach (config('credit_documents.types', []) as $key => $document) {
            if (empty($document['required'])) {
                continue;
            }

            $hasDocument = $application->documents()->where('document_type', $key)->exists();
            if (! $hasDocument) {
                $errors[] = "Required document missing: {$document['label']}.";
            }
        }

        return $errors;
    }

    /**
     * Runs validation-1, records the attempt (pass or fail) in validation_steps, and — only on
     * success — advances the application's status.
     */
    public function runValidationOne(CreditApplication $application, ?string $ip = null, ?string $userAgent = null): array
    {
        $errors = $this->checkRequiredSections($application);

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

        $this->verifyDocuments($application);

        $application->update(['status' => CreditApplication::STATUS_VALIDATION_1_COMPLETED]);
        $this->auditLog->log('credit_application.validation_1_passed', $application->user, ipAddress: $ip, userAgent: $userAgent, application: $application);

        return [];
    }

    /**
     * AI authenticity check, advisory only — per document, best-effort. A failure (provider
     * down, no API key configured, malformed response) is logged and leaves ai_verified_at null
     * rather than blocking validation-1; staff simply see "not yet checked" for that document
     * instead of a verdict. Documents already checked (ai_verified_at set) are skipped.
     */
    private function verifyDocuments(CreditApplication $application): void
    {
        foreach ($application->documents()->whereNull('ai_verified_at')->get() as $document) {
            try {
                $result = $this->documentVerification->verify($document);

                $document->update([
                    'ai_verified_at' => now(),
                    'ai_is_valid' => $result['is_valid'],
                    'ai_confidence' => $result['confidence'],
                    'ai_comment' => $result['comment'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('[document-verification] check failed', [
                    'document_id' => $document->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
