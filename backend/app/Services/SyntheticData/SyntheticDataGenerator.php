<?php

namespace App\Services\SyntheticData;

use App\Models\Branch;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SyntheticDataGenerator
{
    public function __construct(private readonly SyntheticDataSafetyGate $safetyGate) {}

    /**
     * @param  null|Closure(array<string,mixed>):void  $progress
     * @return array<string,int|string|bool>
     */
    public function generate(GenerationOptions $options, ?Closure $progress = null): array
    {
        /* Must execute before hashing, filesystem writes, or any database mutation. */
        $this->safetyGate->assertAllowed($options);

        $connection = DB::connection();
        $branches = $this->branches();
        if ($branches === []) {
            throw new RuntimeException(
                'No branches exist. Seed or create branch reference data before generating synthetic records; no branch is created automatically.'
            );
        }

        $checkpoint = new GenerationCheckpoint($connection, $options);
        $checkpoint->acquire();
        $startedAt = microtime(true);

        try {
            $passwordHash = $this->deterministicPasswordHash($options);
            $actors = $this->ensureSyntheticActors($connection, $options, $branches, $passwordHash);
            $planner = new SyntheticWorkloadPlanner($options);
            $statePath = new SyntheticStatePath;
            $records = new SyntheticRecordFactory($options, $statePath);
            $allocator = new AppointmentSlotAllocator($connection);

            $completed = min($checkpoint->completedCustomerOrdinal(), $options->customers);
            if ($completed > 0 && ! $this->checkpointMatchesDatabase($connection, $options, $planner, $completed)) {
                $completed = 0;
                $progress?->__invoke([
                    'phase' => 'checkpoint_rescan',
                    'current' => 0,
                    'total' => $options->customers,
                    'message' => 'Checkpoint data mismatch; safely rescanning deterministic records.',
                ]);
            }

            $totals = [
                'users_inserted' => 0,
                'applications_inserted' => 0,
                'related_rows_inserted' => 0,
                'documents_materialized' => 0,
                'document_bytes_materialized' => 0,
                'chunks_completed' => 0,
            ];

            for ($start = $completed + 1; $start <= $options->customers; $start += $options->chunkSize) {
                $end = min($options->customers, $start + $options->chunkSize - 1);

                /** @var array{users_inserted:int,applications_inserted:int,related_rows_inserted:int,document_specs:list<array{path:string,content:string}>} $chunkResult */
                $chunkResult = $connection->transaction(fn () => $this->generateChunk(
                    connection: $connection,
                    options: $options,
                    planner: $planner,
                    records: $records,
                    allocator: $allocator,
                    branches: $branches,
                    actors: $actors,
                    passwordHash: $passwordHash,
                    start: $start,
                    end: $end,
                ), attempts: 1);

                $materialized = $options->materializeDocuments
                    ? $this->materializeDocuments($chunkResult['document_specs'])
                    : ['count' => 0, 'bytes' => 0];

                $checkpoint->save($end);
                $totals['users_inserted'] += $chunkResult['users_inserted'];
                $totals['applications_inserted'] += $chunkResult['applications_inserted'];
                $totals['related_rows_inserted'] += $chunkResult['related_rows_inserted'];
                $totals['documents_materialized'] += $materialized['count'];
                $totals['document_bytes_materialized'] += $materialized['bytes'];
                $totals['chunks_completed']++;

                $elapsed = max(0.001, microtime(true) - $startedAt);
                $progress?->__invoke([
                    'phase' => 'customers',
                    'current' => $end,
                    'total' => $options->customers,
                    'rate' => (int) floor(($end - $completed) / $elapsed),
                    'memory_bytes' => memory_get_usage(true),
                    ...$totals,
                ]);
            }

            return [
                'synthetic_test_data' => true,
                'profile' => $options->profile,
                'run_key' => $options->runKey,
                'customers_planned' => $options->customers,
                'applications_planned' => $options->applications,
                'resumed_after_customer' => $completed,
                'elapsed_seconds' => (int) ceil(microtime(true) - $startedAt),
                ...$totals,
            ];
        } finally {
            $checkpoint->release();
        }
    }

    /** @return list<array<string,mixed>> */
    private function branches(): array
    {
        $branches = Branch::query()->orderBy('id')->get()->map(function (Branch $branch): array {
            $slotTimes = array_values(array_unique(array_map(
                fn (string $time): string => strlen($time) === 5 ? $time.':00' : substr($time, 0, 8),
                $branch->slotTimes(),
            )));

            return [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'ville' => (string) $branch->ville,
                'delegation' => (string) ($branch->delegation ?: $branch->ville),
                'latitude' => $branch->latitude === null ? null : (string) $branch->latitude,
                'longitude' => $branch->longitude === null ? null : (string) $branch->longitude,
                'daily_capacity' => max(1, (int) $branch->daily_capacity),
                'slot_times' => $slotTimes === [] ? Branch::DEFAULT_SLOTS : $slotTimes,
            ];
        })->values()->all();

        // The domain routing service selects the first matching branch. Keep
        // every branch for staff/analytics, but route new applications only to
        // one canonical branch for duplicate coordinates or locality records.
        $seenRoutingKeys = [];
        foreach ($branches as $index => $branch) {
            $routingKey = $branch['latitude'] !== null && $branch['longitude'] !== null
                ? 'coordinates:'.$branch['latitude'].'|'.$branch['longitude']
                : 'locality:'.mb_strtolower($branch['ville']).'|'.mb_strtolower($branch['delegation']);
            $branches[$index]['routing_enabled'] = ! isset($seenRoutingKeys[$routingKey]);
            $seenRoutingKeys[$routingKey] = true;
        }

        return $branches;
    }

    /**
     * @param  list<array<string,mixed>>  $branches
     * @return array{admin_id:int,security_id:int,staff_by_branch:array<int,int>}
     */
    private function ensureSyntheticActors(
        ConnectionInterface $connection,
        GenerationOptions $options,
        array $branches,
        string $passwordHash,
    ): array {
        $domain = (string) config('synthetic_data.email_domain', 'synthetic.bts.invalid');
        $createdAt = $options->anchor()->subYears($options->years + 2)->format('Y-m-d H:i:s');
        $definitions = [
            [
                'key' => 'admin',
                'email' => 'syn-'.$options->runKey.'-admin@'.$domain,
                'first_name' => 'Admin',
                'last_name' => 'Synthétique',
                'role' => 'admin',
                'branch_id' => null,
            ],
            [
                'key' => 'security',
                'email' => 'syn-'.$options->runKey.'-security@'.$domain,
                'first_name' => 'Sécurité',
                'last_name' => 'Synthétique',
                'role' => 'security',
                'branch_id' => null,
            ],
        ];
        foreach ($branches as $branch) {
            $definitions[] = [
                'key' => 'branch-'.$branch['id'],
                'email' => 'syn-'.$options->runKey.'-staff-b'.$branch['id'].'@'.$domain,
                'first_name' => 'Agent',
                'last_name' => 'Synthétique B'.$branch['id'],
                'role' => 'staff',
                'branch_id' => $branch['id'],
            ];
        }

        $rows = array_map(fn (array $definition): array => [
            'first_name' => $definition['first_name'],
            'last_name' => $definition['last_name'],
            'email' => $definition['email'],
            'password' => $passwordHash,
            'role' => $definition['role'],
            'status' => 'active',
            'branch_id' => $definition['branch_id'],
            'remember_token' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => null,
        ], $definitions);
        $connection->table('staff_users')->insertOrIgnore($rows);

        $stored = $connection->table('staff_users')
            ->whereIn('email', array_column($definitions, 'email'))
            ->get()
            ->keyBy('email');
        if ($stored->count() !== count($definitions)) {
            throw new RuntimeException('Synthetic staff namespace collided with an existing unique value.');
        }

        $result = ['admin_id' => 0, 'security_id' => 0, 'staff_by_branch' => []];
        foreach ($definitions as $definition) {
            $staff = $stored->get($definition['email']);
            if (
                $staff === null
                || (string) $staff->role !== $definition['role']
                || ($definition['branch_id'] !== null && (int) $staff->branch_id !== (int) $definition['branch_id'])
                || $staff->deleted_at !== null
            ) {
                throw new RuntimeException('Existing synthetic staff marker has incompatible data; refusing to overwrite it.');
            }

            if ($definition['key'] === 'admin') {
                $result['admin_id'] = (int) $staff->id;
            } elseif ($definition['key'] === 'security') {
                $result['security_id'] = (int) $staff->id;
            } else {
                $result['staff_by_branch'][(int) $definition['branch_id']] = (int) $staff->id;
            }
        }

        return $result;
    }

    private function checkpointMatchesDatabase(
        ConnectionInterface $connection,
        GenerationOptions $options,
        SyntheticWorkloadPlanner $planner,
        int $completed,
    ): bool {
        $emailPattern = $options->emailPrefix().'%@'.config('synthetic_data.email_domain', 'synthetic.bts.invalid');
        $userCount = $connection->table('users')->where('email', 'like', $emailPattern)->whereNull('deleted_at')->count();
        if ($userCount !== $completed) {
            return false;
        }

        foreach (array_unique([$options->emailFor(1), $options->emailFor($completed)]) as $email) {
            if (! $connection->table('users')->where('email', $email)->whereNull('deleted_at')->exists()) {
                return false;
            }
        }

        $applicationCount = $connection->table('credit_applications')
            ->join('users', 'users.id', '=', 'credit_applications.user_id')
            ->where('users.email', 'like', $emailPattern)
            ->whereNull('users.deleted_at')
            ->whereNull('credit_applications.deleted_at')
            ->count('credit_applications.id');

        return $applicationCount === $planner->applicationsThrough($completed);
    }

    /**
     * @param  list<array<string,mixed>>  $branches
     * @param  array{admin_id:int,security_id:int,staff_by_branch:array<int,int>}  $actors
     * @return array{users_inserted:int,applications_inserted:int,related_rows_inserted:int,document_specs:list<array{path:string,content:string}>}
     */
    private function generateChunk(
        ConnectionInterface $connection,
        GenerationOptions $options,
        SyntheticWorkloadPlanner $planner,
        SyntheticRecordFactory $records,
        AppointmentSlotAllocator $allocator,
        array $branches,
        array $actors,
        string $passwordHash,
        int $start,
        int $end,
    ): array {
        $userBlueprints = [];
        for ($ordinal = $start; $ordinal <= $end; $ordinal++) {
            $userBlueprints[$ordinal] = $records->user($ordinal, $passwordHash, $actors['admin_id']);
        }

        $emails = array_map(fn (array $blueprint): string => $blueprint['row']['email'], $userBlueprints);
        $beforeUsers = $connection->table('users')->whereIn('email', $emails)->get()->keyBy('email');
        $newUserEmails = [];
        $userRows = [];
        foreach ($userBlueprints as $blueprint) {
            $email = $blueprint['row']['email'];
            if (! $beforeUsers->has($email)) {
                $newUserEmails[$email] = true;
                $userRows[] = $blueprint['row'];
            }
        }
        $this->insertRows($connection, 'users', $userRows, ignore: true);

        $storedUsers = $connection->table('users')->whereIn('email', $emails)->get()->keyBy('email');
        if ($storedUsers->count() !== count($userBlueprints)) {
            throw new RuntimeException('Synthetic customer insertion failed, usually because a generated phone collided with existing data.');
        }

        foreach ($userBlueprints as $blueprint) {
            $stored = $storedUsers->get($blueprint['row']['email']);
            if ((string) $stored->phone !== $blueprint['row']['phone'] || $stored->deleted_at !== null) {
                throw new RuntimeException('Existing synthetic customer marker has incompatible data; refusing to overwrite it.');
            }
        }

        $applicationBlueprints = [];
        foreach ($userBlueprints as $ordinal => $userBlueprint) {
            $storedUser = $storedUsers->get($userBlueprint['row']['email']);
            $historyCount = $planner->applicationsForCustomer($ordinal);
            for ($historyIndex = 1; $historyIndex <= $historyCount; $historyIndex++) {
                $blueprint = $records->application(
                    customerOrdinal: $ordinal,
                    historyIndex: $historyIndex,
                    historyCount: $historyCount,
                    userId: (int) $storedUser->id,
                    user: $userBlueprint,
                    branches: $branches,
                    staffByBranch: $actors['staff_by_branch'],
                    adminId: $actors['admin_id'],
                    securityId: $actors['security_id'],
                );
                $applicationBlueprints[$blueprint['key']] = $blueprint;
            }
        }

        $userIds = $storedUsers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $existingApplications = $this->applicationMap($connection, $userIds);
        $newApplicationKeys = [];
        $applicationRows = [];
        foreach ($applicationBlueprints as $key => $blueprint) {
            $identity = $this->applicationIdentity($blueprint['user_id'], $blueprint['row']['created_at']);
            if (! isset($existingApplications[$identity])) {
                $newApplicationKeys[$key] = true;
                $applicationRows[] = $blueprint['row'];
            }
        }
        $this->insertRows($connection, 'credit_applications', $applicationRows);

        $storedApplications = $this->applicationMap($connection, $userIds);
        foreach ($applicationBlueprints as $key => &$blueprint) {
            $identity = $this->applicationIdentity($blueprint['user_id'], $blueprint['row']['created_at']);
            if (! isset($storedApplications[$identity])) {
                throw new RuntimeException('Cannot resolve a newly inserted synthetic credit application.');
            }
            $blueprint['application_id'] = $storedApplications[$identity];
        }
        unset($blueprint);

        $tableRows = [
            'clients' => [],
            'credit_requests' => [],
            'projects' => [],
            'documents' => [],
            'validation_steps' => [],
            'appointments' => [],
            'report_messages' => [],
            'audit_logs' => [],
            'app_notifications' => [],
            'otp_codes' => [],
        ];
        $documentSpecs = [];

        foreach ($applicationBlueprints as $key => $blueprint) {
            $applicationId = (int) $blueprint['application_id'];
            $documentRows = $records->documentRows($blueprint, $applicationId);
            foreach ($documentRows as $documentRow) {
                $documentSpecs[$documentRow['disk_path']] = [
                    'path' => $documentRow['disk_path'],
                    'content' => $records->documentContent($blueprint, $documentRow['document_type']),
                ];
            }

            if (! isset($newApplicationKeys[$key])) {
                $this->assertExistingApplicationCompatible($connection, $blueprint, $documentRows, $records);

                continue;
            }

            $client = $records->clientRow($blueprint, $applicationId);
            $credit = $records->creditRequestRow($blueprint, $applicationId);
            $project = $records->projectRow($blueprint, $applicationId);
            if ($client !== null) {
                $tableRows['clients'][] = $client;
            }
            if ($credit !== null) {
                $tableRows['credit_requests'][] = $credit;
            }
            if ($project !== null) {
                $tableRows['projects'][] = $project;
            }
            array_push($tableRows['documents'], ...$documentRows);
            array_push($tableRows['validation_steps'], ...$records->validationRows($blueprint, $applicationId));
            array_push($tableRows['appointments'], ...$records->appointmentRows($blueprint, $applicationId, $allocator));
            array_push($tableRows['report_messages'], ...$records->reportRows($blueprint, $applicationId));
            array_push($tableRows['audit_logs'], ...$records->auditRows($blueprint, $applicationId));
            array_push($tableRows['app_notifications'], ...$records->notificationRows($blueprint, $applicationId));
        }

        foreach ($userBlueprints as $ordinal => $blueprint) {
            if (! isset($newUserEmails[$blueprint['row']['email']])) {
                continue;
            }
            $otp = $records->otpRow($ordinal, (int) $storedUsers->get($blueprint['row']['email'])->id, $passwordHash);
            if ($otp !== null) {
                $tableRows['otp_codes'][] = $otp;
            }
        }

        $relatedRows = 0;
        foreach ($tableRows as $table => $rows) {
            $this->insertRows($connection, $table, $rows);
            $relatedRows += count($rows);
        }

        return [
            'users_inserted' => count($newUserEmails),
            'applications_inserted' => count($newApplicationKeys),
            'related_rows_inserted' => $relatedRows,
            'document_specs' => array_values($documentSpecs),
        ];
    }

    /** @param list<int> $userIds @return array<string,int> */
    private function applicationMap(ConnectionInterface $connection, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $map = [];
        $applications = $connection->table('credit_applications')
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'created_at']);
        foreach ($applications as $application) {
            $identity = $this->applicationIdentity((int) $application->user_id, (string) $application->created_at);
            if (isset($map[$identity])) {
                throw new RuntimeException('Two applications share a deterministic user/timestamp identity; refusing ambiguous resume.');
            }
            $map[$identity] = (int) $application->id;
        }

        return $map;
    }

    private function applicationIdentity(int $userId, string $createdAt): string
    {
        return $userId.'|'.substr($createdAt, 0, 19);
    }

    /**
     * Refuse a replay that would silently leave a partially deleted or manually
     * altered synthetic application behind. The generator is additive, never a
     * repair tool for arbitrary application data.
     *
     * @param  array<string,mixed>  $blueprint
     * @param  list<array<string,mixed>>  $documentRows
     */
    private function assertExistingApplicationCompatible(
        ConnectionInterface $connection,
        array $blueprint,
        array $documentRows,
        SyntheticRecordFactory $records,
    ): void {
        $applicationId = (int) $blueprint['application_id'];
        $application = $connection->table('credit_applications')->where('id', $applicationId)->first();
        if (
            $application === null
            || $application->deleted_at !== null
            || (string) $application->status !== (string) $blueprint['row']['status']
            || (int) $application->branch_id !== (int) $blueprint['row']['branch_id']
        ) {
            throw new RuntimeException('Existing synthetic application marker has incompatible data; refusing to overwrite it.');
        }

        $expectedOneToOne = [
            'clients' => $records->clientRow($blueprint, $applicationId) !== null,
            'credit_requests' => $records->creditRequestRow($blueprint, $applicationId) !== null,
            'projects' => $records->projectRow($blueprint, $applicationId) !== null,
        ];
        foreach ($expectedOneToOne as $table => $expected) {
            $actual = $connection->table($table)->where('credit_application_id', $applicationId)->count();
            if ($actual !== ($expected ? 1 : 0)) {
                throw new RuntimeException('Synthetic resume found incomplete related data in '.$table.'. Use a new seed or restore the test database.');
            }
        }

        $paths = array_column($documentRows, 'disk_path');
        $documentCount = $connection->table('documents')->where('credit_application_id', $applicationId)->whereNull('deleted_at')->count();
        if ($documentCount !== count($paths) || ($paths !== [] && $connection->table('documents')->whereIn('disk_path', $paths)->count() !== count($paths))) {
            throw new RuntimeException('Synthetic resume found incomplete document metadata. Use a new seed or restore the test database.');
        }
        $validationCount = $connection->table('validation_steps')->where('credit_application_id', $applicationId)->count();
        if ($validationCount !== count($records->validationRows($blueprint, $applicationId))) {
            throw new RuntimeException('Synthetic resume found incomplete validation history. Use a new seed or restore the test database.');
        }
    }

    private function deterministicPasswordHash(GenerationOptions $options): string
    {
        $material = hash('sha256', 'bts-synthetic-password|'.$options->seed.'|'.$options->runKey, true);
        $salt = substr(strtr(rtrim(base64_encode(substr($material, 0, 16)), '='), '+', '.'), 0, 22);
        $hash = crypt('bts-synthetic|'.$options->runKey, '$2y$12$'.$salt.'$');
        if (! is_string($hash) || strlen($hash) !== 60) {
            throw new RuntimeException('The platform cannot create a deterministic bcrypt hash for synthetic data.');
        }

        return $hash;
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertRows(ConnectionInterface $connection, string $table, array $rows, bool $ignore = false): void
    {
        if ($rows === []) {
            return;
        }

        $batchSize = max(1, (int) config('synthetic_data.limits.insert_batch', 500));
        foreach (array_chunk($rows, $batchSize) as $batch) {
            if ($ignore) {
                $connection->table($table)->insertOrIgnore($batch);
            } else {
                $connection->table($table)->insert($batch);
            }
        }
    }

    /**
     * @param  list<array{path:string,content:string}>  $documents
     * @return array{count:int,bytes:int}
     */
    private function materializeDocuments(array $documents): array
    {
        $disk = Storage::disk((string) config('synthetic_data.document_disk', 'local'));
        $created = 0;
        $bytes = 0;
        foreach ($documents as $document) {
            if ($disk->exists($document['path'])) {
                $existing = $disk->get($document['path']);
                if (! is_string($existing) || ! hash_equals(hash('sha256', $document['content']), hash('sha256', $existing))) {
                    throw new RuntimeException('Existing synthetic document path contains unexpected content: '.$document['path']);
                }

                continue;
            }
            if (! $disk->put($document['path'], $document['content'])) {
                throw new RuntimeException('Cannot materialize synthetic document: '.$document['path']);
            }
            $created++;
            $bytes += strlen($document['content']);
        }

        return ['count' => $created, 'bytes' => $bytes];
    }
}
