<?php

namespace Tests\Feature\CreditApplication;

use App\Models\CreditApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class CreditApplicationTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    protected function newApplication(): CreditApplication
    {
        $id = $this->postJson('/api/applications')->json('data.application.id');

        return CreditApplication::findOrFail($id);
    }

    protected function validClientPayload(): array
    {
        return [
            'code_client' => 'CL-0001',
            'civilite' => 'M',
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'nom_epoux' => null,
            'deuxieme_prenom' => null,
            'date_naissance' => '1990-05-12',
            'lieu_naissance' => 'Tunis',
            'pays_naissance' => 'Tunisie',
            'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie',
            'etat_civil' => 'célibataire',
            'nombre_enfants' => 0,
            'type_pid' => 'CIN',
            'numero_pid' => '12345678',
            'date_delivrance_pid' => '2015-01-10',
            'lieu_delivrance_pid' => 'Tunis',
            'numero_carte_sejour' => null,
            'profession' => 'Ingénieur',
            'date_entree_relation' => '2020-01-01',
        ];
    }

    protected function validCreditRequestPayload(): array
    {
        return [
            'nom_ou_rs' => 'Ben Salah',
            'prenom_ou_dc' => 'Karim',
            'origine' => 'agence',
            'date_depot' => '2026-01-05',
            'date_reception' => '2026-01-06',
            'type_demande' => 'crédit personnel',
            'code_devise' => 'TND',
            'montant_global_sollicite' => 15000,
            'nombre_credits_sollicites' => 1,
            'unite_depot' => 'agence centrale',
        ];
    }

    protected function validProjectPayload(): array
    {
        return [
            'nom_ou_rs' => 'Ben Salah',
            'prenom_ou_dc' => 'Karim',
            'type_projet' => 'extension',
            'objet' => 'Achat de matériel',
            'adresse' => '12 Rue de la République',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'activite' => 'Commerce',
            'description' => 'Extension d\'un commerce existant.',
            'delegation' => 'Bab Bhar',
            'localisation' => 'Centre-ville',
            'cout' => 20000,
            'investissement_personnel' => 5000,
            'financement' => 15000,
            'revenus' => 3000,
            'depenses' => 1500,
        ];
    }

    protected function completeStep1(CreditApplication $application): void
    {
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())->assertOk();
    }
}
