<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\StaffUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class E2ETestSeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! app()->environment('e2e', 'testing') || ! str_starts_with($database, 'bts_e2e_')) {
            throw new RuntimeException('E2ETestSeeder refuses to run outside a dedicated bts_e2e_* database.');
        }

        $primary = Branch::factory()->default()->create(['name' => 'Agence E2E Tunis', 'ville' => 'Tunis']);
        $other = Branch::factory()->create(['name' => 'Agence E2E Sfax', 'ville' => 'Sfax']);

        $this->staff('e2e.staff@bts.test', 'E2E-Staff-Pass-2026!', 'staff', 'active', $primary->id);
        $this->staff('e2e.other-branch@bts.test', 'E2E-Other-Branch-Pass-2026!', 'staff', 'active', $other->id);
        $this->staff('e2e.admin@bts.test', 'E2E-Admin-Pass-2026!', 'admin', 'active');
        $this->staff('e2e.security@bts.test', 'E2E-Security-Pass-2026!', 'security', 'active');
        $this->staff('e2e.suspended.security@bts.test', 'E2E-Suspended-Pass-2026!', 'security', 'suspended');
    }

    private function staff(string $email, string $password, string $role, string $status, ?int $branchId = null): void
    {
        StaffUser::create([
            'first_name' => 'Synthetic',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'status' => $status,
            'branch_id' => $branchId,
        ]);
    }
}
