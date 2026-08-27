<?php

namespace Tests\Feature\CreditApplication;

use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class DocumentVerificationTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['services.document_verification.provider' => 'gemini']);
        Branch::factory()->default()->create();
    }

    private function applicationReadyForValidation(): CreditApplication
    {
        $application = $this->newApplication();
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);

        return $application;
    }

    private function fakeGemini(array $payload): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiEnvelope($payload), 200)]);
    }

    private function geminiEnvelope(array $payload): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($payload)]]]],
            ],
        ];
    }

    private function openRouterEnvelope(array $payload): array
    {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => json_encode($payload)]],
            ],
        ];
    }

    private function validVerdict(array $overrides = []): array
    {
        return array_merge([
            'is_valid' => true,
            'confidence' => 'high',
            'comment' => 'Looks like a genuine, legible CIN.',
            'extracted_fields' => [
                'nom' => 'Ben Salah',
                'prenom' => 'Karim',
                'date_naissance' => '1990-05-12',
                'numero_pid' => '12345678',
                'date_delivrance_pid' => '2015-01-10',
            ],
            'mismatches' => [],
        ], $overrides);
    }

    public function test_validation_1_still_passes_when_gemini_is_not_configured(): void
    {
        // No GEMINI_API_KEY set (the test-env default) — DocumentVerificationService throws,
        // CreditApplicationValidationService catches it and leaves the document unverified. The
        // check is advisory only, so validation-1 itself must still succeed.
        $application = $this->applicationReadyForValidation();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
    }

    public function test_openrouter_minimax_provider_verifies_document_through_existing_pipeline(): void
    {
        config([
            'services.document_verification.provider' => 'openrouter',
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.model' => 'minimax/minimax-m3:free',
        ]);
        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterEnvelope($this->validVerdict()), 200),
        ]);
        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertTrue($document->ai_is_valid);
        $this->assertSame('high', $document->ai_confidence);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && ($request->data()['model'] ?? null) === 'minimax/minimax-m3:free');
    }

    public function test_openrouter_rate_limit_cannot_exhaust_the_php_request_deadline(): void
    {
        config([
            'services.document_verification.provider' => 'openrouter',
            'services.document_verification.validation_budget_seconds' => 1,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.model' => 'minimax/minimax-m3:free',
            'services.openrouter.max_retries' => 1,
        ]);
        Http::fake([
            'openrouter.ai/*' => Http::response('rate limited', 429, ['Retry-After' => '10']),
        ]);
        $application = $this->applicationReadyForValidation();
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'devis',
            'file' => $this->fakePdf('devis.pdf', 500),
        ]);
        $startedAt = microtime(true);

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $this->assertLessThan(2.0, microtime(true) - $startedAt);
        $this->assertSame(2, Document::where('credit_application_id', $application->id)
            ->where('ai_processing_status', 'failed')
            ->count());
    }

    public function test_local_only_mode_never_sends_customer_documents_to_a_cloud_provider(): void
    {
        config(['services.document_verification.provider' => 'local']);
        Http::fake();
        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        Http::assertNothingSent();
        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
        $this->assertSame('failed', $document->ai_processing_status);
    }

    public function test_validation_1_still_passes_when_gemini_returns_an_error(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('Service unavailable', 503)]);

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
    }

    public function test_validation_1_still_passes_when_gemini_returns_unparseable_json(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'This is definitely not JSON.']]]],
            ],
        ], 200)]);

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
    }

    public function test_validation_1_fails_when_ai_verdict_is_invalid(): void
    {
        $this->fakeGemini($this->validVerdict([
            'is_valid' => false,
            'comment' => 'Le fichier est une capture d’écran et non une pièce d’identité exploitable.',
            'mismatches' => [],
        ]));

        $application = $this->applicationReadyForValidation();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_1_FAILED');
        $this->assertSame(CreditApplication::STATUS_READY_FOR_VALIDATION_1, $application->fresh()->status);
        $this->assertStringNotContainsString('vérification IA échouée', $response->json('error.errors.0'));
        $this->assertStringContainsString('capture d’écran', $response->json('error.errors.0'));
        $this->assertStringContainsString('Remplacez ce fichier', $response->json('error.errors.0'));

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertFalse($document->ai_is_valid);
    }

    public function test_validation_1_fails_when_ai_finds_critical_mismatches(): void
    {
        $this->fakeGemini($this->validVerdict([
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre Nom', 'severity' => 'critical'],
            ],
        ]));

        $application = $this->applicationReadyForValidation();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_1_FAILED');
        $this->assertStringContainsString('Nom différent', $response->json('error.errors.0'));
        $this->assertStringContainsString('« Ben Salah »', $response->json('error.errors.0'));
        $this->assertStringContainsString('« Autre Nom »', $response->json('error.errors.0'));

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertFalse($document->ai_is_valid);
        $this->assertSame([
            ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre Nom', 'severity' => 'critical'],
        ], $document->ai_mismatches);
    }

    public function test_wrong_document_explains_the_real_problem_once_in_french(): void
    {
        $this->fakeGemini($this->validVerdict([
            'is_valid' => false,
            'comment' => 'Le fichier est une illustration et non une carte d’identité.',
            'extracted_fields' => [
                'nom' => null,
                'prenom' => null,
                'date_naissance' => null,
                'numero_pid' => null,
                'date_delivrance_pid' => null,
            ],
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => null, 'severity' => 'critical'],
                ['field' => 'prenom', 'expected' => 'Karim', 'extracted' => null, 'severity' => 'critical'],
                ['field' => 'numero_pid', 'expected' => '12345678', 'extracted' => null, 'severity' => 'critical'],
            ],
        ]));

        $application = $this->applicationReadyForValidation();
        $response = $this->postJson("/api/applications/{$application->id}/validation-1");
        $message = $response->json('error.errors.0');

        $response->assertStatus(422)
            ->assertJsonPath('error.message', 'Certains éléments du dossier doivent être corrigés.');
        $this->assertStringContainsString('illustration et non une carte d’identité', $message);
        $this->assertStringContainsString('Carte d’Identité Nationale (CIN)', str_replace("'", '’', $message));
        $this->assertStringNotContainsString('détecté « — »', $message);
        $this->assertStringNotContainsString('Nom ne correspond pas', $message);
    }

    public function test_unchanged_rejected_document_stays_blocked_on_retry(): void
    {
        $this->fakeGemini($this->validVerdict([
            'is_valid' => false,
            'comment' => 'Le fichier est un fond d’écran et non une carte d’identité.',
            'mismatches' => [],
        ]));

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_1_FAILED');

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_1_FAILED')
            ->assertJsonPath('error.errors.0', fn (string $message): bool => str_contains($message, 'fond d’écran'));

        $this->assertSame(CreditApplication::STATUS_READY_FOR_VALIDATION_1, $application->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_local_mode_does_not_enforce_a_historical_cloud_ai_rejection(): void
    {
        $this->fakeGemini($this->validVerdict([
            'is_valid' => false,
            'comment' => 'Le fichier est synthétique.',
            'mismatches' => [],
        ]));
        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertStatus(422);

        config(['services.document_verification.provider' => 'local']);

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        Http::assertSentCount(1);
        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertFalse($document->ai_is_valid);
        $this->assertNotNull($document->ai_verified_at);
    }

    public function test_validation_1_passes_when_ai_verdict_is_valid_with_matching_fields(): void
    {
        $this->fakeGemini($this->validVerdict());

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertTrue($document->ai_is_valid);
        $this->assertSame([], $document->ai_mismatches);
    }

    public function test_validation_1_records_the_ai_verdict_on_the_document(): void
    {
        $this->fakeGemini($this->validVerdict());

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNotNull($document->ai_verified_at);
        $this->assertTrue($document->ai_is_valid);
        $this->assertSame('high', $document->ai_confidence);
        $this->assertSame('Looks like a genuine, legible CIN.', $document->ai_comment);
    }

    public function test_validation_1_records_extracted_fields_and_mismatches_on_document(): void
    {
        $this->fakeGemini($this->validVerdict([
            'extracted_fields' => [
                'nom' => 'Ben Salah',
                'prenom' => 'Karim',
                'date_naissance' => '1991-02-03',
                'numero_pid' => '12345678',
                'date_delivrance_pid' => '2015-01-10',
            ],
            'mismatches' => [
                ['field' => 'date_naissance', 'expected' => '1990-05-12', 'extracted' => '1991-02-03', 'severity' => 'warning'],
            ],
        ]));

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertSame('Ben Salah', $document->ai_extracted_fields['nom']);
        $this->assertSame('1991-02-03', $document->ai_extracted_fields['date_naissance']);
        $this->assertSame([
            ['field' => 'date_naissance', 'expected' => '1990-05-12', 'extracted' => '1991-02-03', 'severity' => 'warning'],
        ], $document->ai_mismatches);
    }

    public function test_high_confidence_verdict_is_marked_verified_without_human_review(): void
    {
        $this->fakeGemini($this->validVerdict());

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertSame('verified', $document->ai_processing_status);
        $this->assertFalse($document->ai_requires_human_review);
        $this->assertSame([], $document->ai_detected_issues);
    }

    public function test_low_confidence_verdict_is_flagged_for_human_review(): void
    {
        $this->fakeGemini($this->validVerdict([
            'confidence' => 'low',
            'comment' => 'Document is heavily damaged, fields are hard to read.',
        ]));

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertSame('needs_human_review', $document->ai_processing_status);
        $this->assertTrue($document->ai_requires_human_review);
        $this->assertTrue($document->ai_is_valid);

        $types = array_column($document->ai_detected_issues ?? [], 'type');
        $this->assertContains('low_confidence', $types);
    }

    public function test_critical_mismatch_sets_needs_human_review_and_builds_detected_issues(): void
    {
        $this->fakeGemini($this->validVerdict([
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre Nom', 'severity' => 'critical'],
            ],
        ]));

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_1_FAILED');

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertSame('needs_human_review', $document->ai_processing_status);
        $this->assertTrue($document->ai_requires_human_review);
        $this->assertFalse($document->ai_is_valid);

        $issues = $document->ai_detected_issues ?? [];
        $this->assertContains('critical_mismatch', array_column($issues, 'type'));
        $this->assertStringContainsString('nom', $issues[0]['message']);
    }

    public function test_gemini_failure_is_recorded_as_failed_status_on_the_document(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('Service unavailable', 503)]);

        $application = $this->applicationReadyForValidation();

        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();

        $document = Document::where('credit_application_id', $application->id)->firstOrFail();
        $this->assertNull($document->ai_verified_at);
        $this->assertSame('failed', $document->ai_processing_status);
    }

    public function test_detected_issues_and_human_review_flag_are_exposed_to_staff(): void
    {
        $this->fakeGemini($this->validVerdict(['confidence' => 'low']));

        $application = $this->submittedApplication();

        $staff = StaffUser::factory()->forBranch($application->fresh()->branch)->create();
        Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson("/api/staff/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.application.documents.0.ai_processing_status', 'needs_human_review')
            ->assertJsonPath('data.application.documents.0.ai_requires_human_review', true);
    }

    public function test_validation_1_fails_when_any_document_fails_ai_verification(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->geminiEnvelope($this->validVerdict()))
            ->push($this->geminiEnvelope($this->validVerdict([
                'is_valid' => false,
                'confidence' => 'medium',
                'comment' => 'Le document présente des signes visibles de modification.',
            ])))]);

        $application = $this->applicationReadyForValidation();
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin2.pdf', 500),
        ]);

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_1_FAILED');
        $this->assertStringContainsString('signes visibles de modification', $response->json('error.errors.0'));

        $documents = Document::where('credit_application_id', $application->id)->get();
        $this->assertSame(2, $documents->count());
        $this->assertTrue($documents[0]->ai_is_valid);
        $this->assertFalse($documents[1]->ai_is_valid);
    }

    private function submittedApplication(): CreditApplication
    {
        $application = $this->applicationReadyForValidation();
        $this->postJson("/api/applications/{$application->id}/validation-1")->assertOk();
        $this->postJson("/api/applications/{$application->id}/validation-2")->assertOk();

        return $application;
    }

    public function test_ai_verdict_is_exposed_to_staff(): void
    {
        $this->fakeGemini($this->validVerdict([
            'is_valid' => true,
            'confidence' => 'medium',
            'comment' => 'Image is genuine but slightly blurry.',
        ]));

        $application = $this->submittedApplication();

        $staff = StaffUser::factory()->forBranch($application->fresh()->branch)->create();
        Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson("/api/staff/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.application.documents.0.ai_is_valid', true)
            ->assertJsonPath('data.application.documents.0.ai_comment', 'Image is genuine but slightly blurry.');
    }

    public function test_ai_extracted_fields_and_mismatches_are_exposed_to_staff(): void
    {
        $this->fakeGemini($this->validVerdict([
            'extracted_fields' => [
                'nom' => 'Ben Salah',
                'prenom' => 'Karim',
                'date_naissance' => '1990-05-12',
            ],
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Ben Salah', 'severity' => 'warning'],
            ],
        ]));

        $application = $this->submittedApplication();

        $staff = StaffUser::factory()->forBranch($application->fresh()->branch)->create();
        Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson("/api/staff/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.application.documents.0.ai_extracted_fields.nom', 'Ben Salah')
            ->assertJsonPath('data.application.documents.0.ai_mismatches.0.field', 'nom')
            ->assertJsonPath('data.application.documents.0.ai_mismatches.0.severity', 'warning');
    }
}
