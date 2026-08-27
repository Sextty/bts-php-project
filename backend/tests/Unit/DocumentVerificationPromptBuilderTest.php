<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Document;
use App\Services\DocumentVerificationPromptBuilder;
use Tests\TestCase;

class DocumentVerificationPromptBuilderTest extends TestCase
{
    private function document(string $type = 'cin'): Document
    {
        return new Document([
            'document_type' => $type,
            'original_filename' => 'cin.jpg',
        ]);
    }

    private function client(): Client
    {
        return new Client([
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'date_naissance' => '1990-05-12',
            'numero_pid' => '12345678',
            'date_delivrance_pid' => '2015-01-10',
        ]);
    }

    public function test_build_with_client_includes_identity_fields_in_prompt(): void
    {
        $prompt = (new DocumentVerificationPromptBuilder)->build($this->document(), $this->client());

        $this->assertStringContainsString('Ben Salah', $prompt);
        $this->assertStringContainsString('Karim', $prompt);
        $this->assertStringContainsString('1990-05-12', $prompt);
        $this->assertStringContainsString('12345678', $prompt);
    }

    public function test_build_with_client_requests_field_extraction_and_comparison(): void
    {
        $prompt = (new DocumentVerificationPromptBuilder)->build($this->document(), $this->client());

        $this->assertStringContainsString('EXTRACTION', $prompt);
        $this->assertStringContainsString('COMPARAISON', $prompt);
        $this->assertStringContainsString('date_naissance', $prompt);
    }

    public function test_build_with_client_requests_full_json_response_schema(): void
    {
        $prompt = (new DocumentVerificationPromptBuilder)->build($this->document(), $this->client());

        foreach (['is_valid', 'confidence', 'comment', 'extracted_fields', 'mismatches'] as $key) {
            $this->assertStringContainsString($key, $prompt);
        }
    }

    public function test_build_skips_field_comparison_for_non_comparable_document_types(): void
    {
        $prompt = (new DocumentVerificationPromptBuilder)->build($this->document('fiche_paie'), $this->client());

        $this->assertStringNotContainsString('COMPARAISON', $prompt);
        $this->assertStringContainsString('mismatches = []', $prompt);
    }

    public function test_build_requires_a_short_french_only_comment(): void
    {
        $prompt = (new DocumentVerificationPromptBuilder)->build($this->document(), $this->client());

        $this->assertStringContainsString('Tous les textes produits doivent être en français', $prompt);
        $this->assertStringContainsString('160 caractères maximum', $prompt);
        $this->assertStringContainsString('UNIQUEMENT', $prompt);
        $this->assertStringContainsString('indiquer la cause précise puis l’action à effectuer', $prompt);
        $this->assertStringContainsString('renvoie mismatches = []', $prompt);
    }
}
