<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;

class ProjectStepTest extends CreditApplicationTestCase
{
    public function test_saving_a_complete_project_advances_status_to_ready_for_validation_1(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $response = $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_READY_FOR_VALIDATION_1);
    }

    public function test_saving_a_project_generates_code_projet_automatically(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload())->assertOk();

        $codeProjet = $application->fresh()->project->code_projet;
        $this->assertMatchesRegularExpression('/^PJ-\d{4}-\d{6}$/', $codeProjet);
    }

    public function test_client_cannot_supply_their_own_code_projet(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = array_merge($this->validProjectPayload(), ['code_projet' => 'FAKE-CODE']);
        $this->putJson("/api/applications/{$application->id}/project", $payload)->assertOk();

        $this->assertNotSame('FAKE-CODE', $application->fresh()->project->code_projet);
    }

    public function test_editing_the_project_keeps_the_same_code_projet(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());
        $first = $application->fresh()->project->code_projet;

        $this->putJson("/api/applications/{$application->id}/project", array_merge($this->validProjectPayload(), ['objet' => 'Nouvel objet']));
        $second = $application->fresh()->project->code_projet;

        $this->assertSame($first, $second);
    }

    public function test_project_code_is_unique_per_application(): void
    {
        $app1 = $this->newApplication();
        $this->completeStep1($app1);
        $this->putJson("/api/applications/{$app1->id}/project", $this->validProjectPayload())->assertOk();

        $app2 = $this->newApplication();
        $this->completeStep1($app2);
        $this->putJson("/api/applications/{$app2->id}/project", $this->validProjectPayload())->assertOk();

        $this->assertNotSame(
            $app1->fresh()->project->code_projet,
            $app2->fresh()->project->code_projet,
        );
    }

    public function test_identifiant_personne_is_auto_populated_from_code_client(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $codeClient = $application->fresh()->client->code_client;

        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload())->assertOk();

        $this->assertSame($codeClient, $application->fresh()->project->identifiant_personne);
    }

    public function test_client_cannot_override_identifiant_personne_in_project(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $codeClient = $application->fresh()->client->code_client;

        $payload = array_merge($this->validProjectPayload(), ['identifiant_personne' => 'OTHER_PERSON']);
        $this->putJson("/api/applications/{$application->id}/project", $payload)->assertOk();

        $this->assertSame($codeClient, $application->fresh()->project->identifiant_personne);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);

        $payload = $this->validProjectPayload();
        unset($payload['cout']);

        $this->putJson("/api/applications/{$application->id}/project", $payload)
            ->assertStatus(422);
    }
}
