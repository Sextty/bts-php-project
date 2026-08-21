<?php

namespace Tests\Feature\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CreditApplicationStateMachine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class StateMachineTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Branch::factory()->default()->create();
    }

    private function machine(): CreditApplicationStateMachine
    {
        return app(CreditApplicationStateMachine::class);
    }

    private function applicationAt(string $status): CreditApplication
    {
        return CreditApplication::factory()->create([
            'user_id' => $this->user->id,
            'status' => $status,
        ]);
    }

    private function completeAllThreeSteps(CreditApplication $application): void
    {
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());
        $this->putJson("/api/applications/{$application->id}/project", $this->validProjectPayload());
    }

    private function submittedApplication(): CreditApplication
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        return $application->fresh();
    }

    // ---- machine: legal transitions ---------------------------------------------------

    public function test_machine_accepts_the_full_legal_customer_chain(): void
    {
        $machine = $this->machine();

        $steps = [
            CreditApplication::STATUS_DRAFT => CreditApplication::STATUS_STEP_1_COMPLETED,
            CreditApplication::STATUS_STEP_1_COMPLETED => CreditApplication::STATUS_STEP_2_COMPLETED,
            CreditApplication::STATUS_STEP_2_COMPLETED => CreditApplication::STATUS_STEP_3_COMPLETED,
            CreditApplication::STATUS_STEP_3_COMPLETED => CreditApplication::STATUS_READY_FOR_VALIDATION_1,
            CreditApplication::STATUS_READY_FOR_VALIDATION_1 => CreditApplication::STATUS_VALIDATION_1_COMPLETED,
            CreditApplication::STATUS_VALIDATION_1_COMPLETED => CreditApplication::STATUS_VALIDATION_2,
            CreditApplication::STATUS_VALIDATION_2 => CreditApplication::STATUS_FINAL_LOCKED,
            CreditApplication::STATUS_FINAL_LOCKED => CreditApplication::STATUS_SUBMITTED,
        ];

        foreach ($steps as $from => $to) {
            $this->assertTrue(
                $machine->canTransition($this->applicationAt($from), $to, $this->user),
                "expected {$from} → {$to} to be legal for a customer",
            );
        }
    }

    public function test_machine_accepts_the_staff_admin_and_appointment_edges(): void
    {
        $machine = $this->machine();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_SUBMITTED), CreditApplication::STATUS_STAFF_APPROVED, $staff));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_SUBMITTED), CreditApplication::STATUS_STAFF_REJECTED, $staff));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED), CreditApplication::STATUS_APPROVED, $admin));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED), CreditApplication::STATUS_REJECTED, $admin));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_APPROVED), CreditApplication::STATUS_APPOINTMENT_PROPOSED, $admin));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_APPOINTMENT_PROPOSED), CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $this->user));
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_APPOINTMENT_PROPOSED), CreditApplication::STATUS_APPOINTMENT_LOCKED, $this->user));
        // Re-proposal loop — the one legal same-state edge.
        $this->assertTrue($machine->canTransition($this->applicationAt(CreditApplication::STATUS_APPOINTMENT_PROPOSED), CreditApplication::STATUS_APPOINTMENT_PROPOSED, $this->user));
    }

    public function test_cancellation_is_legal_until_validation_two_inclusive(): void
    {
        $machine = $this->machine();

        foreach ([
            CreditApplication::STATUS_DRAFT,
            CreditApplication::STATUS_STEP_1_COMPLETED,
            CreditApplication::STATUS_STEP_2_COMPLETED,
            CreditApplication::STATUS_STEP_3_COMPLETED,
            CreditApplication::STATUS_READY_FOR_VALIDATION_1,
            CreditApplication::STATUS_VALIDATION_1_COMPLETED,
            CreditApplication::STATUS_VALIDATION_2,
        ] as $from) {
            $this->assertTrue(
                $machine->canTransition($this->applicationAt($from), CreditApplication::STATUS_CANCELLED, $this->user),
                "expected cancellation from {$from} to be legal",
            );
        }

        $this->assertFalse($machine->canTransition($this->applicationAt(CreditApplication::STATUS_FINAL_LOCKED), CreditApplication::STATUS_CANCELLED, $this->user));
        $this->assertFalse($machine->canTransition($this->applicationAt(CreditApplication::STATUS_SUBMITTED), CreditApplication::STATUS_CANCELLED, $this->user));
    }

    // ---- machine: illegal transitions -------------------------------------------------

    public function test_machine_allows_forward_jumps_within_the_customer_zone(): void
    {
        $machine = $this->machine();

        // The legacy services moved the status forward monotonically, so a credit save from a
        // fresh DRAFT (STEP_2) or a project save from DRAFT (STEP_3 then READY) is legal — the
        // frontend was built against that and ClientStepTest/ProjectStepTest pin it end to end.
        foreach ([
            CreditApplication::STATUS_DRAFT => CreditApplication::STATUS_STEP_2_COMPLETED,
            CreditApplication::STATUS_DRAFT => CreditApplication::STATUS_STEP_3_COMPLETED,
            CreditApplication::STATUS_STEP_1_COMPLETED => CreditApplication::STATUS_STEP_3_COMPLETED,
            CreditApplication::STATUS_STEP_2_COMPLETED => CreditApplication::STATUS_READY_FOR_VALIDATION_1,
        ] as $from => $to) {
            $this->assertTrue(
                $machine->canTransition($this->applicationAt($from), $to, $this->user),
                "expected forward jump {$from} → {$to} to be legal",
            );
        }
    }

    public function test_machine_rejects_a_backward_step(): void
    {
        $machine = $this->machine();
        $application = $this->applicationAt(CreditApplication::STATUS_STEP_2_COMPLETED);

        try {
            $machine->apply($application, CreditApplication::STATUS_STEP_1_COMPLETED, $this->user);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::InvalidApplicationStatus->value, $e->errorCode);
        }

        $this->assertSame(CreditApplication::STATUS_STEP_2_COMPLETED, $application->fresh()->status);
    }

    public function test_machine_rejects_an_unknown_current_status(): void
    {
        $machine = $this->machine();
        $application = $this->applicationAt('NOT_A_REAL_STAGE');

        $this->expectException(ApiException::class);
        $machine->apply($application, CreditApplication::STATUS_STEP_1_COMPLETED, $this->user);
    }

    public function test_machine_rejects_a_duplicate_same_state_transition(): void
    {
        $machine = $this->machine();
        $application = $this->applicationAt(CreditApplication::STATUS_SUBMITTED);

        $this->expectException(ApiException::class);
        $machine->apply($application, CreditApplication::STATUS_SUBMITTED, $this->user);
    }

    public function test_machine_rejects_an_unknown_target_state(): void
    {
        $machine = $this->machine();
        $application = $this->applicationAt(CreditApplication::STATUS_DRAFT);

        $this->assertFalse($machine->canTransition($application, 'DOCUMENTS_PENDING', $this->user));

        $this->expectException(ApiException::class);
        $machine->apply($application, 'DOCUMENTS_PENDING', $this->user);
    }

    // ---- machine: actor enforcement ---------------------------------------------------

    public function test_machine_blocks_customer_from_staff_and_admin_transitions(): void
    {
        $machine = $this->machine();

        try {
            $machine->apply($this->applicationAt(CreditApplication::STATUS_SUBMITTED), CreditApplication::STATUS_STAFF_APPROVED, $this->user);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::Forbidden->value, $e->errorCode);
            $this->assertSame(403, $e->status);
        }

        try {
            $machine->apply($this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED), CreditApplication::STATUS_APPROVED, $this->user);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::Forbidden->value, $e->errorCode);
        }
    }

    public function test_machine_blocks_staff_from_admin_transitions(): void
    {
        $machine = $this->machine();
        $staff = StaffUser::factory()->create();

        $this->assertFalse($machine->canTransition($this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED), CreditApplication::STATUS_APPROVED, $staff));

        $this->expectException(ApiException::class);
        $machine->apply($this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED), CreditApplication::STATUS_APPROVED, $staff);
    }

    public function test_machine_blocks_admin_from_customer_transitions(): void
    {
        $machine = $this->machine();
        $admin = StaffUser::factory()->admin()->create();

        $this->assertFalse($machine->canTransition($this->applicationAt(CreditApplication::STATUS_DRAFT), CreditApplication::STATUS_STEP_1_COMPLETED, $admin));
    }

    // ---- machine: terminal states -----------------------------------------------------

    public function test_rejected_and_cancelled_states_are_terminal(): void
    {
        $machine = $this->machine();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        foreach ([
            [CreditApplication::STATUS_STAFF_REJECTED, CreditApplication::STATUS_STAFF_APPROVED, $staff],
            [CreditApplication::STATUS_REJECTED, CreditApplication::STATUS_APPROVED, $admin],
            [CreditApplication::STATUS_REJECTED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, $admin],
            [CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, $this->user],
            [CreditApplication::STATUS_APPOINTMENT_LOCKED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, $this->user],
            [CreditApplication::STATUS_CANCELLED, CreditApplication::STATUS_DRAFT, $this->user],
            [CreditApplication::STATUS_CANCELLED, CreditApplication::STATUS_CANCELLED, $this->user],
        ] as [$from, $to, $actor]) {
            $this->assertFalse(
                $machine->canTransition($this->applicationAt($from), $to, $actor),
                "expected {$from} → {$to} to be impossible",
            );
        }
    }

    // ---- machine: audit -----------------------------------------------------------------

    public function test_every_transition_writes_a_status_changed_audit_row(): void
    {
        $application = $this->applicationAt(CreditApplication::STATUS_DRAFT);

        $this->machine()->apply($application, CreditApplication::STATUS_STEP_1_COMPLETED, $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'user_id' => $this->user->id,
            'staff_user_id' => null,
            'action' => 'credit_application.status_changed',
            'previous_state' => json_encode(['status' => CreditApplication::STATUS_DRAFT]),
            'new_state' => json_encode(['status' => CreditApplication::STATUS_STEP_1_COMPLETED]),
        ]);
    }

    public function test_staff_transitions_record_the_staff_member_on_the_audit_row(): void
    {
        $staff = StaffUser::factory()->create();
        $application = $this->applicationAt(CreditApplication::STATUS_SUBMITTED);

        $this->machine()->apply($application, CreditApplication::STATUS_STAFF_APPROVED, $staff);

        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'user_id' => null,
            'staff_user_id' => $staff->id,
            'action' => 'credit_application.status_changed',
        ]);
    }

    // ---- rejection reasons ------------------------------------------------------------

    public function test_staff_rejection_persists_the_reason_and_decided_by(): void
    {
        $staff = StaffUser::factory()->create();
        $application = $this->submittedApplication();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/reject", ['reason' => 'Pièce manquante'])
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_REJECTED);

        $application = $application->fresh();
        $this->assertSame('Pièce manquante', $application->rejection_reason);
        $this->assertSame($staff->id, $application->decided_by_staff_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'staff_user_id' => $staff->id,
            'action' => 'credit_application.staff_rejected',
        ]);
    }

    public function test_admin_rejection_persists_the_reason_and_decided_by(): void
    {
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        $application = $this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-reject", ['reason' => 'Dossier non conforme'])
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_REJECTED);

        $application = $application->fresh();
        $this->assertSame('Dossier non conforme', $application->rejection_reason);
        $this->assertSame($admin->id, $application->decided_by_admin_user_id);
    }

    public function test_a_rejected_application_cannot_be_re_decided(): void
    {
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        $application = $this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-reject", ['reason' => 'N/A']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::InvalidApplicationStatus->value);

        $this->assertSame(CreditApplication::STATUS_REJECTED, $application->fresh()->status);
    }

    // ---- duplicate operations ---------------------------------------------------------

    public function test_second_staff_approval_is_rejected(): void
    {
        $staff = StaffUser::factory()->create();
        $application = $this->submittedApplication();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve");
        $this->postJson("/api/staff/applications/{$application->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::InvalidApplicationStatus->value);
    }

    public function test_duplicate_submit_is_rejected(): void
    {
        $application = $this->submittedApplication();

        // The service's own guard (status must be FINAL_LOCKED) rejects the second submit with
        // a coarse 409 before the machine ever sees it — same outcome, one envelope earlier.
        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_NOT_LOCKED');
    }

    // ---- cancellation: HTTP --------------------------------------------------------------

    public function test_customer_can_cancel_before_committing(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);

        $this->postJson("/api/applications/{$application->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_CANCELLED);

        $this->assertSame(CreditApplication::STATUS_CANCELLED, $application->fresh()->status);
    }

    public function test_cancel_is_terminal_and_duplicate_cancels_are_rejected(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/cancel")->assertOk();
        $this->postJson("/api/applications/{$application->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::InvalidApplicationStatus->value);
        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(409);
    }

    public function test_cancel_after_final_lock_is_rejected(): void
    {
        $application = $this->newApplication();
        $this->completeAllThreeSteps($application);
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        $this->postJson("/api/applications/{$application->id}/cancel")
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::Forbidden->value);

        // After validation-2, the application is auto-submitted (SUBMITTED), which is past
        // FINAL_LOCKED in the progression — cancellation by the customer is forbidden (staff|admin only).
        $this->assertSame(CreditApplication::STATUS_SUBMITTED, $application->fresh()->status);
    }

    public function test_only_the_owner_can_cancel(): void
    {
        $application = $this->newApplication();
        $other = User::factory()->create();

        Sanctum::actingAs($other, ['*']);
        $this->postJson("/api/applications/{$application->id}/cancel")->assertStatus(403);
    }

    public function test_cancellation_writes_its_audit_events(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/cancel")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'action' => 'credit_application.status_changed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'action' => 'credit_application.cancelled',
        ]);
    }

    public function test_a_cancelled_application_opens_a_report(): void
    {
        $application = $this->newApplication();
        $this->postJson("/api/applications/{$application->id}/cancel")->assertOk();

        $this->assertTrue($application->fresh()->isReportOpen());
    }

    public function test_cancel_keeps_the_application_frozen_for_edits(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/cancel")->assertOk();

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'APPLICATION_LOCKED');
    }

    // ---- admin final approval ---------------------------------------------------------

    public function test_admin_final_approval_records_decided_by_admin(): void
    {
        // Remove the default branch created in setUp so we control exactly which branch is matched.
        Branch::query()->delete();
        $branch = Branch::factory()->default()->create();
        $admin = StaffUser::factory()->admin()->create();
        $application = $this->applicationAt(CreditApplication::STATUS_STAFF_APPROVED);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);

        $application = $application->fresh();
        $this->assertSame($admin->id, $application->decided_by_admin_user_id);
        $this->assertNotNull($application->latestAppointment());
        $this->assertSame($branch->id, $application->latestAppointment()->branch_id);
    }
}
