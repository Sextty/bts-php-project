<?php

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);

        $this->postJson("/api/applications/{$id}/validation-1");
        $this->postJson("/api/applications/{$id}/validation-2");
        $this->postJson("/api/applications/{$id}/submit");

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
        $staff = StaffUser::factory()->create();
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
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_APPROVED);

        Sanctum::actingAs($admin, ['*']);
        // adminApprove() chains straight into the first appointment proposal, so the
        // application's resting status is APPOINTMENT_PROPOSED, not APPROVED.
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")
            ->assertOk()
            ->assertJsonPath('data.application.status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);

        $application->refresh();
        $this->assertSame($staff->id, $application->decided_by_staff_user_id);
        $this->assertSame($admin->id, $application->decided_by_admin_user_id);
    }

    public function test_staff_reject_requires_a_reason_and_records_it(): void
    {
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->create();
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/staff/applications/{$application->id}/reject", [])
            ->assertStatus(422);

        $response = $this->postJson("/api/staff/applications/{$application->id}/reject", [
            'reason' => 'Income documentation does not match the declared amount.',
        ]);

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_STAFF_REJECTED);
        $this->assertSame(
            'Income documentation does not match the declared amount.',
            $response->json('data.application.rejection_reason'),
        );
    }

    public function test_admin_reject_after_staff_approval(): void
    {
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve");

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/staff/applications/{$application->id}/admin-reject", [
            'reason' => 'Project financing plan is not viable.',
        ]);

        $response->assertOk()->assertJsonPath('data.application.status', CreditApplication::STATUS_REJECTED);
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

        $staff = StaffUser::factory()->create();
        Sanctum::actingAs($staff, ['*']);

        $this->postJson("/api/staff/applications/{$draftId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_APPLICATION_STATUS');
    }

    public function test_a_decided_application_can_no_longer_be_edited_by_the_customer(): void
    {
        $application = $this->submittedApplication();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/approve");
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve");

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
