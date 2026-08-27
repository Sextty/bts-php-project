<?php

namespace Tests\Feature\Documents;

use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffDocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->customer = User::factory()->create();
    }

    private function as(mixed $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function staff(string $role = 'staff', ?Branch $branch = null): StaffUser
    {
        return StaffUser::factory()->create([
            'role' => $role,
            'branch_id' => $branch?->id,
        ]);
    }

    /** Drives a fresh application through the customer flow to SUBMITTED, routed to $ville's branch. */
    private function submittedApplication(string $ville = 'Tunis'): CreditApplication
    {
        $this->as($this->customer);

        $application = CreditApplication::findOrFail($this->postJson('/api/applications')->json('data.application.id'));

        $this->putJson("/api/applications/{$application->id}/client", [
            'code_client' => 'CL-0001', 'civilite' => 'M', 'nom' => 'Ben Salah', 'prenom' => 'Karim',
            'nom_epoux' => null, 'deuxieme_prenom' => null, 'date_naissance' => '1990-05-12',
            'lieu_naissance' => 'Tunis', 'pays_naissance' => 'Tunisie', 'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie', 'etat_civil' => 'célibataire', 'nombre_enfants' => 0,
            'type_pid' => 'CIN', 'numero_pid' => '12345678', 'date_delivrance_pid' => '2015-01-10',
            'lieu_delivrance_pid' => 'Tunis', 'numero_carte_sejour' => null, 'profession' => 'Ingénieur',
            'date_entree_relation' => '2020-01-01',
        ]);

        $this->putJson("/api/applications/{$application->id}/credit", [
            'identifiant_personne' => 'CL-0001', 'nom_ou_rs' => 'Ben Salah', 'prenom_ou_dc' => 'Karim',
            'type_pid' => 'CIN', 'numero_pid' => '12345678', 'origine' => 'agence',
            'date_depot' => '2026-01-05', 'date_reception' => '2026-01-06', 'type_demande' => 'crédit personnel',
            'code_devise' => 'TND', 'montant_global_sollicite' => 15000, 'nombre_credits_sollicites' => 1,
            'unite_depot' => 'agence centrale',
        ]);

        $this->putJson("/api/applications/{$application->id}/project", [
            'code_projet' => 'PR-0001', 'identifiant_personne' => 'CL-0001', 'nom_ou_rs' => 'Ben Salah',
            'prenom_ou_dc' => 'Karim', 'type_projet' => 'extension', 'objet' => 'Achat de matériel',
            'adresse' => '12 Rue de la République', 'ville' => $ville, 'code_postal' => '1000',
            'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
            'delegation' => 'Bab Bhar', 'localisation' => 'Centre-ville', 'cout' => 20000,
            'investissement_personnel' => 5000, 'financement' => 15000, 'revenus' => 3000, 'depenses' => 1500,
        ]);

        $response = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 fake pdf payload'),
        ]);
        $application->setRelation('documents', collect([Document::findOrFail($response->json('data.document.id'))]));

        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        return $application->fresh();
    }

    public function test_branch_assigned_staff_can_download_documents_of_their_branch(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $application = $this->submittedApplication('Tunis');
        $document = $application->documents->first();

        $this->as($this->staff('staff', $tunis));

        $this->get("/api/staff/applications/{$application->id}/documents/{$document->id}")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=cin.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_other_branch_staff_are_denied_a_download(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);
        $application = $this->submittedApplication('Tunis');
        $document = $application->documents->first();

        $this->as($this->staff('staff', $sfax));

        $this->get("/api/staff/applications/{$application->id}/documents/{$document->id}")
            ->assertStatus(403);
    }

    public function test_unassigned_staff_cannot_download_branch_documents(): void
    {
        Branch::factory()->default()->create(['ville' => 'Tunis']);
        $application = $this->submittedApplication('Tunis');
        $document = $application->documents->first();

        $this->as($this->staff('staff'));

        $this->get("/api/staff/applications/{$application->id}/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_staff_cannot_download_documents_of_a_draft_application(): void
    {
        Branch::factory()->default()->create(['ville' => 'Tunis']);
        $this->as($this->customer);

        $application = CreditApplication::findOrFail($this->postJson('/api/applications')->json('data.application.id'));
        $response = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 fake pdf payload'),
        ]);
        $document = Document::findOrFail($response->json('data.document.id'));

        $this->as($this->staff('staff'));

        $this->get("/api/staff/applications/{$application->id}/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_staff_download_route_rejects_customer_tokens(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $application = $this->submittedApplication('Tunis');
        $document = $application->documents->first();

        // Customer acting against the staff portal.
        $this->as($this->customer);

        $this->get("/api/staff/applications/{$application->id}/documents/{$document->id}")
            ->assertStatus(403);
    }
}
