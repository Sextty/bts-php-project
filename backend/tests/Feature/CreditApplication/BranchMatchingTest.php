<?php

namespace Tests\Feature\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\Project;
use App\Models\User;
use App\Services\BranchMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchMatchingTest extends TestCase
{
    use RefreshDatabase;

    private BranchMatchingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BranchMatchingService();
        $this->user = User::factory()->create();
    }

    private function createApplicationWithProject(array $projectAttributes = []): CreditApplication
    {
        $application = CreditApplication::factory()->create(['user_id' => $this->user->id]);
        $application->project()->create(array_merge([
            'nom_ou_rs' => 'Societe Test',
            'prenom_ou_dc' => 'Projet Test',
            'type_projet' => 'Creation',
            'objet' => 'Test',
            'adresse' => 'Rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'activite' => 'Services',
            'description' => 'Description test',
            'delegation' => 'Bab Bhar',
            'localisation' => 'Urbain',
            'cout' => 10000,
            'investissement_personnel' => 2000,
            'financement' => 8000,
            'revenus' => 3000,
            'depenses' => 1500,
        ], $projectAttributes));

        return $application->fresh()->load('project');
    }

    /** Test 1: Priority 1 - Haversine GPS matching selects nearest branch */
    public function test_priority_1_nearest_branch_by_coordinates_is_selected(): void
    {
        // Project located in Tunis Centre: 36.8000, 10.1800
        $application = $this->createApplicationWithProject([
            'latitude' => 36.8000,
            'longitude' => 10.1800,
            'ville' => 'Sousse', // deliberately mismatched ville to prove GPS priority
            'delegation' => 'Sousse Ville',
        ]);

        // Branch 1: Tunis Centre (~0.5km away)
        $branchClose = Branch::factory()->create([
            'name' => 'BTS Tunis Centre',
            'ville' => 'Tunis',
            'delegation' => 'Bab Bhar',
            'latitude' => 36.8020,
            'longitude' => 10.1820,
            'is_default' => false,
        ]);

        // Branch 2: Sfax (~270km away)
        Branch::factory()->create([
            'name' => 'BTS Sfax',
            'ville' => 'Sfax',
            'delegation' => 'Sfax Ville',
            'latitude' => 34.7400,
            'longitude' => 10.7600,
            'is_default' => true,
        ]);

        $matched = $this->service->findForApplication($application);
        $this->assertSame($branchClose->id, $matched->id);
    }

    /** Test 2: Priority 2 - Delegation match when no coordinates exist */
    public function test_priority_2_delegation_match_used_when_no_coordinates(): void
    {
        $application = $this->createApplicationWithProject([
            'latitude' => null,
            'longitude' => null,
            'ville' => 'Tunis',
            'delegation' => 'La Marsa',
        ]);

        Branch::factory()->create([
            'name' => 'BTS Bab Bhar',
            'ville' => 'Tunis',
            'delegation' => 'Bab Bhar',
            'is_default' => false,
        ]);

        $branchMarsa = Branch::factory()->create([
            'name' => 'BTS La Marsa',
            'ville' => 'Tunis',
            'delegation' => 'La Marsa',
            'is_default' => false,
        ]);

        Branch::factory()->default()->create(['name' => 'BTS Siege']);

        $matched = $this->service->findForApplication($application);
        $this->assertSame($branchMarsa->id, $matched->id);
    }

    /** Test 3: Priority 3 - Governorate (ville) match when delegation not matched */
    public function test_priority_3_governorate_match_used_when_no_delegation_match(): void
    {
        $application = $this->createApplicationWithProject([
            'latitude' => null,
            'longitude' => null,
            'ville' => 'Bizerte',
            'delegation' => 'Menzel Bourguiba', // no branch in this delegation
        ]);

        $branchBizerteVille = Branch::factory()->create([
            'name' => 'BTS Bizerte Centre',
            'ville' => 'Bizerte',
            'delegation' => 'Bizerte Nord',
            'is_default' => false,
        ]);

        Branch::factory()->default()->create(['name' => 'BTS Siege Tunis']);

        $matched = $this->service->findForApplication($application);
        $this->assertSame($branchBizerteVille->id, $matched->id);
    }

    /** Test 4: Priority 4 - Fallback to default branch when no geographic matches */
    public function test_priority_4_default_branch_used_when_no_geographic_matches(): void
    {
        $application = $this->createApplicationWithProject([
            'latitude' => null,
            'longitude' => null,
            'ville' => 'Tozeur',
            'delegation' => 'Degache',
        ]);

        Branch::factory()->create([
            'name' => 'BTS Sousse',
            'ville' => 'Sousse',
            'delegation' => 'Sousse Ville',
            'is_default' => false,
        ]);

        $defaultBranch = Branch::factory()->default()->create([
            'name' => 'BTS Agence Centrale',
            'ville' => 'Tunis',
        ]);

        $matched = $this->service->findForApplication($application);
        $this->assertSame($defaultBranch->id, $matched->id);
    }

    /** Test 5: Priority 5 - Throws NO_BRANCH_AVAILABLE if no branch exists */
    public function test_priority_5_throws_no_branch_available_when_no_branches_in_db(): void
    {
        $application = $this->createApplicationWithProject([
            'latitude' => null,
            'longitude' => null,
            'ville' => 'Nabeul',
            'delegation' => 'Hammamet',
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->service->findForApplication($application);
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::NoBranchAvailable, $e->error);
            throw $e;
        }
    }
}
