<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\CreditApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $civilite = $this->faker->randomElement(['M', 'Mme', 'Mlle']);
        $firstName = $civilite === 'M'
            ? $this->faker->firstNameMale()
            : $this->faker->firstNameFemale();
        $lastName = $this->faker->lastName();
        $maritalStatus = $this->faker->randomElement([
            'célibataire',
            'célibataire',
            'marié',
            'marié',
            'divorcé',
            'veuf',
        ]);
        $identityType = $this->faker->randomElement(['CIN', 'CIN', 'CIN', 'Passeport', 'Carte de séjour']);
        $birthDate = $this->faker->dateTimeBetween('-68 years', '-20 years');
        $identityIssuedAfter = (clone $birthDate)->modify('+18 years');
        $identityIssuedAt = $this->faker->dateTimeBetween($identityIssuedAfter, '-1 day');

        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_STEP_1_COMPLETED,
            ]),
            'code_client' => sprintf(
                'CL-%d-%06d',
                now()->year,
                $this->faker->unique()->numberBetween(1, 999999),
            ),
            'civilite' => $civilite,
            'nom' => $lastName,
            'prenom' => $firstName,
            'nom_epoux' => $maritalStatus === 'marié' && $civilite !== 'M'
                ? $this->faker->lastName()
                : null,
            'deuxieme_prenom' => $this->faker->optional(0.25)->firstName(),
            'date_naissance' => $birthDate,
            'lieu_naissance' => $this->faker->city(),
            'pays_naissance' => 'Tunisie',
            'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie',
            'etat_civil' => $maritalStatus,
            'nombre_enfants' => $this->faker->randomElement([0, 0, 0, 1, 1, 2, 2, 3, 4]),
            'type_pid' => $identityType,
            'numero_pid' => match ($identityType) {
                'CIN' => $this->faker->numerify('########'),
                'Passeport' => strtoupper($this->faker->bothify('??#######')),
                default => strtoupper($this->faker->bothify('CS########')),
            },
            'date_delivrance_pid' => $identityIssuedAt,
            'lieu_delivrance_pid' => $this->faker->city(),
            'numero_carte_sejour' => $identityType === 'Carte de séjour'
                ? strtoupper($this->faker->bothify('CS########'))
                : null,
            'profession' => $this->faker->jobTitle(),
            'date_entree_relation' => $this->faker->dateTimeBetween('-15 years', 'now'),
        ];
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $application->getKey(),
        ]);
    }
}
