<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $governorate = $this->faker->randomElement([
            'Tunis', 'Ariana', 'Ben Arous', 'Manouba', 'Nabeul', 'Zaghouan',
            'Bizerte', 'Béja', 'Jendouba', 'Le Kef', 'Siliana', 'Sousse',
            'Monastir', 'Mahdia', 'Sfax', 'Kairouan', 'Kasserine', 'Sidi Bouzid',
            'Gabès', 'Médenine', 'Tataouine', 'Gafsa', 'Tozeur', 'Kébili',
        ]);
        $costMillimes = $this->faker->numberBetween(5_000_000, 250_000_000);
        $personalMillimes = intdiv(
            $costMillimes * $this->faker->numberBetween(5, 40),
            100,
        );
        $revenueMillimes = $this->faker->numberBetween(800_000, 15_000_000);
        $expenseMillimes = intdiv(
            $revenueMillimes * $this->faker->numberBetween(20, 80),
            100,
        );

        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_READY_FOR_VALIDATION_1,
            ]),
            'code_projet' => sprintf(
                'PJ-%d-%06d',
                now()->year,
                $this->faker->unique()->numberBetween(1, 999999),
            ),
            'identifiant_personne' => sprintf(
                'CL-%d-%06d',
                now()->year,
                $this->faker->numberBetween(1, 999999),
            ),
            'nom_ou_rs' => $this->faker->lastName(),
            'prenom_ou_dc' => $this->faker->firstName(),
            'type_projet' => $this->faker->randomElement(['création', 'extension', 'développement']),
            'objet' => $this->faker->sentence(6),
            'adresse' => $this->faker->streetAddress(),
            'ville' => $governorate,
            'code_postal' => (string) $this->faker->numberBetween(1000, 9299),
            'activite' => $this->faker->randomElement(['artisanat', 'agriculture', 'commerce', 'services', 'industrie']),
            'description' => $this->faker->paragraphs(2, true),
            'delegation' => $governorate.' Test '.$this->faker->numberBetween(1, 99),
            'localisation' => 'Zone synthétique '.$this->faker->numberBetween(1, 999),
            'latitude' => $this->faker->latitude(30.2, 37.5),
            'longitude' => $this->faker->longitude(7.5, 11.8),
            'cout' => $this->amount($costMillimes),
            'investissement_personnel' => $this->amount($personalMillimes),
            'financement' => $this->amount($costMillimes - $personalMillimes),
            'revenus' => $this->amount($revenueMillimes),
            'depenses' => $this->amount($expenseMillimes),
        ];
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(function (array $attributes) use ($application) {
            $client = $application->client;

            return array_filter([
                'credit_application_id' => $application->getKey(),
                'identifiant_personne' => $client?->code_client,
                'nom_ou_rs' => $client?->nom,
                'prenom_ou_dc' => $client?->prenom,
            ], fn ($value) => $value !== null);
        });
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $client->credit_application_id,
            'identifiant_personne' => $client->code_client,
            'nom_ou_rs' => $client->nom,
            'prenom_ou_dc' => $client->prenom,
        ]);
    }

    public function nearBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'ville' => $branch->ville,
            'delegation' => $branch->delegation,
            'localisation' => $branch->ville.', '.$branch->delegation,
            'latitude' => $branch->latitude !== null
                ? (float) $branch->latitude + $this->faker->randomFloat(5, -0.01, 0.01)
                : null,
            'longitude' => $branch->longitude !== null
                ? (float) $branch->longitude + $this->faker->randomFloat(5, -0.01, 0.01)
                : null,
        ]);
    }

    private function amount(int $millimes): string
    {
        return number_format($millimes / 1000, 3, '.', '');
    }
}
