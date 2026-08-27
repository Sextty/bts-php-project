<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with all required default data.
     */
    public function run(): void
    {
        // 1. Seed branches
        $this->call([
            BranchSeeder::class,
        ]);

        $defaultBranch = Branch::first();

        // 2. Seed Admin User (only if development seed password is provided or in local env)
        $adminPassword = env('ADMIN_SEED_PASSWORD', app()->isLocal() ? 'motdepasse123' : null);
        if ($adminPassword) {
            StaffUser::firstOrCreate(
                ['email' => env('ADMIN_SEED_EMAIL', 'admin@btsbank.tn')],
                [
                    'first_name' => 'Sonia',
                    'last_name' => 'Trabelsi',
                    'password' => Hash::make($adminPassword),
                    'role' => 'admin',
                    'branch_id' => $defaultBranch?->id,
                    'status' => 'active',
                ]
            );
        }

        // 3. Seed Security Center User (only if development seed password is provided or in local env)
        $securityPassword = env('SECURITY_SEED_PASSWORD', app()->isLocal() ? 'motdepasse123' : null);
        if ($securityPassword) {
            StaffUser::firstOrCreate(
                ['email' => env('SECURITY_SEED_EMAIL', 'security@btsbank.tn')],
                [
                    'first_name' => 'Farid',
                    'last_name' => 'Gharbi',
                    'password' => Hash::make($securityPassword),
                    'role' => 'security',
                    'branch_id' => null,
                    'status' => 'active',
                ]
            );
        }

        // 4. Seed Staff User
        $staffPassword = env('STAFF_SEED_PASSWORD', app()->isLocal() ? 'motdepasse123' : null);
        if ($staffPassword) {
            $staff = StaffUser::firstOrCreate(
                ['email' => env('STAFF_SEED_EMAIL', 'staff@btsbank.tn')],
                [
                    'first_name' => 'Ahmed',
                    'last_name' => 'Ben Salah',
                    'password' => Hash::make($staffPassword),
                    'role' => 'staff',
                    'branch_id' => $defaultBranch?->id,
                    'status' => 'active',
                ]
            );

            // Legacy local databases may already contain this seeded account from before the
            // branch_id column existed. firstOrCreate() correctly preserves the account and its
            // password, but it also leaves the new assignment null; strict branch isolation then
            // fails closed and the Staff portal legitimately returns empty datasets. Repair only
            // the explicitly configured seed account, never arbitrary operational accounts.
            if ($staff->isBranchRestricted() && $staff->branch_id === null && $defaultBranch !== null) {
                $staff->forceFill(['branch_id' => $defaultBranch->id])->save();
            }
        }

        // 5. Seed Demo Customer User
        $clientPassword = env('CLIENT_SEED_PASSWORD', app()->isLocal() ? 'motdepasse123' : null);
        if ($clientPassword) {
            User::firstOrCreate(
                ['email' => env('CLIENT_SEED_EMAIL', 'client@btsbank.tn')],
                [
                    'first_name' => 'Karim',
                    'last_name' => 'Mansour',
                    'phone' => '+21698123456',
                    'phone_verified_at' => now(),
                    'password' => Hash::make($clientPassword),
                    'auth_provider' => 'password',
                    'status' => 'active',
                ]
            );
        }
    }
}
