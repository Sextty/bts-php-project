<?php

namespace Tests\Unit\SyntheticData;

use App\Models\CreditApplication;
use App\Services\SyntheticData\GenerationOptions;
use App\Services\SyntheticData\SyntheticRecordFactory;
use App\Services\SyntheticData\SyntheticStatePath;
use App\Services\SyntheticData\SyntheticWorkloadPlanner;
use DateTimeInterface;
use Tests\TestCase;

class SyntheticRecordFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('synthetic_data.anchor_date', '2026-08-23 12:00:00');
    }

    public function test_fixed_seed_reproduces_the_same_semantic_rows(): void
    {
        config()->set('synthetic_data.status_weights', [
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 100,
        ]);

        $firstFactory = $this->factory(seed: 'repeatable-seed');
        $secondFactory = $this->factory(seed: 'repeatable-seed');

        $first = $this->semanticSnapshot($firstFactory, customerOrdinal: 17);
        $second = $this->semanticSnapshot($secondFactory, customerOrdinal: 17);

        $this->assertSame($first, $second);
    }

    public function test_different_seeds_produce_different_synthetic_identities(): void
    {
        $first = $this->factory(seed: 'seed-alpha')->user(9, 'irrelevant-test-hash', 91);
        $second = $this->factory(seed: 'seed-beta')->user(9, 'irrelevant-test-hash', 91);

        $this->assertNotSame($first['row']['email'], $second['row']['email']);
        $this->assertNotSame($first['row']['phone'], $second['row']['phone']);
    }

    public function test_workload_planner_assigns_the_exact_requested_application_count(): void
    {
        $options = $this->generationOptions(customers: 17, applications: 43, seed: 'planner-seed');
        $planner = new SyntheticWorkloadPlanner($options);

        $counts = [];
        $keys = [];
        for ($customer = 1; $customer <= $options->customers; $customer++) {
            $count = $planner->applicationsForCustomer($customer);
            $counts[] = $count;

            for ($history = 1; $history <= $count; $history++) {
                $keys[] = $planner->applicationKey($customer, $history);
            }
        }

        $this->assertSame(43, array_sum($counts));
        $this->assertContainsOnly('int', $counts);
        $this->assertSame([2, 3], array_values(array_unique($counts)));
        $this->assertCount(43, array_unique($keys));
        $this->assertSame(43, $planner->applicationsThrough(17));
        $this->assertSame(array_sum(array_slice($counts, 0, 7)), $planner->applicationsThrough(7));
    }

    public function test_workload_planner_keeps_an_exact_total_when_some_customers_have_no_application(): void
    {
        $options = $this->generationOptions(customers: 11, applications: 4, seed: 'sparse-planner');
        $planner = new SyntheticWorkloadPlanner($options);
        $counts = [];

        for ($customer = 1; $customer <= $options->customers; $customer++) {
            $counts[] = $planner->applicationsForCustomer($customer);
        }

        $this->assertSame(4, array_sum($counts));
        $this->assertSame(4, count(array_filter($counts, fn (int $count): bool => $count === 1)));
        $this->assertSame(7, count(array_filter($counts, fn (int $count): bool => $count === 0)));
    }

    public function test_financing_breakdown_and_identity_fields_stay_consistent_across_steps(): void
    {
        config()->set('synthetic_data.status_weights', [
            CreditApplication::STATUS_SUBMITTED => 100,
        ]);

        $factory = $this->factory(seed: 'financial-consistency');
        $user = $factory->user(23, 'irrelevant-test-hash', 91);
        $application = $factory->application(
            customerOrdinal: 23,
            historyIndex: 1,
            historyCount: 1,
            userId: 501,
            user: $user,
            branches: $this->branches(),
            staffByBranch: [11 => 31, 12 => 32],
            adminId: 91,
            securityId: 92,
        );

        $client = $factory->clientRow($application, 701);
        $credit = $factory->creditRequestRow($application, 701);
        $project = $factory->projectRow($application, 701);

        $this->assertNotNull($client);
        $this->assertNotNull($credit);
        $this->assertNotNull($project);

        $this->assertSame($client['code_client'], $credit['identifiant_personne']);
        $this->assertSame($client['code_client'], $project['identifiant_personne']);
        $this->assertSame($client['nom'], $credit['nom_ou_rs']);
        $this->assertSame($client['nom'], $project['nom_ou_rs']);
        $this->assertSame($client['prenom'], $credit['prenom_ou_dc']);
        $this->assertSame($client['prenom'], $project['prenom_ou_dc']);
        $this->assertSame($client['type_pid'], $credit['type_pid']);
        $this->assertSame($client['numero_pid'], $credit['numero_pid']);

        $amounts = $application['amounts'];
        $this->assertSame(
            $amounts['global_millimes'],
            $amounts['eqp_millimes'] + $amounts['fdr_millimes'] + $amounts['amg_millimes'] + $amounts['chp_millimes'],
        );
        $this->assertSame($credit['montant_global_sollicite'], $project['financement']);
        $this->assertSame($credit['montant_eqp'], $amounts['eqp']);
        $this->assertSame($credit['montant_fdr'], $amounts['fdr']);
        $this->assertSame($credit['montant_amg'], $amounts['amg']);
        $this->assertSame($credit['montant_chp'], $amounts['chp']);
        $this->assertSame(
            $this->millimes($project['cout']),
            $this->millimes($project['financement']) + $this->millimes($project['investissement_personnel']),
        );
    }

    private function semanticSnapshot(SyntheticRecordFactory $factory, int $customerOrdinal): array
    {
        $user = $factory->user($customerOrdinal, 'stable-test-password-hash', 91);
        $application = $factory->application(
            customerOrdinal: $customerOrdinal,
            historyIndex: 2,
            historyCount: 3,
            userId: 501,
            user: $user,
            branches: $this->branches(),
            staffByBranch: [11 => 31, 12 => 32],
            adminId: 91,
            securityId: 92,
        );
        $applicationId = 701;

        return $this->normalize([
            'user' => $user,
            'application' => $application,
            'client' => $factory->clientRow($application, $applicationId),
            'credit_request' => $factory->creditRequestRow($application, $applicationId),
            'project' => $factory->projectRow($application, $applicationId),
            'documents' => $factory->documentRows($application, $applicationId),
            'validations' => $factory->validationRows($application, $applicationId),
            'reports' => $factory->reportRows($application, $applicationId),
            'audits' => $factory->auditRows($application, $applicationId),
            'notifications' => $factory->notificationRows($application, $applicationId),
            'otp' => $factory->otpRow($customerOrdinal, 501, 'stable-test-otp-hash'),
        ]);
    }

    private function factory(string $seed): SyntheticRecordFactory
    {
        return new SyntheticRecordFactory(
            $this->generationOptions(customers: 100, applications: 130, seed: $seed),
            new SyntheticStatePath,
        );
    }

    private function generationOptions(int $customers, int $applications, string $seed): GenerationOptions
    {
        return GenerationOptions::fromInput(
            profile: 'small',
            customers: $customers,
            applications: $applications,
            seed: $seed,
            chunk: 10,
            materializeDocuments: false,
        );
    }

    /** @return list<array<string, mixed>> */
    private function branches(): array
    {
        return [
            [
                'id' => 11,
                'name' => 'Agence synthétique Nord',
                'ville' => 'Tunis',
                'delegation' => 'Zone Nord',
                'latitude' => '36.8065000',
                'longitude' => '10.1815000',
                'daily_capacity' => 4,
                'slot_start_time' => '09:00:00',
                'slot_end_time' => '16:00:00',
            ],
            [
                'id' => 12,
                'name' => 'Agence synthétique Sud',
                'ville' => 'Sfax',
                'delegation' => 'Zone Sud',
                'latitude' => '34.7406000',
                'longitude' => '10.7603000',
                'daily_capacity' => 4,
                'slot_start_time' => '09:00:00',
                'slot_end_time' => '16:00:00',
            ],
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    private function millimes(string $amount): int
    {
        [$dinars, $millimes] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $dinars * 1_000) + (int) str_pad(substr($millimes, 0, 3), 3, '0');
    }
}
