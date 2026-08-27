<?php

namespace Tests\Feature\CreditApplication;

class CreditRequestStepTest extends CreditApplicationTestCase
{
    public function test_saving_a_credit_request_generates_a_number_automatically(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $response = $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());

        $response->assertOk()->assertJsonPath('data.application.status', 'STEP_2_COMPLETED');

        $nDemande = $application->fresh()->creditRequest->n_demande;
        $this->assertMatchesRegularExpression('/^CR-\d{4}-\d{6}$/', $nDemande);
    }

    public function test_client_cannot_supply_their_own_n_demande(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = array_merge($this->validCreditRequestPayload(), ['n_demande' => 'CR-2000-000001']);
        $this->putJson("/api/applications/{$application->id}/credit", $payload);

        $this->assertNotSame('CR-2000-000001', $application->fresh()->creditRequest->n_demande);
    }

    public function test_editing_the_credit_request_again_keeps_the_same_number(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $first = $application->fresh()->creditRequest->n_demande;

        $this->putJson("/api/applications/{$application->id}/credit", array_merge($this->validCreditRequestPayload(), ['origine' => 'en ligne']));
        $second = $application->fresh()->creditRequest->n_demande;

        $this->assertSame($first, $second);
    }

    public function test_identifiant_personne_is_auto_populated_from_code_client(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $codeClient = $application->fresh()->client->code_client;

        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload())->assertOk();

        $this->assertSame($codeClient, $application->fresh()->creditRequest->identifiant_personne);
    }

    public function test_client_cannot_override_identifiant_personne(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $codeClient = $application->fresh()->client->code_client;

        $payload = array_merge($this->validCreditRequestPayload(), ['identifiant_personne' => 'OTHER_PERSON']);
        $this->putJson("/api/applications/{$application->id}/credit", $payload)->assertOk();

        $this->assertSame($codeClient, $application->fresh()->creditRequest->identifiant_personne);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = $this->validCreditRequestPayload();
        unset($payload['montant_global_sollicite']);

        $this->putJson("/api/applications/{$application->id}/credit", $payload)
            ->assertStatus(422);
    }

    public function test_valid_financing_breakdown_is_accepted_and_persisted(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = array_merge($this->validCreditRequestPayload(), [
            'montant_global_sollicite' => 50000,
            'montant_eqp' => 25000,
            'montant_fdr' => 15000,
            'montant_amg' => 10000,
            'montant_chp' => 0,
        ]);

        $response = $this->putJson("/api/applications/{$application->id}/credit", $payload);
        $response->assertOk();

        $cr = $application->fresh()->creditRequest;
        $this->assertEquals(25000, (float) $cr->montant_eqp);
        $this->assertEquals(15000, (float) $cr->montant_fdr);
        $this->assertEquals(10000, (float) $cr->montant_amg);
        $this->assertEquals(0, (float) $cr->montant_chp);
    }

    public function test_mismatched_financing_breakdown_sum_is_rejected(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = array_merge($this->validCreditRequestPayload(), [
            'montant_global_sollicite' => 50000,
            'montant_eqp' => 20000,
            'montant_fdr' => 10000,
            'montant_amg' => 10000,
            'montant_chp' => 0, // Sum = 40,000 != 50,000
        ]);

        $response = $this->putJson("/api/applications/{$application->id}/credit", $payload);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('montant_global_sollicite', $response->json('error.fields'));
    }
}
