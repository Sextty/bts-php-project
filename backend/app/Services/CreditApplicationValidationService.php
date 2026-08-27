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
 * supporting document uploaded, and — when a cloud AI is configured — every unverified document
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
            $errors[] = 'Informations du demandeur incomplètes : terminez l’étape 1.';
        }

        if (! $application->creditRequest) {
            $errors[] = 'Informations du crédit incomplètes : terminez l’étape 2.';
        }

        if (! $application->project) {
            $errors[] = 'Informations du projet incomplètes : terminez l’étape 3.';
        }

        // Strict by default. A local synthetic/demo environment can explicitly disable this
        // when its attachment step is intentionally hidden; production remains protected.
        if (config('credit_documents.require_at_least_one_for_validation', true)
            && $application->documents()->count() === 0) {
            $errors[] = 'Document manquant : ajoutez au moins un justificatif avant de lancer la vérification.';
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
     * A provider failure (cloud AI down, no API key configured, malformed response) is logged and
     * leaves ai_verified_at null instead of blocking — staff simply see "not yet checked" for
     * that document instead of a verdict. Documents already checked (ai_verified_at set) are
     * skipped.
     *
     * @return array<string> Empty array means every checked document passed.
     */
    private function verifyDocuments(CreditApplication $application): array
    {
        if (config('services.document_verification.provider') === 'local') {
            $application->documents()
                ->whereNull('ai_verified_at')
                ->update(['ai_processing_status' => 'failed']);

            Log::info('[document-verification] cloud verification disabled; deferred to human review', [
                'application_id' => $application->id,
            ]);

            return [];
        }

        $errors = [];
        $client = $application->client;
        $documents = $application->documents()
            ->where(function ($query) {
                $query->whereNull('ai_verified_at')
                    ->orWhere('ai_is_valid', false);
            })
            ->get();
        $budgetSeconds = max(1, (int) config('services.document_verification.validation_budget_seconds', 20));
        $deadline = microtime(true) + $budgetSeconds;

        foreach ($documents as $document) {
            $remainingSeconds = (int) ceil($deadline - microtime(true));
            if ($remainingSeconds < 1) {
                $documents
                    ->whereNull('ai_verified_at')
                    ->each(fn (Document $pending) => $pending->forceFill(['ai_processing_status' => 'failed'])->save());

                Log::warning('[document-verification] validation budget exhausted', [
                    'application_id' => $application->id,
                    'budget_seconds' => $budgetSeconds,
                    'remaining_documents' => $documents->whereNull('ai_verified_at')->count(),
                ]);

                break;
            }

            try {
                $result = $this->documentVerification->verify($document, $client, $remainingSeconds);

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
                        'Document à vérifier',
                        'Le document « '.$document->original_filename.' » n’a pas pu être confirmé comme authentique. Vérifiez-le et joignez une nouvelle version.',
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

                // A temporary provider failure must never turn a previously rejected document
                // into a passing one on retry. Keep blocking it with the last known French cause
                // until the customer uploads a corrected file and a new verdict succeeds.
                if ($document->ai_is_valid === false) {
                    $errors[] = $this->documentError($document, new DocumentVerificationResult(
                        false,
                        $document->document_type,
                        $document->ai_confidence ?? 'low',
                        $document->ai_comment ?? '',
                        $document->ai_extracted_fields ?? [],
                        $document->ai_mismatches ?? [],
                        $document->ai_detected_issues ?? [],
                    ));
                }
            }
        }

        return $errors;
    }

    private function documentError(Document $document, DocumentVerificationResult $result): string
    {
        $labels = config('credit_documents.ai_field_labels', []);
        $details = [];
        $hasUnreadableCriticalField = false;

        foreach ($result->mismatches as $mismatch) {
            if ($mismatch['severity'] !== 'critical') {
                continue;
            }

            $extracted = trim((string) ($mismatch['extracted'] ?? ''));
            if ($extracted === '') {
                $hasUnreadableCriticalField = true;

                continue;
            }

            $label = $labels[$mismatch['field']] ?? $mismatch['field'];
            $details[] = sprintf(
                '%s différent : formulaire « %s », document « %s »',
                $label,
                trim((string) ($mismatch['expected'] ?? 'non renseigné')),
                $extracted,
            );
        }

        // A wrong or unreadable file often makes every identity field come back null. Listing
        // five empty "expected/detected" mismatches hides the useful AI explanation. In that
        // case, show the direct cause once and tell the customer exactly what to upload next.
        if (! $details) {
            $details[] = $result->comment ?: 'Ce fichier ne permet pas de vérifier le justificatif demandé';
        }

        $documentType = config(
            "credit_documents.types.{$document->document_type}.label",
            'justificatif demandé',
        );

        $details[] = $hasUnreadableCriticalField || count($details) === 1
            ? "Remplacez ce fichier par une copie nette et complète du document suivant : {$documentType}"
            : 'Corrigez les informations du formulaire ou remplacez ce document';

        return sprintf(
            'Document « %s » : %s.',
            $document->original_filename,
            rtrim(implode('; ', $details), ". \t\n\r\0\x0B"),
        );
    }
}
