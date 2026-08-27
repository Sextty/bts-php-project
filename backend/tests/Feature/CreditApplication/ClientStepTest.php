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

    public function test_cin_must_be_exactly_8_digits(): void
    {
        $application = $this->newApplication();

        // 7 digits -> rejected
        $payload7 = array_merge($this->validClientPayload(), [
            'type_pid' => 'CIN',
            'numero_pid' => '1234567',
        ]);
        $response7 = $this->putJson("/api/applications/{$application->id}/client", $payload7);
        $response7->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('numero_pid', $response7->json('error.fields'));

        // Non-digit characters -> rejected
        $payloadAlpha = array_merge($this->validClientPayload(), [
            'type_pid' => 'CIN',
            'numero_pid' => '1234567A',
        ]);
        $responseAlpha = $this->putJson("/api/applications/{$application->id}/client", $payloadAlpha);
        $responseAlpha->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('numero_pid', $responseAlpha->json('error.fields'));

        // 8 digits -> accepted
        $payload8 = array_merge($this->validClientPayload(), [
            'type_pid' => 'CIN',
            'numero_pid' => '08123456',
        ]);
        $response8 = $this->putJson("/api/applications/{$application->id}/client", $payload8);
        $response8->assertOk();
        $this->assertSame('08123456', $application->fresh()->client->numero_pid);
    }
}
