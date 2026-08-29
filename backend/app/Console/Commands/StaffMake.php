<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Bootstraps a staff, security or admin account.
 */
class StaffMake extends Command
{
    protected $signature = 'staff:make
                            {email : Email address for the new account}
                            {--first-name= : First name (prompted if omitted)}
                            {--last-name= : Last name (prompted if omitted)}
                            {--role=staff : "staff", "security", or "admin"}
                            {--branch-id= : Required branch ID for operational staff}
                            {--password= : Password (generated and printed once if omitted)}';

    protected $description = 'Create a staff, security, or admin account';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');

        if (! in_array($role, ['staff', 'security', 'admin'], true)) {
            $this->error('--role must be "staff", "security", or "admin".');

            return self::FAILURE;
        }

        if (StaffUser::where('email', $email)->exists()) {
            $this->error("A staff account with email {$email} already exists.");

            return self::FAILURE;
        }

        $branchId = $this->option('branch-id');
        if ($role === 'staff' && $branchId === null) {
            $this->error('--branch-id is required for staff accounts.');

            return self::FAILURE;
        }

        if ($branchId !== null && (! ctype_digit((string) $branchId) || ! Branch::whereKey((int) $branchId)->exists())) {
            $this->error('--branch-id must identify an existing branch.');

            return self::FAILURE;
        }

        $firstName = $this->option('first-name') ?: $this->ask('First name');
        $lastName = $this->option('last-name') ?: $this->ask('Last name');

        $password = $this->option('password');
        $generated = false;

        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        try {
            Validator::make(['password' => $password], ['password' => Password::min(10)])->validate();
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->errors()['password'] ?? ['Password does not meet minimum requirements.']));

            return self::FAILURE;
        }

        $staff = StaffUser::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'status' => 'active',
            'branch_id' => $branchId === null ? null : (int) $branchId,
        ]);

        $this->info("Created {$role} account: {$staff->email} (id {$staff->id}).");

        if ($generated) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
