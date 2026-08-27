<?php

namespace App\ValueObjects;

/**
 * Immutable structured verdict produced by DocumentVerificationService::verify for one document.
 *
 * Carries the parsed AI result plus the domain interpretation of it, and enforces the
 * backend invariants at construction — the model's answer can never outrank the business rules:
 *   - a critical field mismatch invalidates the document even when the model answered
 *     `is_valid: true` (defensive backstop — a hard mismatch in name/number/birth date);
 *   - low model confidence or a critical mismatch flags the document for human review, which
 *     staff see on the review surface instead of treating the AI verdict as final.
 *
 * The AI output is advisory by design: this result feeds backend validation, it never decides
 * anything irreversible by itself.
 */
final class DocumentVerificationResult
{
    /** AI verdict overridden by the critical-mismatch invariant. */
    public readonly bool $isValid;

    /** Low confidence or a critical mismatch → a human must look at the document. */
    public readonly bool $requiresHumanReview;

    /** 'verified' when no human review is needed, 'needs_human_review' otherwise. */
    public readonly string $processingStatus;

    /** Numeric reading of the confidence level: high=0.9, medium=0.6, low=0.3. */
    public readonly float $confidenceScore;

    /**
     * @param  array<string, string|null>  $extractedFields
     * @param  list<array{field: string, expected: string|null, extracted: string|null, severity: 'critical'|'warning'}>  $mismatches
     * @param  list<array{type: string, severity: 'critical'|'warning'|'info', message: string}>  $detectedIssues
     */
    public function __construct(
        bool $isValid,
        public readonly string $documentType,
        public readonly string $confidence,
        public readonly string $comment,
        public readonly array $extractedFields,
        public readonly array $mismatches,
        public readonly array $detectedIssues = [],
    ) {
        $this->isValid = $isValid && ! $this->hasCriticalMismatch();
        $this->confidenceScore = match ($confidence) {
            'high' => 0.9,
            'medium' => 0.6,
            'low' => 0.3,
            default => 0.0,
        };
        $this->requiresHumanReview = $confidence === 'low' || $this->hasCriticalMismatch();
        $this->processingStatus = $this->requiresHumanReview ? 'needs_human_review' : 'verified';
    }

    public function hasCriticalMismatch(): bool
    {
        foreach ($this->mismatches as $mismatch) {
            if ($mismatch['severity'] === 'critical') {
                return true;
            }
        }

        return false;
    }
}
