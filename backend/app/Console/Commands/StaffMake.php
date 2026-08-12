<?php

namespace App\Console\Commands;

use App\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Bootstraps a staff/admin account. There is no self-registration for internal employees — this
 * is the only way one gets created, matching how the review plan treats staff accounts as an
 * ops task rather than a signup flow.
 */
class StaffMake extends Command
{
    protected $signature = 'staff:make
                            {email : Email address for the new account}
                            {--first-name= : First name (prompted if omitted)}
                            {--last-name= : Last name (prompted if omitted)}
                            {--role=staff : "staff" or "admin"}
                            {--password= : Password (generated and printed once if omitted)}';

    protected $description = 'Create a staff or admin account';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');

        if (! in_array($role, ['staff', 'admin'], true)) {
            $this->error('--role must be "staff" or "admin".');

            return self::FAILURE;
        }

        if (StaffUser::where('email', $email)->exists()) {
            $this->error("A staff account with email {$email} already exists.");

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
        ]);

        $this->info("Created {$role} account: {$staff->email} (id {$staff->id}).");

        if ($generated) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
