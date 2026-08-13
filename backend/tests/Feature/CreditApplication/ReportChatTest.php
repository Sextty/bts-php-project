<?php

namespace Tests\Feature\CreditApplication;

use App\Events\ReportMessageSent;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportChatTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private StaffUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->customer = User::factory()->create();
        $this->staff = StaffUser::factory()->create();
    }

    /** Drives a fresh application all the way to APPOINTMENT_LOCKED (3 rejections). */
    private function lockedApplication(): CreditApplication
    {
        Branch::factory()->default()->create();

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

        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($this->staff, ['*']);
        $this->postJson("/api/staff/applications/{$id}/approve");

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$id}/admin-approve");

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$id}/appointment/reject");
        $this->postJson("/api/applications/{$id}/appointment/reject");
        $this->postJson("/api/applications/{$id}/appointment/reject");

        return CreditApplication::findOrFail($id);
    }

    public function test_customer_cannot_message_before_the_application_is_locked(): void
    {
        Branch::factory()->default()->create();
        Sanctum::actingAs($this->customer, ['*']);
        $id = $this->postJson('/api/applications')->json('data.application.id');

        $this->postJson("/api/applications/{$id}/report/messages", ['body' => 'Hello?'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'REPORT_NOT_OPEN');
    }

    public function test_customer_can_send_the_first_message_once_locked(): void
    {
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();

        $response = $this->postJson("/api/applications/{$application->id}/report/messages", [
            'body' => 'None of the proposed times work for me, can someone call me?',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.message.sender_type', 'customer')
            ->assertJsonPath('data.message.body', 'None of the proposed times work for me, can someone call me?');

        Event::assertDispatched(ReportMessageSent::class);

        $this->getJson("/api/applications/{$application->id}/report/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_staff_sees_locked_applications_and_can_reply(): void
    {
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();
        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'Please call me back.']);

        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson('/api/staff/reports')
            ->assertOk()
            ->assertJsonPath('data.applications.0.id', $application->id);

        $response = $this->postJson("/api/staff/reports/{$application->id}/messages", [
            'body' => 'We will call you tomorrow morning.',
        ]);

        $response->assertCreated()->assertJsonPath('data.message.sender_type', 'staff');

        $this->getJson("/api/staff/reports/{$application->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_conversation_is_free_back_and_forth_with_no_cap(): void
    {
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => "customer message {$i}"])
                ->assertCreated();
        }

        Sanctum::actingAs($this->staff, ['*']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/staff/reports/{$application->id}/messages", ['body' => "staff reply {$i}"])
                ->assertCreated();
        }

        $this->getJson("/api/staff/reports/{$application->id}/messages")
            ->assertOk()
            ->assertJsonCount(10, 'data.messages');
    }

    public function test_another_customer_cannot_view_or_message_this_report(): void
    {
        $application = $this->lockedApplication();

        $other = User::factory()->create();
        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/applications/{$application->id}/report/messages")->assertStatus(403);
        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'hi'])->assertStatus(403);
    }

    public function test_a_customer_token_cannot_reach_staff_report_routes(): void
    {
        $application = $this->lockedApplication();

        $this->getJson('/api/staff/reports')->assertStatus(403);
        $this->getJson("/api/staff/reports/{$application->id}/messages")->assertStatus(403);
    }
}
