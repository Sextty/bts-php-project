<?php

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production-readiness audit: branch isolation for staff users.
 *
 * Verifies that after a full customer validation-2 flow (which auto-submits and routes the
 * application to a branch via BranchMatchingService), branch-restricted staff only see
 * applications routed to their own branch, while admins and unassigned staff see everything.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private StaffUser $staffA;
    private StaffUser $staffB;
    private StaffUser $admin;
    private StaffUser $unassignedStaff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');

        // Branch A: Tunis (default), delegation = 'Bab Bhar'.
        $this->branchA = Branch::factory()->default()->create(['ville' => 'Tunis', 'delegation' => 'Bab Bhar']);

        // Branch B: Sfax, delegation = 'Sfax Centre' — must be different from Branch A's
        // delegation so BranchMatchingService routes Sfax-ville apps here instead of
        // matching Branch A's delegation first.
        $this->branchB = Branch::factory()->create(['ville' => 'Sfax', 'delegation' => 'Sfax Centre']);

        // Branch-restricted staff.
        $this->staffA = StaffUser::factory()->forBranch($this->branchA)->create();
        $this->staffB = StaffUser::factory()->forBranch($this->branchB)->create();

        // Unrestricted staff — no branch assigned.
        $this->unassignedStaff = StaffUser::factory()->create();

        // Admin — never branch-restricted.
        $this->admin = StaffUser::factory()->admin()->create();

        // Customer for creating applications.
        $this->customer = User::factory()->create();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function actAsCustomer(): void
    {
        Sanctum::actingAs($this->customer, ['*']);
    }

    private function actAsStaff(StaffUser $staff): void
    {
        Sanctum::actingAs($staff, ['*']);
    }

    /**
     * Fake the Gemini AI so validation-1 does not hit the real API.
     */
    private function fakeGemini(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'is_valid' => true,
                    'confidence' => 'high',
                    'comment' => 'OK',
                    'extracted_fields' => [],
                    'mismatches' => [],
                ])]]]],
            ],
        ], 200)]);
    }

    /**
     * Create and fully submit a credit application with the given project ville/delegation.
     * Goes through: create → client → credit → project → documents → validation-1 → validation-2
     * (which auto-submits and routes to the matching branch).
     */
    private function createAndSubmitApplication(string $ville, string $delegation): CreditApplication
    {
        $this->actAsCustomer();

        $id = $this->postJson('/api/applications')->json('data.application.id');
        $application = CreditApplication::findOrFail($id);

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
            'adresse' => '12 Rue de la République', 'ville' => $ville, 'code_postal' => '1000',
            'activite' => 'Commerce', 'description' => "Extension d'un commerce existant.",
            'delegation' => $delegation, 'localisation' => 'Centre-ville', 'cout' => 20000,
            'investissement_personnel' => 5000, 'financement' => 15000, 'revenus' => 3000, 'depenses' => 1500,
        ]);

        $this->postJson("/api/applications/{$id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);

        $this->postJson("/api/applications/{$id}/validation-1");
        $this->postJson("/api/applications/{$id}/validation-2");

        return $application->fresh();
    }

    // ------------------------------------------------------------------
    // Core branch isolation tests
    // ------------------------------------------------------------------

    public function test_validation_2_auto_submits_and_assigns_branch(): void
    {
        $this->fakeGemini();

        $application = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');

        $this->assertSame(CreditApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame($this->branchA->id, $application->branch_id);
        $this->assertNotNull($application->submitted_at);
    }

    public function test_branch_a_staff_sees_their_own_branch_application(): void
    {
        $this->fakeGemini();

        $application = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');

        $this->actAsStaff($this->staffA);

        // List: exactly 1 application (their branch's).
        $response = $this->getJson('/api/staff/applications');
        $response->assertOk()->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.applications.0.id', $application->id);

        // Show: full details accessible.
        $this->getJson("/api/staff/applications/{$application->id}")->assertOk();
    }

    public function test_branch_b_staff_cannot_see_branch_a_application(): void
    {
        $this->fakeGemini();

        $application = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');

        $this->actAsStaff($this->staffB);

        // List: 0 applications (their branch has none).
        $this->getJson('/api/staff/applications')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        // Show: 403, not 404 (must not leak existence).
        $this->getJson("/api/staff/applications/{$application->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Actions: also 403.
        $this->postJson("/api/staff/applications/{$application->id}/approve")
            ->assertForbidden();
        $this->postJson("/api/staff/applications/{$application->id}/reject", ['reason' => 'Nope'])
            ->assertForbidden();
    }

    public function test_admin_sees_all_branch_applications(): void
    {
        $this->fakeGemini();

        $appA = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');
        $appB = $this->createAndSubmitApplication('Sfax', 'Sfax Centre');

        $this->actAsStaff($this->admin);

        // Admin sees both.
        $this->getJson('/api/staff/applications')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson("/api/staff/applications/{$appA->id}")->assertOk();
        $this->getJson("/api/staff/applications/{$appB->id}")->assertOk();
    }

    public function test_unassigned_staff_sees_all_branch_applications(): void
    {
        $this->fakeGemini();

        $appA = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');
        $appB = $this->createAndSubmitApplication('Sfax', 'Sfax Centre');

        $this->actAsStaff($this->unassignedStaff);

        // Unassigned staff see both branches.
        $this->getJson('/api/staff/applications')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson("/api/staff/applications/{$appA->id}")->assertOk();
        $this->getJson("/api/staff/applications/{$appB->id}")->assertOk();
    }

    public function test_branch_b_staff_sees_only_their_own_when_both_branches_have_apps(): void
    {
        $this->fakeGemini();

        $appA = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');
        $appB = $this->createAndSubmitApplication('Sfax', 'Sfax Centre');

        $this->assertNotSame($appA->branch_id, $appB->branch_id);

        $this->actAsStaff($this->staffB);

        // List: only Branch B's application.
        $response = $this->getJson('/api/staff/applications');
        $response->assertOk()->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.applications.0.id', $appB->id);

        // Show: Branch B's is accessible, Branch A's is forbidden.
        $this->getJson("/api/staff/applications/{$appB->id}")->assertOk();
        $this->getJson("/api/staff/applications/{$appA->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Branch isolation on documents
    // ------------------------------------------------------------------

    public function test_branch_isolation_on_document_download(): void
    {
        $this->fakeGemini();

        $appA = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');
        $appB = $this->createAndSubmitApplication('Sfax', 'Sfax Centre');

        $docA = $appA->documents->first();
        $docB = $appB->documents->first();

        $this->assertNotSame($appA->branch_id, $appB->branch_id);

        $this->actAsStaff($this->staffA);

        // Staff A can download their branch's document.
        $this->getJson("/api/staff/applications/{$appA->id}/documents/{$docA->id}")
            ->assertOk();

        // Staff A cannot download the other branch's document.
        $this->getJson("/api/staff/applications/{$appB->id}/documents/{$docB->id}")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Branch isolation on activity trail
    // ------------------------------------------------------------------

    public function test_branch_isolation_on_activity_trail(): void
    {
        $this->fakeGemini();

        $appA = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');
        $appB = $this->createAndSubmitApplication('Sfax', 'Sfax Centre');

        $this->assertNotSame($appA->branch_id, $appB->branch_id);

        $this->actAsStaff($this->staffA);

        // Staff A sees activity for their branch's app.
        $this->assertGreaterThan(
            0,
            $this->getJson("/api/staff/activity?application_id={$appA->id}")->json('data.meta.total'),
        );

        // Staff A sees no activity for the other branch's app.
        $this->getJson("/api/staff/activity?application_id={$appB->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    // ------------------------------------------------------------------
    // Admin rejection still works after staff approval (cross-branch)
    // ------------------------------------------------------------------

    public function test_admin_can_act_on_any_branch_application(): void
    {
        $this->fakeGemini();

        $app = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');

        // Set to STAFF_APPROVED directly (staff approve now chains past this to APPOINTMENT_PROPOSED,
        // but admin-reject is still valid from STAFF_APPROVED).
        $app->update(['status' => CreditApplication::STATUS_STAFF_APPROVED]);

        // Admin can see and reject (admin is not branch-restricted).
        $this->actAsStaff($this->admin);
        $this->getJson("/api/staff/applications/{$app->id}")->assertOk();
        $this->postJson("/api/staff/applications/{$app->id}/admin-reject", ['reason' => 'Changed mind'])
            ->assertOk();
    }

    public function test_branch_restricted_staff_cannot_approve_across_branch(): void
    {
        $this->fakeGemini();

        $app = $this->createAndSubmitApplication('Tunis', 'Bab Bhar');

        $this->actAsStaff($this->staffB);
        $this->postJson("/api/staff/applications/{$app->id}/approve")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Factory usage test: forBranch() creates branch-restricted staff
    // ------------------------------------------------------------------

    public function test_factory_for_branch_creates_restricted_staff(): void
    {
        $staff = StaffUser::factory()->forBranch($this->branchA)->create();

        $this->assertSame($this->branchA->id, $staff->branch_id);
        $this->assertTrue($staff->isBranchRestricted());
    }

    public function test_factory_admin_is_not_branch_restricted_even_with_branch(): void
    {
        $admin = StaffUser::factory()->admin()->forBranch($this->branchA)->create();

        $this->assertSame($this->branchA->id, $admin->branch_id);
        // Admin is a superuser, so branch_id is irrelevant.
        $this->assertFalse($admin->isBranchRestricted());
    }
}
