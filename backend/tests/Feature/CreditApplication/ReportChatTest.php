<?php

namespace Tests\Feature\CreditApplication;

use App\Events\ReportMessageSent;
use App\Models\Appointment;
use App\Models\AsyncOutboxEvent;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\DocumentSecurity\MalwareScanner;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
        config(['services.document_verification.provider' => 'gemini']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_valid' => true,
                    'confidence' => 'high',
                    'comment' => 'Synthetic document accepted for test.',
                    'extracted_fields' => [],
                    'mismatches' => [],
                ])]]]]],
            ], 200),
        ]);
        $this->customer = User::factory()->create();
        $this->staff = StaffUser::factory()->create();
    }

    /** Drives a fresh application to the four-change discussion escalation threshold. */
    private function lockedApplication(int $reschedules = Appointment::MAX_RESCHEDULES): CreditApplication
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
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);

        $this->postJson("/api/applications/{$id}/validation-1");
        $this->postJson("/api/applications/{$id}/validation-2");

        $application = CreditApplication::findOrFail($id);
        $this->staff->update(['branch_id' => $application->branch_id]);

        $admin = StaffUser::factory()->admin()->create();

        Sanctum::actingAs($this->staff, ['*']);
        $this->postJson("/api/staff/applications/{$id}/approve");

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/staff/applications/{$id}/admin-approve");

        Sanctum::actingAs($this->customer, ['*']);
        for ($i = 0; $i < $reschedules; $i++) {
            $this->postJson("/api/applications/{$id}/appointment/reject")->assertOk();
        }

        return $application->fresh();
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

        $message = ReportMessage::findOrFail($response->json('data.message.id'));
        $channels = (new ReportMessageSent($message))->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-application.{$application->id}.report", $channels[0]->name);
        $this->assertDatabaseHas('async_outbox_events', [
            'type' => 'report_message.broadcast',
            'aggregate_id' => $message->id,
        ]);

        $this->getJson("/api/applications/{$application->id}/report/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_appointment_discussion_opens_only_after_four_successful_reschedules(): void
    {
        $application = $this->lockedApplication(0);

        for ($count = 0; $count < Appointment::MAX_RESCHEDULES; $count++) {
            Sanctum::actingAs($this->customer, ['*']);
            $this->getJson("/api/applications/{$application->id}/report/messages")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'REPORT_NOT_OPEN');

            Sanctum::actingAs($this->staff, ['*']);
            $ids = collect($this->getJson('/api/staff/reports')->assertOk()->json('data.applications'))
                ->pluck('id');
            $this->assertFalse($ids->contains($application->id));

            Sanctum::actingAs($this->customer, ['*']);
            $this->postJson("/api/applications/{$application->id}/appointment/reject")
                ->assertOk()
                ->assertJsonPath('data.appointment.reschedule_count', $count + 1)
                ->assertJsonPath(
                    'data.appointment.remaining_reschedules',
                    Appointment::MAX_RESCHEDULES - $count - 1
                );
        }

        $this->getJson("/api/applications/{$application->id}/report/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data.messages');

        Sanctum::actingAs($this->staff, ['*']);
        $ids = collect($this->getJson('/api/staff/reports')->assertOk()->json('data.applications'))
            ->pluck('id');
        $this->assertTrue($ids->contains($application->id));
    }

    public function test_confirmed_appointment_without_messages_is_not_a_discussion_case(): void
    {
        $application = $this->lockedApplication(0);

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$application->id}/appointment/accept")->assertOk();

        $this->getJson("/api/applications/{$application->id}/report/messages")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'REPORT_NOT_OPEN');

        Sanctum::actingAs($this->staff, ['*']);
        $ids = collect($this->getJson('/api/staff/reports')->assertOk()->json('data.applications'))
            ->pluck('id');
        $this->assertFalse($ids->contains($application->id));
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
            Sanctum::actingAs($this->customer, ['*']);
            $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => "customer message {$i}"])
                ->assertCreated();

            Sanctum::actingAs($this->staff, ['*']);
            $this->postJson("/api/staff/reports/{$application->id}/messages", ['body' => "staff reply {$i}"])
                ->assertCreated();
        }

        $this->getJson("/api/staff/reports/{$application->id}/messages")
            ->assertOk()
            ->assertJsonCount(10, 'data.messages');
    }

    public function test_customer_cannot_send_consecutive_messages_until_staff_replies(): void
    {
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'first customer message'])
            ->assertCreated();

        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'second consecutive message'])
            ->assertStatus(422);

        Sanctum::actingAs($this->staff, ['*']);
        $this->postJson("/api/staff/reports/{$application->id}/messages", ['body' => 'staff reply'])
            ->assertCreated();

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'customer can speak again'])
            ->assertCreated();
    }

    public function test_staff_or_system_message_does_not_block_the_first_customer_message(): void
    {
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();
        $application->reportMessages()->create([
            'sender_type' => ReportMessage::SENDER_STAFF,
            'staff_user_id' => $this->staff->id,
            'body' => '📅 Le dossier est maintenant disponible pour échange.',
        ]);

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$application->id}/report/messages", [
            'body' => 'Premier vrai message du client.',
        ])->assertCreated();
    }

    public function test_only_assigned_branch_sees_fresh_eligible_case_among_historical_cases(): void
    {
        $application = $this->lockedApplication();

        CreditApplication::factory()->count(30)->create([
            'branch_id' => $application->branch_id,
            'status' => CreditApplication::STATUS_CANCELLED,
            'updated_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->staff, ['*']);
        $response = $this->getJson('/api/staff/reports')->assertOk();
        $this->assertContains($application->id, collect($response->json('data.applications'))->pluck('id')->all());

        $otherBranch = Branch::factory()->create();
        $otherStaff = StaffUser::factory()->create(['branch_id' => $otherBranch->id]);
        Sanctum::actingAs($otherStaff, ['*']);
        $otherResponse = $this->getJson('/api/staff/reports')->assertOk();
        $this->assertNotContains($application->id, collect($otherResponse->json('data.applications'))->pluck('id')->all());
        $this->getJson("/api/staff/reports/{$application->id}/messages")->assertForbidden();
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

    public function test_message_sending_remains_durable_while_realtime_worker_is_offline(): void
    {
        $application = $this->lockedApplication();
        config(['queue.default' => 'database']);

        $response = $this->postJson("/api/applications/{$application->id}/report/messages", [
            'body' => 'Message during Reverb outage',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.message.body', 'Message during Reverb outage')
            ->assertJsonPath('data.message.sender_type', 'customer');

        $this->assertDatabaseHas('report_messages', [
            'credit_application_id' => $application->id,
            'body' => 'Message during Reverb outage',
        ]);
        $this->assertDatabaseHas('async_outbox_events', [
            'type' => 'report_message.broadcast',
            'aggregate_id' => $response->json('data.message.id'),
            'status' => AsyncOutboxEvent::STATUS_PENDING,
        ]);
    }

    public function test_customer_can_send_file_attachment_without_body(): void
    {
        Storage::fake('local');
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();

        Sanctum::actingAs($this->customer, ['*']);

        $file = $this->fakePdf('devis_commercial.pdf', 1024);

        $response = $this->post("/api/applications/{$application->id}/report/messages", [
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.message.sender_type', 'customer')
            ->assertJsonPath('data.message.has_attachment', true)
            ->assertJsonPath('data.message.attachment_name', 'devis_commercial.pdf');

        $messageId = $response->json('data.message.id');

        $downloadResponse = $this->get("/api/applications/{$application->id}/report/messages/{$messageId}/attachment");
        $downloadResponse->assertOk();
    }

    public function test_infected_report_attachment_is_rejected_before_storage(): void
    {
        $application = $this->lockedApplication();

        $scanner = $this->mock(MalwareScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn([
            'status' => 'infected',
            'signature' => 'Synthetic.Test.Signature',
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $this->post("/api/applications/{$application->id}/report/messages", [
            'file' => $this->fakePdf('infected.pdf', 512),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENT_MALWARE_DETECTED');

        $this->assertDatabaseMissing('report_messages', [
            'credit_application_id' => $application->id,
            'attachment_name' => 'infected.pdf',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->customer->id,
            'credit_application_id' => $application->id,
            'action' => 'report_attachment.malware_rejected',
        ]);
        Storage::disk('documents')->assertMissing("applications/{$application->id}");
    }

    public function test_staff_can_send_file_attachment(): void
    {
        Storage::fake('local');
        Event::fake([ReportMessageSent::class]);
        $application = $this->lockedApplication();

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/applications/{$application->id}/report/messages", ['body' => 'Customer message'])->assertCreated();

        Sanctum::actingAs($this->staff, ['*']);

        $file = UploadedFile::fake()->image('facture.png');

        $response = $this->post("/api/staff/reports/{$application->id}/messages", [
            'body' => 'Voici votre document',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.message.sender_type', 'staff')
            ->assertJsonPath('data.message.has_attachment', true)
            ->assertJsonPath('data.message.attachment_name', 'facture.png');

        $messageId = $response->json('data.message.id');

        $downloadResponse = $this->get("/api/staff/reports/{$application->id}/messages/{$messageId}/attachment");
        $downloadResponse->assertOk();
    }
}
