<?php

namespace Tests\Feature\CreditApplication;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->customer = User::factory()->create();
    }

    /** Drives a fresh application all the way to APPROVED (customer -> staff -> admin). */
    private function approvedApplication(?Branch $matchingBranch = null): CreditApplication
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
            'adresse' => '12 Rue de la République', 'ville' => $matchingBranch->ville ?? 'Tunis',
            'code_postal' => '1000', 'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
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

        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$id}/approve");

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$id}/admin-approve");

        Sanctum::actingAs($this->customer, ['*']);

        return CreditApplication::findOrFail($id);
    }

    public function test_admin_approval_automatically_proposes_the_first_appointment(): void
    {
        $branch = Branch::factory()->default()->create();

        $application = $this->approvedApplication($branch);

        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_PROPOSED, $application->fresh()->status);
        $appointment = $application->fresh()->latestAppointment();
        $this->assertNotNull($appointment);
        $this->assertSame(1, $appointment->attempt_number);
        $this->assertSame($branch->id, $appointment->branch_id);
        $this->assertSame(Carbon::tomorrow()->toDateString(), $appointment->scheduled_date->toDateString());
        $this->assertSame('08:00:00', $appointment->scheduled_time);
    }

    public function test_matches_branch_by_project_ville_over_the_default(): void
    {
        Branch::factory()->default()->create(['ville' => 'Sfax']);
        $sousseBranch = Branch::factory()->create(['ville' => 'Sousse']);

        $application = $this->approvedApplication($sousseBranch);

        $this->assertSame($sousseBranch->id, $application->fresh()->latestAppointment()->branch_id);
    }

    public function test_customer_can_view_and_accept_the_proposed_appointment(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        $this->getJson("/api/applications/{$application->id}/appointment")
            ->assertOk()
            ->assertJsonPath('data.appointment.status', Appointment::STATUS_PROPOSED)
            ->assertJsonPath('data.appointment.branch.name', $branch->name);

        $this->postJson("/api/applications/{$application->id}/appointment/accept")
            ->assertOk()
            ->assertJsonPath('data.appointment.status', Appointment::STATUS_ACCEPTED);

        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $application->fresh()->status);
    }

    public function test_rejecting_proposes_a_new_slot_at_the_same_branch(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);
        $first = $application->fresh()->latestAppointment();

        $response = $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $response->assertOk()->assertJsonPath('data.application_status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);
        $second = $application->fresh()->latestAppointment();
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame($branch->id, $second->branch_id);
        $this->assertNotSame($first->id, $second->id);
        // Not '09:00:00': a rejected appointment frees its slot (proven separately by
        // test_a_rejected_slot_frees_up_for_the_next_proposal), so the freed 08:00 slot is
        // reused rather than moving to the next one.
        $this->assertSame('08:00:00', $second->scheduled_time);
    }

    public function test_a_third_rejection_locks_the_application_for_staff(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        $this->postJson("/api/applications/{$application->id}/appointment/reject");
        $this->postJson("/api/applications/{$application->id}/appointment/reject");
        $response = $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $response->assertOk()
            ->assertJsonPath('data.application_status', CreditApplication::STATUS_APPOINTMENT_LOCKED)
            ->assertJsonPath('data.appointment', null);

        $this->assertSame(3, $application->fresh()->appointments()->count());
        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_LOCKED, $application->fresh()->status);
    }

    public function test_cannot_accept_or_reject_after_the_application_is_locked(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        $this->postJson("/api/applications/{$application->id}/appointment/reject");
        $this->postJson("/api/applications/{$application->id}/appointment/reject");
        $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $this->postJson("/api/applications/{$application->id}/appointment/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_ALREADY_DECIDED');
    }

    public function test_a_full_day_rolls_over_to_the_next_day(): void
    {
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        // Fill tomorrow to capacity with unrelated applications' appointments.
        for ($i = 0; $i < 4; $i++) {
            $other = CreditApplication::factory()->create();
            Appointment::create([
                'credit_application_id' => $other->id,
                'branch_id' => $branch->id,
                'attempt_number' => 1,
                'scheduled_date' => Carbon::tomorrow(),
                'scheduled_time' => $branch->slotTimes()[$i],
                'status' => Appointment::STATUS_PROPOSED,
            ]);
        }

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        $this->assertSame(Carbon::tomorrow()->addDay()->toDateString(), $appointment->scheduled_date->toDateString());
        $this->assertSame('08:00:00', $appointment->scheduled_time);
    }

    public function test_a_rejected_slot_frees_up_for_the_next_proposal(): void
    {
        $branch = Branch::factory()->default()->create(['daily_capacity' => 1]);

        $application = $this->approvedApplication($branch);
        $firstDate = $application->fresh()->latestAppointment()->scheduled_date->toDateString();

        // capacity is 1, so rejecting the only slot tomorrow must free it up again rather than
        // rolling to a new day — status != rejected is the count filter that proves this.
        $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $second = $application->fresh()->latestAppointment();
        $this->assertSame($firstDate, $second->scheduled_date->toDateString());
    }

    public function test_another_customer_cannot_view_or_act_on_this_appointment(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        $other = User::factory()->create();
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/applications/{$application->id}/appointment")->assertStatus(403);
        $this->postJson("/api/applications/{$application->id}/appointment/accept")->assertStatus(403);
    }

    public function test_no_branch_configured_returns_a_clear_error(): void
    {
        // No branches at all, not even a default — approvedApplication() itself will hit this
        // during admin-approve, since that's what triggers the first proposal.
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
            'adresse' => '12 Rue de la République', 'ville' => 'Tunis',
            'code_postal' => '1000', 'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
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

        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$id}/approve");

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/staff/applications/{$id}/admin-approve");

        $response->assertStatus(503)->assertJsonPath('error.code', 'NO_BRANCH_AVAILABLE');
    }
}
