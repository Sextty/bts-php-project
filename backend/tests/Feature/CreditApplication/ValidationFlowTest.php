<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;
use Illuminate\Support\Facades\Storage;

class ValidationFlowTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function completeAllThreeSteps(CreditApplication $application): void
    {
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());
    }

    public function test_validation_1_fails_when_a_required_document_is_missing(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_1_FAILED')
            ->assertJsonPath('error.errors.0', 'Document manquant : ajoutez au moins un justificatif avant de lancer la vérification.');
        $this->assertSame(CreditApplication::STATUS_READY_FOR_VALIDATION_1, $application->fresh()->status);
    }

    public function test_forced_ai_validation_cannot_bypass_a_missing_required_document(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);

        $this->postJson("/api/applications/{$application->id}/validation-1", [
            'force_ai_validation' => true,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_1_FAILED')
            ->assertJsonPath('error.errors.0', 'Document manquant : ajoutez au moins un justificatif avant de lancer la vérification.');

        $this->assertSame(CreditApplication::STATUS_READY_FOR_VALIDATION_1, $application->fresh()->status);
    }

    public function test_validation_1_allows_no_document_only_when_explicitly_configured(): void
    {
        config()->set('credit_documents.require_at_least_one_for_validation', false);

        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);

        $this->assertSame(CreditApplication::STATUS_VALIDATION_1_COMPLETED, $application->fresh()->status);
        $this->assertSame(0, $application->documents()->count());
    }

    public function test_validation_1_passes_once_the_required_cin_document_is_uploaded(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);
        // Regression: ->fresh() drops previously eager-loaded relations, which silently emptied
        // client/credit_request/project out of this response and broke the validation review
        // page's "ready for validation" check — caught live in the browser, not by this suite
        // until now.
        $response->assertJsonPath('data.application.client.nom', 'Ben Salah');
        $response->assertJsonStructure(['data' => ['application' => ['credit_request', 'project']]]);
    }

    public function test_validation_1_cannot_run_before_all_three_steps_are_complete(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/validation-1")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STEPS_INCOMPLETE');
    }

    public function test_validation_2_auto_submits_the_application(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");

        $response = $this->postJson("/api/applications/{$application->id}/validation-2");

        $response->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_SUBMITTED)
            ->assertJsonPath('data.application.is_locked', true)
            ->assertJsonStructure(['data' => ['application' => ['client', 'credit_request', 'project', 'branch']]]);
        $this->assertNotNull($application->fresh()->submitted_at);
    }

    public function test_validation_2_is_rejected_before_validation_1_passes(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);

        $this->postJson("/api/applications/{$application->id}/validation-2")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VALIDATION_1_REQUIRED');
    }

    public function test_no_section_can_be_edited_after_final_lock(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'APPLICATION_LOCKED');

        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload())
            ->assertStatus(403);

        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload())
            ->assertStatus(403);

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin2.pdf', 500),
        ])->assertStatus(403);
    }

    public function test_submit_requires_final_lock_first(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_NOT_LOCKED');
    }

    public function test_submit_after_final_lock_marks_the_application_submitted(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        // After validation-2, the application is already auto-submitted (SUBMITTED).
        // Calling submit again should be rejected because it requires FINAL_LOCKED.
        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_NOT_LOCKED');

        $this->assertSame(CreditApplication::STATUS_SUBMITTED, $application->fresh()->status);
        $this->assertNotNull($application->fresh()->submitted_at);
    }

    public function test_validation_1_passes_with_fdr_financing_document(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'fdr',
            'file' => $this->fakePdf('facture_fdr.pdf', 500),
        ])->assertCreated();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");
        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);
    }

    public function test_validation_1_passes_with_amg_financing_document(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'amg',
            'file' => $this->fakePdf('devis_travaux_amg.pdf', 500),
        ])->assertCreated();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");
        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);
    }

    public function test_validation_1_passes_with_chp_financing_document(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'chp',
            'file' => $this->fakePdf('proforma_cheptel_chp.pdf', 500),
        ])->assertCreated();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");
        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);
    }

    public function test_validation_1_passes_with_epr_financing_document(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'epr',
            'file' => $this->fakePdf('devis_materiel_epr.pdf', 500),
        ])->assertCreated();

        $response = $this->postJson("/api/applications/{$application->id}/validation-1");
        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_VALIDATION_1_COMPLETED);
    }
}
