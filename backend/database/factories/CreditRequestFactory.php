<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\CreditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditRequest>
 */
class CreditRequestFactory extends Factory
{
    protected $model = CreditRequest::class;

    public function definition(): array
    {
        $depositedAt = $this->faker->dateTimeBetween('-3 years', '-1 day');
        $receivedAt = (clone $depositedAt)->modify('+'.$this->faker->numberBetween(0, 5).' days');
        if ($receivedAt > now()) {
            $receivedAt = now()->toDateTime();
        }

        $totalMillimes = $this->faker->numberBetween(3_000_000, 150_000_000);
        $breakdown = $this->breakdown($totalMillimes);
        $identity = sprintf('CL-%d-%06d', $depositedAt->format('Y'), $this->faker->numberBetween(1, 999999));

        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_STEP_2_COMPLETED,
            ]),
            'n_demande' => sprintf(
                'CR-%s-%06d',
                $depositedAt->format('Y'),
                $this->faker->unique()->numberBetween(1, 999999),
            ),
            'identifiant_personne' => $identity,
            'nom_ou_rs' => $this->faker->lastName(),
            'prenom_ou_dc' => $this->faker->firstName(),
            'type_pid' => 'CIN',
            'numero_pid' => $this->faker->numerify('########'),
            'origine' => $this->faker->randomElement(['agence', 'portail web', 'partenaire']),
            'date_depot' => $depositedAt,
            'date_reception' => $receivedAt,
            'type_demande' => $this->faker->randomElement(['création', 'extension', 'renouvellement']),
            'code_devise' => 'TND',
            'montant_global_sollicite' => $this->amount($totalMillimes),
            'montant_eqp' => $this->amount($breakdown['eqp']),
            'montant_fdr' => $this->amount($breakdown['fdr']),
            'montant_amg' => $this->amount($breakdown['amg']),
            'montant_chp' => $this->amount($breakdown['chp']),
            'nombre_credits_sollicites' => $this->faker->numberBetween(1, 3),
            'unite_depot' => 'Unité synthétique '.$this->faker->numberBetween(1, 999),
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
                'type_pid' => $client?->type_pid,
                'numero_pid' => $client?->numero_pid,
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
            'type_pid' => $client->type_pid,
            'numero_pid' => $client->numero_pid,
        ]);
    }

    /** @return array{eqp: int, fdr: int, amg: int, chp: int} */
    private function breakdown(int $total): array
    {
        $keys = ['eqp', 'fdr', 'amg', 'chp'];
        $active = $this->faker->randomElements($keys, $this->faker->numberBetween(1, 3));
        $weights = [];

        foreach ($active as $key) {
            $weights[$key] = $this->faker->numberBetween(1, 10);
        }

        $result = array_fill_keys($keys, 0);
        $remaining = $total;
        $weightTotal = array_sum($weights);
        $lastKey = array_key_last($weights);

        foreach ($weights as $key => $weight) {
            $value = $key === $lastKey
                ? $remaining
                : intdiv($total * $weight, $weightTotal);
            $result[$key] = $value;
            $remaining -= $value;
        }

        return $result;
    }

    private function amount(int $millimes): string
    {
        return number_format($millimes / 1000, 3, '.', '');
    }
}
