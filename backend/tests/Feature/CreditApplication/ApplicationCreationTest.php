<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;
use App\Models\User;

class ApplicationCreationTest extends CreditApplicationTestCase
{
    public function test_creating_an_application_starts_in_draft(): void
    {
        $response = $this->postJson('/api/applications');

        $response->assertStatus(201)
            ->assertJsonPath('data.application.status', 'DRAFT')
            ->assertJsonPath('data.application.is_locked', false)
            ->assertJsonPath('data.application.can_be_deleted', true);
    }

    public function test_index_only_lists_the_authenticated_users_own_applications(): void
    {
        $this->newApplication();

        $otherUser = User::factory()->create();
        CreditApplication::factory()->count(2)->create(['user_id' => $otherUser->id]);

        $response = $this->getJson('/api/applications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.applications'));
    }

    public function test_show_rejects_another_users_application_with_403(): void
    {
        $otherUser = User::factory()->create();
        $application = CreditApplication::factory()->create(['user_id' => $otherUser->id]);

        $this->getJson("/api/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_show_returns_404_for_a_nonexistent_application(): void
    {
        $this->getJson('/api/applications/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_customer_can_delete_an_own_unfinished_application(): void
    {
        $application = $this->newApplication();

        $this->deleteJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('credit_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'action' => 'credit_application.deleted_by_customer',
        ]);
    }

    public function test_customer_can_delete_a_completed_draft_before_validation(): void
    {
        $application = $this->newApplication();
        $this->completeStep2($application);
        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload())->assertOk();

        $this->deleteJson("/api/applications/{$application->id}")->assertOk();

        $this->assertSoftDeleted('credit_applications', ['id' => $application->id]);
    }

    public function test_customer_cannot_delete_an_application_after_validation(): void
    {
        $application = $this->newApplication();
        $application->forceFill(['status' => CreditApplication::STATUS_VALIDATION_1_COMPLETED])->save();

        $this->deleteJson("/api/applications/{$application->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_CANNOT_BE_DELETED');

        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.application.can_be_deleted', false);

        $this->assertNotSoftDeleted('credit_applications', ['id' => $application->id]);
    }

    public function test_customer_cannot_delete_another_users_application(): void
    {
        $otherUser = User::factory()->create();
        $application = CreditApplication::factory()->create(['user_id' => $otherUser->id]);

        $this->deleteJson("/api/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertNotSoftDeleted('credit_applications', ['id' => $application->id]);
    }

    public function test_index_includes_the_n_demande_once_the_credit_step_is_saved(): void
    {
        $application = $this->newApplication();
        $this->completeStep1($application);
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload())->assertOk();

        $response = $this->getJson('/api/applications');

        $response->assertOk();
        $this->assertNotNull($response->json('data.applications.0.credit_request.n_demande'));
    }

    public function test_credit_step_cannot_skip_the_client_step(): void
    {
        $application = $this->newApplication();

        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STEPS_INCOMPLETE');
    }
}
