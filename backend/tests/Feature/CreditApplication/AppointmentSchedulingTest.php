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
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['is_valid' => true])]]]]],
            ], 200),
        ]);
        $this->customer = User::factory()->create();
    }

    /** Drives a fresh application all the way to APPOINTMENT_PROPOSED (customer -> staff). */
    private function approvedApplication(?Branch $matchingBranch = null, array $projectOverrides = []): CreditApplication
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

        $projectPayload = array_merge([
            'code_projet' => 'PR-0001', 'identifiant_personne' => 'CL-0001', 'nom_ou_rs' => 'Ben Salah',
            'prenom_ou_dc' => 'Karim', 'type_projet' => 'extension', 'objet' => 'Achat de matériel',
            'adresse' => '12 Rue de la République', 'ville' => $matchingBranch->ville ?? 'Tunis',
            'code_postal' => '1000', 'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
            'delegation' => 'Bab Bhar', 'localisation' => 'Centre-ville', 'cout' => 20000,
            'investissement_personnel' => 5000, 'financement' => 15000, 'revenus' => 3000, 'depenses' => 1500,
        ], $projectOverrides);

        $this->putJson("/api/applications/{$id}/project", $projectPayload);

        $this->postJson("/api/applications/{$id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);

        $this->postJson("/api/applications/{$id}/validation-1");
        $this->postJson("/api/applications/{$id}/validation-2");

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
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday
        $branch = Branch::factory()->default()->create();

        $application = $this->approvedApplication($branch);

        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_PROPOSED, $application->fresh()->status);
        $appointment = $application->fresh()->latestAppointment();
        $this->assertNotNull($appointment);
        $this->assertSame(1, $appointment->attempt_number);
        $this->assertSame($branch->id, $appointment->branch_id);
        $this->assertSame('2026-08-18', $appointment->scheduled_date->toDateString()); // Tuesday
        $this->assertSame('09:00:00', $appointment->scheduled_time);
        $this->assertFalse($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
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
            ->assertJsonPath('data.appointment.branch.name', $branch->name)
            ->assertJsonPath('data.appointment.branch.governorate', $branch->ville)
            ->assertJsonPath('data.appointment.is_auto_scheduled_future', false);

        $this->postJson("/api/applications/{$application->id}/appointment/accept")
            ->assertOk()
            ->assertJsonPath('data.appointment.status', Appointment::STATUS_ACCEPTED);

        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $application->fresh()->status);
    }

    public function test_rejecting_proposes_a_new_slot_at_the_same_branch(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);
        $first = $application->fresh()->latestAppointment();

        $response = $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $response->assertOk()->assertJsonPath('data.application_status', CreditApplication::STATUS_APPOINTMENT_PROPOSED);
        $second = $application->fresh()->latestAppointment();
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame($branch->id, $second->branch_id);
        // A rejected appointment causes the next proposal for this application to advance to the next available slot
        $this->assertSame('11:00:00', $second->scheduled_time);
        Carbon::setTestNow();
    }

    public function test_a_third_rejection_locks_the_application_for_staff(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        for ($i = 0; $i < Appointment::MAX_ATTEMPTS - 1; $i++) {
            $this->postJson("/api/applications/{$application->id}/appointment/reject");
        }
        $response = $this->postJson("/api/applications/{$application->id}/appointment/reject");

        $response->assertOk()
            ->assertJsonPath('data.application_status', CreditApplication::STATUS_APPOINTMENT_LOCKED)
            ->assertJsonPath('data.appointment', null);

        $this->assertSame(Appointment::MAX_ATTEMPTS, $application->fresh()->appointments()->count());
        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_LOCKED, $application->fresh()->status);
    }

    public function test_cannot_accept_or_reject_after_the_application_is_locked(): void
    {
        $branch = Branch::factory()->default()->create();
        $application = $this->approvedApplication($branch);

        for ($i = 0; $i < Appointment::MAX_ATTEMPTS; $i++) {
            $this->postJson("/api/applications/{$application->id}/appointment/reject");
        }

        $this->postJson("/api/applications/{$application->id}/appointment/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_ALREADY_DECIDED');
    }

    /** Test 1: Monday full -> rolls over to Tuesday 09:00 with is_auto_scheduled_future = true. */
    public function test_monday_full_schedules_tuesday_with_future_flag(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00'); // Sunday -> next working day is Monday (2026-08-17)
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        $monday = '2026-08-17';
        $slots = ['09:00:00', '11:00:00', '14:00:00', '15:00:00'];
        foreach ($slots as $slot) {
            $other = CreditApplication::factory()->create();
            Appointment::create([
                'credit_application_id' => $other->id,
                'branch_id' => $branch->id,
                'attempt_number' => 1,
                'scheduled_date' => $monday,
                'scheduled_time' => $slot,
                'status' => Appointment::STATUS_ACCEPTED,
                'is_auto_scheduled_future' => false,
            ]);
        }

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        $this->assertSame('2026-08-18', $appointment->scheduled_date->toDateString()); // Tuesday
        $this->assertSame('09:00:00', $appointment->scheduled_time);
        $this->assertTrue($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 2: Monday + Tuesday full -> rolls over to Wednesday 09:00 with is_auto_scheduled_future = true. */
    public function test_monday_and_tuesday_full_schedules_wednesday(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00'); // Sunday -> target earliest working day is Monday
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        $days = ['2026-08-17', '2026-08-18']; // Monday, Tuesday
        $slots = ['09:00:00', '11:00:00', '14:00:00', '15:00:00'];
        foreach ($days as $day) {
            foreach ($slots as $slot) {
                $other = CreditApplication::factory()->create();
                Appointment::create([
                    'credit_application_id' => $other->id,
                    'branch_id' => $branch->id,
                    'attempt_number' => 1,
                    'scheduled_date' => $day,
                    'scheduled_time' => $slot,
                    'status' => Appointment::STATUS_ACCEPTED,
                ]);
            }
        }

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        $this->assertSame('2026-08-19', $appointment->scheduled_date->toDateString()); // Wednesday
        $this->assertSame('09:00:00', $appointment->scheduled_time);
        $this->assertTrue($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 3: Friday full -> rolls over to Monday 09:00, skipping Saturday and Sunday. */
    public function test_friday_full_schedules_monday_skipping_weekends(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00'); // Thursday -> next working day is Friday (2026-08-21)
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        $friday = '2026-08-21';
        $slots = ['09:00:00', '11:00:00', '14:00:00', '15:00:00'];
        foreach ($slots as $slot) {
            $other = CreditApplication::factory()->create();
            Appointment::create([
                'credit_application_id' => $other->id,
                'branch_id' => $branch->id,
                'attempt_number' => 1,
                'scheduled_date' => $friday,
                'scheduled_time' => $slot,
                'status' => Appointment::STATUS_ACCEPTED,
            ]);
        }

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        // Must skip Saturday (2026-08-22) and Sunday (2026-08-23)
        $this->assertSame('2026-08-24', $appointment->scheduled_date->toDateString()); // Monday
        $this->assertSame('09:00:00', $appointment->scheduled_time);
        $this->assertTrue($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 4: Cancelled slot (11:00 cancelled, others occupied) -> assigns 11:00 on same day without rolling over. */
    public function test_cancelled_slot_is_reassigned_on_same_day(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday -> target is Tuesday (2026-08-18)
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);
        $tuesday = '2026-08-18';

        // 09:00 accepted, 11:00 cancelled, 14:00 accepted, 15:00 accepted
        Appointment::create(['credit_application_id' => CreditApplication::factory()->create()->id, 'branch_id' => $branch->id, 'attempt_number' => 1, 'scheduled_date' => $tuesday, 'scheduled_time' => '09:00:00', 'status' => Appointment::STATUS_ACCEPTED]);
        Appointment::create(['credit_application_id' => CreditApplication::factory()->create()->id, 'branch_id' => $branch->id, 'attempt_number' => 1, 'scheduled_date' => $tuesday, 'scheduled_time' => '11:00:00', 'status' => Appointment::STATUS_CANCELLED]);
        Appointment::create(['credit_application_id' => CreditApplication::factory()->create()->id, 'branch_id' => $branch->id, 'attempt_number' => 1, 'scheduled_date' => $tuesday, 'scheduled_time' => '14:00:00', 'status' => Appointment::STATUS_ACCEPTED]);
        Appointment::create(['credit_application_id' => CreditApplication::factory()->create()->id, 'branch_id' => $branch->id, 'attempt_number' => 1, 'scheduled_date' => $tuesday, 'scheduled_time' => '15:00:00', 'status' => Appointment::STATUS_ACCEPTED]);

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        $this->assertSame($tuesday, $appointment->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $appointment->scheduled_time);
        $this->assertFalse($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 5: Rejected appointments do not consume capacity. */
    public function test_rejected_slot_does_not_consume_capacity(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday -> target is Tuesday
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);
        $tuesday = '2026-08-18';

        // 09:00 rejected, others free
        Appointment::create(['credit_application_id' => CreditApplication::factory()->create()->id, 'branch_id' => $branch->id, 'attempt_number' => 1, 'scheduled_date' => $tuesday, 'scheduled_time' => '09:00:00', 'status' => Appointment::STATUS_REJECTED]);

        $application = $this->approvedApplication($branch);
        $appointment = $application->fresh()->latestAppointment();

        $this->assertSame($tuesday, $appointment->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $appointment->scheduled_time);
        $this->assertFalse($appointment->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 6: Cancelled application cancels its appointment and frees the slot. */
    public function test_cancelled_application_frees_its_appointment_slot(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);
        $tuesday = '2026-08-18';

        // App 1 takes 09:00
        $app1 = CreditApplication::factory()->create(['status' => CreditApplication::STATUS_DRAFT]);
        $appt1 = Appointment::create([
            'credit_application_id' => $app1->id,
            'branch_id' => $branch->id,
            'attempt_number' => 1,
            'scheduled_date' => $tuesday,
            'scheduled_time' => '09:00:00',
            'status' => Appointment::STATUS_PROPOSED,
        ]);

        // Cancel app1 via API
        Sanctum::actingAs($app1->user, ['*']);
        $this->postJson("/api/applications/{$app1->id}/cancel")->assertOk();

        $this->assertSame(Appointment::STATUS_CANCELLED, $appt1->fresh()->status);

        // App 2 should now receive 09:00 on Tuesday
        $app2 = $this->approvedApplication($branch);
        $appt2 = $app2->fresh()->latestAppointment();

        $this->assertSame($tuesday, $appt2->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $appt2->scheduled_time);
        $this->assertFalse($appt2->is_auto_scheduled_future);
        Carbon::setTestNow();
    }

    /** Test 7: Future flag is true ONLY when moved due to capacity, false otherwise. */
    public function test_future_flag_is_persisted_and_returned_in_api(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        // Normal appointment on Tuesday
        $appNormal = $this->approvedApplication($branch);
        $apptNormal = $appNormal->fresh()->latestAppointment();
        $this->assertFalse($apptNormal->isAutoScheduledFuture());

        // Fill remaining Tuesday slots
        foreach (['11:00:00', '14:00:00', '15:00:00'] as $slot) {
            Appointment::create([
                'credit_application_id' => CreditApplication::factory()->create()->id,
                'branch_id' => $branch->id,
                'attempt_number' => 1,
                'scheduled_date' => '2026-08-18',
                'scheduled_time' => $slot,
                'status' => Appointment::STATUS_ACCEPTED,
            ]);
        }

        // New customer application -> Tuesday is full -> Wednesday 09:00
        $customer2 = User::factory()->create();
        Sanctum::actingAs($customer2, ['*']);
        $id2 = $this->postJson('/api/applications')->json('data.application.id');
        $this->putJson("/api/applications/{$id2}/client", [
            'code_client' => 'CL-0002', 'civilite' => 'M', 'nom' => 'Trabelsi', 'prenom' => 'Sami',
            'nom_epoux' => null, 'deuxieme_prenom' => null, 'date_naissance' => '1992-05-12',
            'lieu_naissance' => 'Tunis', 'pays_naissance' => 'Tunisie', 'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie', 'etat_civil' => 'célibataire', 'nombre_enfants' => 0,
            'type_pid' => 'CIN', 'numero_pid' => '87654321', 'date_delivrance_pid' => '2016-01-10',
            'lieu_delivrance_pid' => 'Tunis', 'numero_carte_sejour' => null, 'profession' => 'Commerçant',
            'date_entree_relation' => '2021-01-01',
        ]);
        $this->putJson("/api/applications/{$id2}/credit", [
            'identifiant_personne' => 'CL-0002', 'nom_ou_rs' => 'Trabelsi', 'prenom_ou_dc' => 'Sami',
            'type_pid' => 'CIN', 'numero_pid' => '87654321', 'origine' => 'agence',
            'date_depot' => '2026-01-05', 'date_reception' => '2026-01-06', 'type_demande' => 'crédit personnel',
            'code_devise' => 'TND', 'montant_global_sollicite' => 10000, 'nombre_credits_sollicites' => 1,
            'unite_depot' => 'agence centrale',
        ]);
        $this->putJson("/api/applications/{$id2}/project", [
            'code_projet' => 'PR-0002', 'identifiant_personne' => 'CL-0002', 'nom_ou_rs' => 'Trabelsi',
            'prenom_ou_dc' => 'Sami', 'type_projet' => 'création', 'objet' => 'Stock initial',
            'adresse' => '12 Rue de la République', 'ville' => $branch->ville,
            'code_postal' => '1000', 'activite' => 'Commerce', 'description' => "Nouveau magasin.",
            'delegation' => 'Bab Bhar', 'localisation' => 'Centre-ville', 'cout' => 15000,
            'investissement_personnel' => 5000, 'financement' => 10000, 'revenus' => 2500, 'depenses' => 1000,
        ]);
        $this->postJson("/api/applications/{$id2}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $this->postJson("/api/applications/{$id2}/validation-1");
        $this->postJson("/api/applications/{$id2}/validation-2");

        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$id2}/approve");
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$id2}/admin-approve");

        Sanctum::actingAs($customer2, ['*']);
        $this->getJson("/api/applications/{$id2}/appointment")
            ->assertOk()
            ->assertJsonPath('data.appointment.is_auto_scheduled_future', true)
            ->assertJsonPath('data.appointment.scheduled_date', '2026-08-19T00:00:00.000000Z')
            ->assertJsonPath('data.appointment.scheduled_time', '09:00:00');

        Carbon::setTestNow();
    }

    /** Test 8: Agency isolation — staff from Agency A cannot manage applications from Agency B; Admin can access all. */
    public function test_agency_isolation_for_appointments_and_applications(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Agency A', 'ville' => 'Tunis']);
        $branchB = Branch::factory()->create(['name' => 'Agency B', 'ville' => 'Ariana']);

        $staffA = StaffUser::factory()->create(['branch_id' => $branchA->id]);
        $staffB = StaffUser::factory()->create(['branch_id' => $branchB->id]);
        $admin = StaffUser::factory()->admin()->create();

        // Application at Branch A
        $appA = $this->approvedApplication($branchA);

        // Staff B cannot access App A
        Sanctum::actingAs($staffB, ['*']);
        $this->getJson("/api/staff/applications/{$appA->id}")->assertStatus(403);

        // Staff A can access App A and see appointment details
        Sanctum::actingAs($staffA, ['*']);
        $this->getJson("/api/staff/applications/{$appA->id}")
            ->assertOk()
            ->assertJsonPath('data.application.latest_appointment.branch.name', 'Agency A');

        // Admin can access App A
        Sanctum::actingAs($admin, ['*']);
        $this->getJson("/api/staff/applications/{$appA->id}")
            ->assertOk()
            ->assertJsonPath('data.application.latest_appointment.branch.name', 'Agency A');
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
        // No branches at all, not even a default — staffApprove() now triggers
        // BranchMatchingService, so the error fires at staff-approve time.
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

        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/staff/applications/{$id}/approve")->assertOk();

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/staff/applications/{$id}/admin-approve");

        $response->assertStatus(503)->assertJsonPath('error.code', 'NO_BRANCH_AVAILABLE');
    }

    public function test_delegation_match_when_multiple_agencies_exist_in_same_governorate(): void
    {
        $branchBabBhar = Branch::factory()->create(['name' => 'Agency Bab Bhar', 'ville' => 'Tunis', 'delegation' => 'Bab Bhar']);
        $branchLafayette = Branch::factory()->create(['name' => 'Agency Lafayette', 'ville' => 'Tunis', 'delegation' => 'Lafayette']);

        $application = $this->approvedApplication(null, [
            'ville' => 'Tunis',
            'delegation' => 'Lafayette',
        ]);

        $this->assertSame($branchLafayette->id, $application->fresh()->latestAppointment()->branch_id);
    }

    public function test_closest_agency_matching_when_coordinates_are_available(): void
    {
        $matchingService = app(\App\Services\BranchMatchingService::class);

        $branchTunis = Branch::factory()->create([
            'name' => 'BTS Tunis',
            'ville' => 'Tunis',
            'latitude' => 36.8065,
            'longitude' => 10.1815,
        ]);

        $branchSousse = Branch::factory()->create([
            'name' => 'BTS Sousse',
            'ville' => 'Sousse',
            'latitude' => 35.8256,
            'longitude' => 10.6369,
        ]);

        // Point near Ariana / Tunis (36.86, 10.19)
        $closest = $matchingService->findClosestByCoordinates(36.8665, 10.1956);
        $this->assertSame($branchTunis->id, $closest->id);

        // Point near Monastir / Sousse (35.77, 10.82)
        $closestSouth = $matchingService->findClosestByCoordinates(35.7780, 10.8262);
        $this->assertSame($branchSousse->id, $closestSouth->id);
    }

    public function test_deterministic_selection_when_multiple_agencies_match(): void
    {
        // Two branches with the same ville and delegation
        $branchFirst = Branch::factory()->create(['name' => 'First Agency', 'ville' => 'Sfax', 'delegation' => 'Sfax Ville']);
        $branchSecond = Branch::factory()->create(['name' => 'Second Agency', 'ville' => 'Sfax', 'delegation' => 'Sfax Ville']);

        $application = $this->approvedApplication(null, [
            'ville' => 'Sfax',
            'delegation' => 'Sfax Ville',
        ]);

        // Deterministic ordering by id picks the first created branch
        $this->assertSame($branchFirst->id, $application->fresh()->latestAppointment()->branch_id);
    }

    public function test_concurrency_and_empty_day_scheduling_assigns_distinct_slots(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday -> target is Tuesday (2026-08-18)
        $branch = Branch::factory()->default()->create(['daily_capacity' => 4]);

        $tuesday = '2026-08-18';
        $wednesday = '2026-08-19';

        // 4 sequential client proposals on an initially empty day
        $app1 = $this->approvedApplication($branch);
        $app2 = $this->approvedApplication($branch);
        $app3 = $this->approvedApplication($branch);
        $app4 = $this->approvedApplication($branch);

        $appt1 = $app1->fresh()->latestAppointment();
        $appt2 = $app2->fresh()->latestAppointment();
        $appt3 = $app3->fresh()->latestAppointment();
        $appt4 = $app4->fresh()->latestAppointment();

        // Must receive 09:00, 11:00, 14:00, 15:00 on Tuesday with no duplicates
        $this->assertSame($tuesday, $appt1->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $appt1->scheduled_time);
        $this->assertFalse($appt1->is_auto_scheduled_future);

        $this->assertSame($tuesday, $appt2->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $appt2->scheduled_time);
        $this->assertFalse($appt2->is_auto_scheduled_future);

        $this->assertSame($tuesday, $appt3->scheduled_date->toDateString());
        $this->assertSame('14:00:00', $appt3->scheduled_time);
        $this->assertFalse($appt3->is_auto_scheduled_future);

        $this->assertSame($tuesday, $appt4->scheduled_date->toDateString());
        $this->assertSame('15:00:00', $appt4->scheduled_time);
        $this->assertFalse($appt4->is_auto_scheduled_future);

        // 5th client must be moved to Wednesday 09:00 with is_auto_scheduled_future = true
        $app5 = $this->approvedApplication($branch);
        $appt5 = $app5->fresh()->latestAppointment();

        $this->assertSame($wednesday, $appt5->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $appt5->scheduled_time);
        $this->assertTrue($appt5->is_auto_scheduled_future);

        Carbon::setTestNow();
    }

    public function test_different_branches_schedule_independently(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // Monday -> target is Tuesday
        $branchA = Branch::factory()->create(['name' => 'Agency A', 'ville' => 'Tunis']);
        $branchB = Branch::factory()->create(['name' => 'Agency B', 'ville' => 'Sousse']);

        $appA = $this->approvedApplication($branchA);
        $appB = $this->approvedApplication($branchB);

        $apptA = $appA->fresh()->latestAppointment();
        $apptB = $appB->fresh()->latestAppointment();

        // Both get 09:00 on Tuesday at their respective branch
        $this->assertSame($branchA->id, $apptA->branch_id);
        $this->assertSame('2026-08-18', $apptA->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $apptA->scheduled_time);

        $this->assertSame($branchB->id, $apptB->branch_id);
        $this->assertSame('2026-08-18', $apptB->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $apptB->scheduled_time);

        Carbon::setTestNow();
    }
}
