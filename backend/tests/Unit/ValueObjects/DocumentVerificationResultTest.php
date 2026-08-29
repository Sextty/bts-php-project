<?php

namespace Tests\Unit\ValueObjects;

use App\ValueObjects\DocumentVerificationResult;
use PHPUnit\Framework\TestCase;

class DocumentVerificationResultTest extends TestCase
{
    private function makeResult(array $overrides = []): DocumentVerificationResult
    {
        return new DocumentVerificationResult(
            isValid: $overrides['is_valid'] ?? true,
            documentType: $overrides['document_type'] ?? 'cin',
            confidence: $overrides['confidence'] ?? 'high',
            comment: $overrides['comment'] ?? 'Genuine.',
            extractedFields: $overrides['extracted_fields'] ?? ['nom' => 'Ben Salah'],
            mismatches: $overrides['mismatches'] ?? [],
            detectedIssues: $overrides['detected_issues'] ?? [],
        );
    }

    public function test_high_confidence_without_mismatches_is_verified(): void
    {
        $result = $this->makeResult();

        $this->assertTrue($result->isValid);
        $this->assertFalse($result->requiresHumanReview);
        $this->assertSame('verified', $result->processingStatus);
        $this->assertSame(0.9, $result->confidenceScore);
    }

    public function test_medium_confidence_maps_to_point_six(): void
    {
        $this->assertSame(0.6, $this->makeResult(['confidence' => 'medium'])->confidenceScore);
    }

    public function test_low_confidence_requires_human_review_but_keeps_ai_verdict(): void
    {
        $result = $this->makeResult(['confidence' => 'low']);

        // Low confidence alone does not flip the verdict — the AI may still be right — but a
        // human must look before the document is acted on.
        $this->assertTrue($result->isValid);
        $this->assertTrue($result->requiresHumanReview);
        $this->assertSame('needs_human_review', $result->processingStatus);
        $this->assertSame(0.3, $result->confidenceScore);
    }

    public function test_critical_mismatch_overrides_an_is_valid_verdict(): void
    {
        $result = $this->makeResult([
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre', 'severity' => 'critical'],
            ],
        ]);

        // The AI answered true, but a critical field mismatch is a hard backend rule: the
        // document is NOT valid, and a human must review it.
        $this->assertFalse($result->isValid);
        $this->assertTrue($result->requiresHumanReview);
        $this->assertSame('needs_human_review', $result->processingStatus);
    }

    public function test_warning_mismatch_does_not_invalidate_the_document(): void
    {
        $result = $this->makeResult([
            'mismatches' => [
                ['field' => 'date_naissance', 'expected' => '1990-05-12', 'extracted' => '1991-02-03', 'severity' => 'warning'],
            ],
        ]);

        $this->assertTrue($result->isValid);
        $this->assertFalse($result->requiresHumanReview);
        $this->assertSame('verified', $result->processingStatus);
    }

    public function test_carries_document_type_and_issue_list(): void
    {
        $result = $this->makeResult([
            'document_type' => 'passeport',
            'detected_issues' => [['type' => 'low_confidence', 'severity' => 'warning', 'message' => 'Blurry.']],
        ]);

        $this->assertSame('passeport', $result->documentType);
        $this->assertSame([['type' => 'low_confidence', 'severity' => 'warning', 'message' => 'Blurry.']], $result->detectedIssues);
    }
}

