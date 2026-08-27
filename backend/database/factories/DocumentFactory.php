<?php

namespace Database\Factories;

use App\Models\CreditApplication;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $uuid = $this->faker->uuid();
        $types = array_keys(config('credit_documents.types', []));

        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_READY_FOR_VALIDATION_1,
            ]),
            'document_type' => $this->faker->randomElement($types ?: ['other']),
            'original_filename' => "document-synthetique-{$uuid}.txt",
            'disk_path' => "synthetic-test/documents/{$uuid}.txt",
            'mime_type' => 'text/plain',
            'size_bytes' => $this->faker->numberBetween(64, 4096),
            'ai_verified_at' => null,
            'ai_is_valid' => null,
            'ai_confidence' => null,
            'ai_comment' => null,
            'ai_extracted_fields' => null,
            'ai_mismatches' => null,
            'ai_processing_status' => null,
            'ai_detected_issues' => null,
            'ai_requires_human_review' => null,
        ];
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $application->getKey(),
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => $type,
        ]);
    }

    public function verified(bool $valid = true): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_verified_at' => now(),
            'ai_is_valid' => $valid,
            'ai_confidence' => $valid ? 'high' : 'low',
            'ai_comment' => $valid
                ? 'Document synthétique validé pour les tests.'
                : 'Document synthétique invalide pour les tests.',
            'ai_extracted_fields' => [],
            'ai_mismatches' => [],
            'ai_processing_status' => $valid ? 'verified' : 'needs_human_review',
            'ai_detected_issues' => $valid ? [] : [[
                'type' => 'synthetic_test_case',
                'severity' => 'warning',
                'message' => 'Cas synthétique contrôlé.',
            ]],
            'ai_requires_human_review' => ! $valid,
        ]);
    }

    public function verificationFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_verified_at' => null,
            'ai_is_valid' => null,
            'ai_confidence' => null,
            'ai_comment' => null,
            'ai_extracted_fields' => null,
            'ai_mismatches' => null,
            'ai_processing_status' => 'failed',
            'ai_detected_issues' => null,
            'ai_requires_human_review' => null,
        ]);
    }
}
