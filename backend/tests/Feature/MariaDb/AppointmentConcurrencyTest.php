<?php

namespace Tests\Feature\MariaDb;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AppointmentConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'mysql' || ! preg_match('/^bts_p0p1_concurrency_[0-9]+$/', $database)) {
            $this->markTestSkipped('Requires a dedicated bts_p0p1_concurrency_* MariaDB database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_mariadb_serializes_slot_capacity_application_and_attempt_races(): void
    {
        $branch = Branch::factory()->create(['daily_capacity' => 4]);
        $staffA = StaffUser::factory()->forBranch($branch)->create();
        $staffB = StaffUser::factory()->forBranch($branch)->create();
        $monday = CarbonImmutable::now()->next(CarbonInterface::MONDAY);
        $sameSlotDate = $monday->toDateString();
        $duplicateDate = $monday->addDay()->toDateString();
        $attemptDate = $monday->addDays(2)->toDateString();
        $capacityDate = $monday->addDays(3)->toDateString();

        // Two applications, two staff, one exact slot: exactly one succeeds.
        $appA = $this->application($branch);
        $appB = $this->application($branch);
        $sameSlot = $this->runConcurrent([
            [$appA->id, $staffA->id, $branch->id, $sameSlotDate, '10:00'],
            [$appB->id, $staffB->id, $branch->id, $sameSlotDate, '10:00'],
        ]);
        $this->assertSame(1, collect($sameSlot)->where('ok', true)->count());
        $this->assertSame(1, Appointment::whereDate('scheduled_date', $sameSlotDate)->count());

        // Duplicate concurrent requests for one application are idempotent.
        $sameApplication = $this->application($branch);
        $duplicate = $this->runConcurrent([
            [$sameApplication->id, $staffA->id, $branch->id, $duplicateDate, '11:00'],
            [$sameApplication->id, $staffB->id, $branch->id, $duplicateDate, '11:00'],
        ]);
        $this->assertSame(2, collect($duplicate)->where('ok', true)->count());
        $this->assertSame(1, Appointment::where('credit_application_id', $sameApplication->id)->count());
        $this->assertCount(1, collect($duplicate)->pluck('appointment_id')->unique());

        // Different concurrent reschedules serialize attempt numbers without duplicates.
        $attemptApplication = $this->application($branch);
        $attempts = $this->runConcurrent([
            [$attemptApplication->id, $staffA->id, $branch->id, $attemptDate, '09:00'],
            [$attemptApplication->id, $staffB->id, $branch->id, $attemptDate, '14:00'],
        ]);
        $this->assertSame(2, collect($attempts)->where('ok', true)->count());
        $this->assertSame([1, 2], Appointment::where('credit_application_id', $attemptApplication->id)->orderBy('attempt_number')->pluck('attempt_number')->all());
        $this->assertSame(1, Appointment::where('credit_application_id', $attemptApplication->id)->whereIn('status', [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED])->count());

        // Capacity one on a separate branch rejects one of two different times without deadlock.
        $smallBranch = Branch::factory()->create(['daily_capacity' => 1]);
        $smallStaffA = StaffUser::factory()->forBranch($smallBranch)->create();
        $smallStaffB = StaffUser::factory()->forBranch($smallBranch)->create();
        $capacity = $this->runConcurrent([
            [$this->application($smallBranch)->id, $smallStaffA->id, $smallBranch->id, $capacityDate, '09:00'],
            [$this->application($smallBranch)->id, $smallStaffB->id, $smallBranch->id, $capacityDate, '11:00'],
        ]);
        $this->assertSame(1, collect($capacity)->where('ok', true)->count());
        $this->assertSame(1, Appointment::where('branch_id', $smallBranch->id)->whereDate('scheduled_date', $capacityDate)->count());
    }

    public function test_concurrent_automatic_scheduling_starts_tomorrow_and_respects_daily_capacity(): void
    {
        $branch = Branch::factory()->create(['daily_capacity' => 1]);
        $admin = StaffUser::factory()->admin()->create();
        $first = $this->approvedApplication($branch);
        $second = $this->approvedApplication($branch);

        $results = $this->runConcurrentAutomatic([
            [$first->id, $admin->id],
            [$second->id, $admin->id],
        ]);

        $this->assertSame(2, collect($results)->where('ok', true)->count());
        $this->assertSame(
            ['2026-08-25', '2026-08-26'],
            Appointment::query()
                ->orderBy('scheduled_date')
                ->get()
                ->map(fn (Appointment $appointment) => $appointment->scheduled_date->toDateString())
                ->all(),
        );
        $this->assertSame(1, Appointment::whereDate('scheduled_date', '2026-08-25')->count());
        $this->assertSame(1, Appointment::whereDate('scheduled_date', '2026-08-26')->count());
        $this->assertSame(0, Appointment::whereDate('scheduled_date', '2026-08-24')->count());
    }

    /** @param list<array{int,int,int,string,string}> $requests @return list<array<string,mixed>> */
    private function runConcurrent(array $requests): array
    {
        $startAt = microtime(true) + 1.0;
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
while (microtime(true) < (float) $argv[2]) { usleep(1000); }
try {
    $appointment = $app->make(App\Services\AppointmentSchedulingService::class)->scheduleManual(
        App\Models\CreditApplication::findOrFail((int) $argv[3]),
        App\Models\StaffUser::findOrFail((int) $argv[4]),
        (int) $argv[5], $argv[6], $argv[7], true
    );
    echo json_encode(['ok' => true, 'appointment_id' => $appointment->id, 'attempt' => $appointment->attempt_number]);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception instanceof App\Exceptions\ApiException ? $exception->errorCode : $exception::class]);
}
PHP;

