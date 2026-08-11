<?php

namespace Tests\Feature\CreditApplication;

class ClientStepTest extends CreditApplicationTestCase
{
    public function test_saving_a_complete_client_advances_status_to_step_1_completed(): void
    {
        $application = $this->newApplication();

        $response = $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());

        $response->assertOk()->assertJsonPath('data.application.status', 'STEP_1_COMPLETED');
        $this->assertSame('Ben Salah', $application->fresh()->client->nom);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $application = $this->newApplication();

        $payload = $this->validClientPayload();
        unset($payload['nom']);

        $this->putJson("/api/applications/{$application->id}/client", $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_saving_client_a_second_time_updates_in_place_rather_than_duplicating(): void
    {
        $application = $this->newApplication();

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/client", array_merge($this->validClientPayload(), ['nom' => 'Trabelsi']));

        $this->assertSame(1, $application->fresh()->client()->count());
        $this->assertSame('Trabelsi', $application->fresh()->client->nom);
    }

    public function test_another_users_application_cannot_be_edited(): void
    {
        $application = $this->newApplication();
        $application->update(['user_id' => \App\Models\User::factory()->create()->id]);

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())
            ->assertStatus(403);
    }
}
