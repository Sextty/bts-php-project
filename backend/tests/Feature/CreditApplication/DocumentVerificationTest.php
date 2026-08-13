<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DocumentVerificationTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function applicationReadyForValidation(): CreditApplication
    {
        $application = $this->newApplication();
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);

        return $application;
    }

    public function test_validation_1_still_passes_when_openrouter_is_not_configured(): void
    {
        // No OPENROUTER_API_KEY set (the test-env default) — DocumentVerificationService throws,
        // CreditApplicationValidationService catches it and leaves the document unverified. The
        // check is advisory only, so validation-1 itself must still succeed.
        $application = $this->applicationReadyForValidation();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
    }

    public function test_validation_1_still_passes_when_openrouter_returns_an_error(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake(['openrouter.ai/*' => Http::response('Service unavailable', 503)]);

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
    }

    public function test_validation_1_records_the_ai_verdict_on_the_document(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'is_valid' => true,
                    'confidence' => 'high',
                    'comment' => 'Looks like a genuine, legible CIN.',
                ])]],
            ],
        ], 200)]);

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNotNull($document->ai_verified_at);
        $this->assertTrue($document->ai_is_valid);
        $this->assertSame('high', $document->ai_confidence);
        $this->assertSame('Looks like a genuine, legible CIN.', $document->ai_comment);
    }

    public function test_ai_verdict_is_exposed_to_staff(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'is_valid' => false,
                    'confidence' => 'medium',
                    'comment' => 'Image is too blurry to confirm authenticity.',
                ])]],
            ],
        ], 200)]);

        $application = $this->applicationReadyForValidation();
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");
        $this->postJson("/api/applications/{$application->id}/submit");

        $staff = \App\Models\StaffUser::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson("/api/staff/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.application.documents.0.ai_is_valid', false)
            ->assertJsonPath('data.application.documents.0.ai_comment', 'Image is too blurry to confirm authenticity.');
    }
}
