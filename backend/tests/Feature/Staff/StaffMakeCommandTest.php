<?php

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffMakeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_staff_account_with_the_given_password(): void
    {
        $branch = Branch::factory()->create();

        $this->artisan('staff:make', [
            'email' => 'reviewer@bts.test',
            '--first-name' => 'Sara',
            '--last-name' => 'Reviewer',
            '--role' => 'staff',
            '--branch-id' => (string) $branch->id,
            '--password' => 'CorrectHorseBattery',
        ])->assertExitCode(0);

        $staff = StaffUser::where('email', 'reviewer@bts.test')->firstOrFail();
        $this->assertSame('staff', $staff->role);
        $this->assertSame($branch->id, $staff->branch_id);
        $this->assertTrue(Hash::check('CorrectHorseBattery', $staff->password));
    }

    public function test_rejects_a_password_under_the_minimum_length(): void
    {
        $branch = Branch::factory()->create();

        $this->artisan('staff:make', [
            'email' => 'reviewer2@bts.test',
            '--first-name' => 'Sara',
            '--last-name' => 'Reviewer',
            '--branch-id' => (string) $branch->id,
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('staff_users', ['email' => 'reviewer2@bts.test']);
    }

    public function test_refuses_a_duplicate_email(): void
    {
        StaffUser::factory()->create(['email' => 'dupe@bts.test']);

        $this->artisan('staff:make', [
            'email' => 'dupe@bts.test',
            '--first-name' => 'Sara',
            '--last-name' => 'Reviewer',
            '--password' => 'CorrectHorseBattery',
        ])->assertExitCode(1);
    }

    public function test_refuses_operational_staff_without_a_branch(): void
    {
        $this->artisan('staff:make', [
            'email' => 'unassigned@bts.test',
            '--first-name' => 'Sara',
            '--last-name' => 'Reviewer',
            '--role' => 'staff',
            '--password' => 'CorrectHorseBattery',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('staff_users', ['email' => 'unassigned@bts.test']);
    }
}
