<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;

class ProjectStepTest extends CreditApplicationTestCase
{
    public function test_saving_a_complete_project_advances_status_to_ready_for_validation_1(): void
    {
        $application = $this->newApplication();

        $response = $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_READY_FOR_VALIDATION_1);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $application = $this->newApplication();

        $payload = $this->validProjectPayload();
        unset($payload['cout']);

        $this->putJson("/api/applications/{$application->id}/project", $payload)
            ->assertStatus(422);
    }
}
