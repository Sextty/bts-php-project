<?php

namespace Tests\Feature\CreditApplication;

class CreditRequestStepTest extends CreditApplicationTestCase
{
    public function test_saving_a_credit_request_generates_a_number_automatically(): void
    {
        $application = $this->newApplication();

        $response = $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());

        $response->assertOk()->assertJsonPath('data.application.status', 'STEP_2_COMPLETED');

        $nDemande = $application->fresh()->creditRequest->n_demande;
        $this->assertMatchesRegularExpression('/^CR-\d{4}-\d{6}$/', $nDemande);
    }

    public function test_client_cannot_supply_their_own_n_demande(): void
    {
        $application = $this->newApplication();

        $payload = array_merge($this->validCreditRequestPayload(), ['n_demande' => 'CR-2000-000001']);
        $this->putJson("/api/applications/{$application->id}/credit", $payload);

        $this->assertNotSame('CR-2000-000001', $application->fresh()->creditRequest->n_demande);
    }

    public function test_editing_the_credit_request_again_keeps_the_same_number(): void
    {
        $application = $this->newApplication();

        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $first = $application->fresh()->creditRequest->n_demande;

        $this->putJson("/api/applications/{$application->id}/credit", array_merge($this->validCreditRequestPayload(), ['origine' => 'en ligne']));
        $second = $application->fresh()->creditRequest->n_demande;

        $this->assertSame($first, $second);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $application = $this->newApplication();

        $payload = $this->validCreditRequestPayload();
        unset($payload['montant_global_sollicite']);

        $this->putJson("/api/applications/{$application->id}/credit", $payload)
            ->assertStatus(422);
    }
}
