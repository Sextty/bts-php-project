<?php

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\AppNotification;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->customer = User::factory()->create();
        // admin-approve auto-proposes the first appointment, which needs a matchable branch.
        Branch::factory()->default()->create();
    }

    /** Drives a fresh application through the customer flow to SUBMITTED, as $this->customer. */
    private function submittedApplication(): CreditApplication
    {
        Sanctum::actingAs($this->customer, ['*']);

        $id = $this->postJson('/api/applications')->json('data.application.id');

        $this->putJson("/api/applications/{$id}/client", [
            'code_client' => 'CL-0001', 'civilite' => 'M', 'nom' => 'Ben Salah', 'prenom' => 'Karim',
            'nom_epoux' => null, 'deuxieme_prenom' => null, 'date_naissance' => '1990-05-12',
            'lieu_naissance' => 'Tunis', 'pays_naissance' => 'Tunisie', 'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie', 'etat_civil' => 'célibataire', 'nombre_enfants' => 0,
            'type_pid' => 'CIN', 'numero_pid' => '12345678', 'date_delivrance_pid' => '2015-01-10',
            'lieu_delivrance_pid' => 'Tunis', 'numero_carte_sejour' => null, 'profession' => 'Ingénieur',
            'date_entree_relation' => '2020-01-01',
        ]);

        $this->putJson("/api/applications/{$id}/credit", [
            'identifiant_personne' => 'CL-0001', 'nom_ou_rs' => 'Ben Salah', 'prenom_ou_dc' => 'Karim',
            'type_pid' => 'CIN', 'numero_pid' => '12345678', 'origine' => 'agence',
            'date_depot' => '2026-01-05', 'date_reception' => '2026-01-06', 'type_demande' => 'crédit personnel',
            'code_devise' => 'TND', 'montant_global_sollicite' => 15000, 'nombre_credits_sollicites' => 1,
            'unite_depot' => 'agence centrale',
        ]);

        $this->putJson("/api/applications/{$id}/project", [
            'code_projet' => 'PR-0001', 'identifiant_personne' => 'CL-0001', 'nom_ou_rs' => 'Ben Salah',
            'prenom_ou_dc' => 'Karim', 'type_projet' => 'extension', 'objet' => 'Achat de matériel',
            'adresse' => '12 Rue de la République', 'ville' => 'Tunis', 'code_postal' => '1000',
            'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
            'delegation' => 'Bab Bhar', 'localisation' => 'Centre-ville', 'cout' => 20000,
            'investissement_personnel' => 5000, 'financement' => 15000, 'revenus' => 3000, 'depenses' => 1500,
        ]);

        $this->postJson("/api/applications/{$id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);

        $this->postJson("/api/applications/{$id}/validation-1");
        $this->postJson("/api/applications/{$id}/validation-2");

        return CreditApplication::findOrFail($id);
    }

    public function test_staff_login_issues_a_token(): void
    {
        $staff = StaffUser::factory()->create(['email' => 'staff@bts.test', 'password' => Hash::make('CorrectHorseBattery')]);

        $response = $this->postJson('/api/staff/login', [
            'email' => 'staff@bts.test',
            'password' => 'CorrectHorseBattery',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['access_token', 'staff_user']]);
        $this->assertSame($staff->id, $response->json('data.staff_user.id'));
    }

    public function test_staff_login_rejects_wrong_password(): void
    {
        StaffUser::factory()->create(['email' => 'staff@bts.test', 'password' => Hash::make('CorrectHorseBattery')]);

        $this->postJson('/api/staff/login', ['email' => 'staff@bts.test', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_admin_login_issues_a_token_for_admin_accounts(): void
    {
        $admin = StaffUser::factory()->admin()->create([
            'email' => 'admin@bts.test',
            'password' => Hash::make('CorrectHorseBattery'),
        ]);

        $response = $this->postJson('/api/staff/admin/login', [
            'email' => 'admin@bts.test',
            'password' => 'CorrectHorseBattery',
        ]);

        $response->assertOk()->assertJsonPath('data.staff_user.role', 'admin');
        $this->assertSame($admin->id, $response->json('data.staff_user.id'));
    }

    public function test_super_admin_can_use_the_admin_entrance(): void
    {
        StaffUser::factory()->create([
            'role' => 'super_admin',
            'email' => 'superadmin@bts.test',
            'password' => Hash::make('CorrectHorseBattery'),
        ]);

        $this->postJson('/api/staff/admin/login', [
            'email' => 'superadmin@bts.test',
            'password' => 'CorrectHorseBattery',
        ])->assertOk()->assertJsonPath('data.staff_user.role', 'super_admin');
    }

    public function test_admin_portal_rejects_staff_accounts(): void
    {
        StaffUser::factory()->create([
            'role' => 'staff',
            'email' => 'staff@bts.test',
            'password' => Hash::make('CorrectHorseBattery'),
        ]);

        $this->postJson('/api/staff/admin/login', ['email' => 'staff@bts.test', 'password' => 'CorrectHorseBattery'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_staff_portal_rejects_admin_accounts(): void
    {
        StaffUser::factory()->admin()->create([
            'email' => 'admin@bts.test',
            'password' => Hash::make('CorrectHorseBattery'),
        ]);

        $this->postJson('/api/staff/login', ['email' => 'admin@bts.test', 'password' => 'CorrectHorseBattery'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_customer_token_cannot_reach_staff_routes(): void
    {
        Sanctum::actingAs($this->customer, ['*']);

        $this->getJson('/api/staff/applications')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_staff_token_cannot_reach_admin_only_routes(): void
    {
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->create();
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_staff_lists_submitted_applications(): void
    {
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->forBranch($application->branch)->create();
        Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson('/api/staff/applications');

        $response->assertOk();
        $this->assertSame($application->id, $response->json('data.applications.0.id'));
        // applicant.* is the account holder (User), distinct from client.nom/prenom (the Étape 1
        // form data) — this checks the right relation is being surfaced, not the wrong one.
        $this->assertSame($this->customer->email, $response->json('data.applications.0.applicant.email'));
    }

    public function test_full_happy_path_submitted_to_approved(): void
    {
        Mail::fake();
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->forBranch($application->branch)->create();
        $admin = StaffUser::factory()->admin()->create();

        // 1. Staff approval -> transitions to STAFF_APPROVED
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_APPROVED);

        $application->refresh();
        $this->assertSame($staff->id, $application->decided_by_staff_user_id);
        $this->assertNull($application->latestAppointment());
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertSame(0, AppNotification::query()->where('type', 'admin.approved')->count());

        // 2. Admin approval -> transitions to APPROVED -> triggers APPOINTMENT_PROPOSED
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);

        $application->refresh();
        $this->assertSame($admin->id, $application->decided_by_admin_user_id);
        $appointment = $application->latestAppointment();
        $this->assertNotNull($appointment);
        $notification = AppNotification::query()->where('type', 'admin.approved')->sole();
        $this->assertStringContainsString($application->application_number, $notification->body);
        $this->assertStringContainsString($appointment->branch->name, $notification->body);
        $this->assertStringContainsString(substr($appointment->scheduled_time, 0, 5), $notification->body);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'action' => 'credit_application.final_decision_notification_queued',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'credit_application_id' => $application->id,
            'action' => 'credit_application.final_decision_notification_sent',
        ]);

        // Every portal reads the same persisted state. The admin approval immediately advances
        // to the appointment workflow, so the staff final-decision queue must include those
        // follow-on statuses instead of looking only for the transient APPROVED state.
        Sanctum::actingAs($staff, ['*']);
        $this->getJson('/api/staff/applications?status[]=APPROVED&status[]=APPOINTMENT_PROPOSED&status[]=APPOINTMENT_CONFIRMED&status[]=APPOINTMENT_LOCKED')
            ->assertOk()
            ->assertJsonPath('data.applications.0.id', $application->id)
            ->assertJsonPath('data.applications.0.status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);

        Sanctum::actingAs($this->customer, ['*']);
        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_APPLICATION_STATUS');
        $this->assertSame(1, AppNotification::query()->where('type', 'admin.approved')->count());
        Mail::assertSentCount(1);
    }

    public function test_admin_reject_from_staff_approved(): void
    {
        Mail::fake();
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->forBranch($application->branch)->create();
        $admin = StaffUser::factory()->admin()->create();

        // Staff approves first
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_APPROVED);

        // Admin rejects from STAFF_APPROVED
        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/staff/applications/{$application->id}/admin-reject", [
            'reason' => 'Project financing plan is not viable.',
        ]);

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_REJECTED);
        $this->assertNull($application->fresh()->latestAppointment());
        $notification = AppNotification::query()->where('type', 'admin.rejected')->sole();
        $this->assertStringContainsString($application->application_number, $notification->body);
        $this->assertStringNotContainsString('Project financing plan is not viable.', $notification->body);
        Mail::assertSentCount(1);

        Sanctum::actingAs($this->customer, ['*']);
        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.application.rejection_reason', null);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/admin-reject", [
            'reason' => 'Repeated internal note.',
        ])->assertStatus(409);
        $this->assertSame(1, AppNotification::query()->where('type', 'admin.rejected')->count());
        Mail::assertSentCount(1);
    }

    public function test_staff_rejection_is_a_terminal_rejection_email_without_internal_notes(): void
    {
        Mail::fake();
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->forBranch($application->branch)->create();
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/reject", [
            'reason' => 'Internal risk model note.',
        ])->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_REJECTED);

        $notification = AppNotification::query()->where('type', 'staff.rejected')->sole();
        $this->assertStringContainsString($application->application_number, $notification->body);
        $this->assertStringNotContainsString('Internal risk model note.', $notification->body);
        $this->assertNull($application->fresh()->latestAppointment());
        Mail::assertSentCount(1);
    }

    public function test_admin_cannot_decide_before_staff_approves(): void
    {
        $application = $this->submittedApplication();
        $admin = StaffUser::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_APPLICATION_STATUS');
    }

    public function test_staff_cannot_approve_a_draft_application(): void
    {
        Sanctum::actingAs($this->customer, ['*']);
        $draftId = $this->postJson('/api/applications')->json('data.application.id');

        $staff = StaffUser::factory()->admin()->create();
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/staff/applications/{$draftId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_APPLICATION_STATUS');
    }

    public function test_a_decided_application_can_no_longer_be_edited_by_the_customer(): void
    {
        $application = $this->submittedApplication();

        // staffApprove() chains to APPOINTMENT_PROPOSED, which locks the application.
        $staff = StaffUser::factory()->forBranch($application->branch)->create();
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve");

        Sanctum::actingAs($this->customer, ['*']);
        // A full, otherwise-valid payload — the point is proving the lock check itself blocks
        // this (403 APPLICATION_LOCKED), not that an incomplete payload fails validation (422)
        // before ever reaching that check.
        $this->putJson("/api/applications/{$application->id}/client", [
            'code_client' => 'CL-0001', 'civilite' => 'M', 'nom' => 'Changed', 'prenom' => 'Karim',
            'nom_epoux' => null, 'deuxieme_prenom' => null, 'date_naissance' => '1990-05-12',
            'lieu_naissance' => 'Tunis', 'pays_naissance' => 'Tunisie', 'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie', 'etat_civil' => 'célibataire', 'nombre_enfants' => 0,
            'type_pid' => 'CIN', 'numero_pid' => '12345678', 'date_delivrance_pid' => '2015-01-10',
            'lieu_delivrance_pid' => 'Tunis', 'numero_carte_sejour' => null, 'profession' => 'Ingénieur',
            'date_entree_relation' => '2020-01-01',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'APPLICATION_LOCKED');
    }
}
