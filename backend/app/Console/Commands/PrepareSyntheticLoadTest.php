<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\LoadTesting\LoadTestSafetyGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class PrepareSyntheticLoadTest extends Command
{
    protected $signature = 'bts:prepare-load-test
                            {--accounts=5 : Synthetic customer accounts}
                            {--applications-per-account=1 : Planned real API workflows per customer}
                            {--branch-mode=distributed : Assign customers across branches or to one branch}
                            {--seed=20260824 : Reproducible synthetic seed}
                            {--output= : Absolute JSON manifest path}';

    protected $description = 'Prepare isolated SYNTHETIC LOAD TEST identities (never business workflow rows)';

    public function handle(LoadTestSafetyGate $safety): int
    {
        try {
            $safety->assertCanInitialize();
            [$accounts, $applications, $branchMode, $seed, $output] = $this->validatedOptions();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $branches = Branch::query()->orderBy('id')->get();
        if ($branches->isEmpty()) {
            $this->error('Seed the existing branches before preparing load identities.');

            return self::FAILURE;
        }

        $database = $safety->databaseName();
        $campaignId = substr(hash('sha256', $database.'|'.$seed), 0, 16);
        $password = 'Load!'.substr(hash('sha256', 'password|'.$seed), 0, 28);
        $passwordHash = Hash::make($password);
        $now = now();

        $customers = DB::transaction(function () use ($accounts, $branches, $branchMode, $campaignId, $passwordHash, $now) {
            $rows = [];
            for ($ordinal = 1; $ordinal <= $accounts; $ordinal++) {
                $branch = $branchMode === 'single' ? $branches->first() : $branches[($ordinal - 1) % $branches->count()];
                $email = sprintf('load-%s-c%06d@synthetic.bts.invalid', $campaignId, $ordinal);
                $phone = '+2161'.str_pad((string) (hexdec(substr(hash('sha256', $campaignId.'|'.$ordinal), 0, 8)) % 10_000_000), 7, '0', STR_PAD_LEFT);

                $user = User::withTrashed()->updateOrCreate(
                    ['email' => $email],
                    [
                        'first_name' => 'Synthétique',
                        'last_name' => sprintf('Charge %06d', $ordinal),
                        'phone' => $phone,
                        'phone_verified_at' => $now,
                        'password' => $passwordHash,
                        'auth_provider' => 'password',
                        'status' => 'active',
                        'banned_at' => null,
                        'banned_reason' => null,
                        'banned_by_staff_id' => null,
                        'deleted_at' => null,
                    ],
                );

                $rows[] = [
                    'ordinal' => $ordinal,
                    'id' => $user->id,
                    'email' => $email,
                    'phone' => $phone,
                    'branch' => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'ville' => $branch->ville,
                        'delegation' => $branch->delegation,
                        'address' => $branch->address,
                        'latitude' => (float) $branch->latitude,
                        'longitude' => (float) $branch->longitude,
                    ],
                ];
            }

            return $rows;
        });

        $staff = [];
        foreach ($branches as $branch) {
            $email = sprintf('load-%s-staff-b%d@synthetic.bts.invalid', $campaignId, $branch->id);
            $actor = StaffUser::withTrashed()->updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => 'Staff',
                    'last_name' => 'Synthétique '.$branch->id,
                    'password' => $passwordHash,
                    'role' => 'staff',
                    'status' => 'active',
                    'branch_id' => $branch->id,
                    'deleted_at' => null,
                ],
            );
            $staff[(string) $branch->id] = ['id' => $actor->id, 'email' => $email, 'branch_id' => $branch->id];
        }

        $adminEmail = sprintf('load-%s-admin@synthetic.bts.invalid', $campaignId);
        $admin = StaffUser::withTrashed()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'first_name' => 'Admin',
                'last_name' => 'Synthétique',
                'password' => $passwordHash,
                'role' => 'admin',
                'status' => 'active',
                'branch_id' => null,
                'deleted_at' => null,
            ],
        );

        $marker = $safety->createMarker($campaignId, $seed);
        $manifest = [
            'marker' => LoadTestSafetyGate::MARKER,
            'version' => 1,
            'campaign_id' => $campaignId,
            'database' => $database,
            'seed' => $seed,
            'created_at' => $marker['created_at'],
            'accounts' => $accounts,
            'applications_per_account' => $applications,
            'branch_mode' => $branchMode,
            'password' => $password,
            'customers' => $customers,
            'staff_by_branch' => $staff,
            'admin' => ['id' => $admin->id, 'email' => $adminEmail],
        ];

        File::ensureDirectoryExists(dirname($output));
        $temporary = $output.'.tmp';
        File::put($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::move($temporary, $output);

        $this->components->warn('SYNTHETIC LOAD TEST ONLY');
        $this->info("Prepared {$accounts} isolated customer identities for campaign {$campaignId}.");
        $this->line('Credential manifest written to the requested local path; its secrets were not printed.');

        return self::SUCCESS;
    }

    private function validatedOptions(): array
    {
        $accounts = filter_var($this->option('accounts'), FILTER_VALIDATE_INT);
        $applications = filter_var($this->option('applications-per-account'), FILTER_VALIDATE_INT);
        $branchMode = (string) $this->option('branch-mode');
        $seed = (string) $this->option('seed');
        $output = (string) $this->option('output');

        if ($accounts === false || $accounts < 1 || $accounts > (int) config('load_testing.max_accounts')) {
            throw new InvalidArgumentException('Accounts must be between 1 and the configured load-test maximum.');
        }
        if ($applications === false || $applications < 1 || $applications > (int) config('load_testing.max_applications_per_account')) {
            throw new InvalidArgumentException('Applications per account exceed the configured safe maximum.');
        }
        if (! in_array($branchMode, ['distributed', 'single'], true)) {
            throw new InvalidArgumentException('Branch mode must be distributed or single.');
        }
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $seed)) {
            throw new InvalidArgumentException('Seed must contain 1–64 letters, numbers, dots, underscores, or hyphens.');
        }
        if ($output === '' || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'json') {
            throw new InvalidArgumentException('An explicit JSON output path is required.');
        }

        return [(int) $accounts, (int) $applications, $branchMode, $seed, $output];
    }
}