        $processes = [];
        foreach ($requests as [$applicationId, $staffId, $branchId, $date, $time]) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $startAt, (string) $applicationId, (string) $staffId, (string) $branchId, $date, $time], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): array {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $decoded = json_decode($process->getOutput(), true);
            $this->assertIsArray($decoded, $process->getOutput().$process->getErrorOutput());

            return $decoded;
        }, $processes);
    }

    /** @param list<array{int,int}> $requests @return list<array<string,mixed>> */
    private function runConcurrentAutomatic(array $requests): array
    {
        $startAt = microtime(true) + 1.0;
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow('2026-08-24 08:00:00');
while (microtime(true) < (float) $argv[2]) { usleep(1000); }
try {
    $appointment = $app->make(App\Services\AppointmentSchedulingService::class)->proposeNext(
        App\Models\CreditApplication::findOrFail((int) $argv[3]),
        App\Models\StaffUser::findOrFail((int) $argv[4]),
    );
    echo json_encode(['ok' => true, 'appointment_id' => $appointment->id, 'date' => $appointment->scheduled_date->toDateString()]);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception instanceof App\Exceptions\ApiException ? $exception->errorCode : $exception::class]);
}
PHP;

        $processes = [];
        foreach ($requests as [$applicationId, $staffId]) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $startAt, (string) $applicationId, (string) $staffId], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): array {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $decoded = json_decode($process->getOutput(), true);
            $this->assertIsArray($decoded, $process->getOutput().$process->getErrorOutput());

            return $decoded;
        }, $processes);
    }

    private function application(Branch $branch): CreditApplication
    {
        return CreditApplication::factory()->create([
            'user_id' => User::factory(),
            'branch_id' => $branch->id,
            'status' => CreditApplication::STATUS_APPOINTMENT_LOCKED,
        ]);
    }

    private function approvedApplication(Branch $branch): CreditApplication
    {
        return CreditApplication::factory()->create([
            'user_id' => User::factory(),
            'branch_id' => $branch->id,
            'status' => CreditApplication::STATUS_APPROVED,
        ]);
    }
}
