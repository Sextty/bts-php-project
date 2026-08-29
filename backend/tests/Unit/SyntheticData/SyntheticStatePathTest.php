<?php

namespace Tests\Unit\SyntheticData;

use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CreditApplicationStateMachine;
use App\Services\SyntheticData\SyntheticStatePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SyntheticStatePathTest extends TestCase
{
    private SyntheticStatePath $paths;

    private CreditApplicationStateMachine $machine;

    protected function setUp(): void
    {
        $this->paths = new SyntheticStatePath;
        $this->machine = new CreditApplicationStateMachine(new AuditLogService);
    }

    public function test_every_application_status_has_a_path_made_only_of_real_status_constants(): void
    {
        $knownStatuses = CreditApplication::STATUS_ORDER;

        foreach ($knownStatuses as $status) {
            $path = $this->paths->for($status);

            $this->assertSame(CreditApplication::STATUS_DRAFT, $path[0], "{$status} must start at DRAFT.");
            $this->assertSame($status, $path[array_key_last($path)], "{$status} must be the path endpoint.");
            $this->assertSame([], array_values(array_diff($path, $knownStatuses)), "{$status} contains an unknown state.");
        }
    }

    public function test_every_generated_path_edge_is_accepted_by_the_real_state_machine(): void
    {
        foreach (CreditApplication::STATUS_ORDER as $status) {
            $this->assertLegalEdges($this->paths->for($status));
        }
    }

    #[DataProvider('cancellationSources')]
    public function test_cancellation_paths_use_only_legal_sources(string $source): void
    {
        $path = $this->paths->for(CreditApplication::STATUS_CANCELLED, $source);

        $this->assertSame($source, $path[count($path) - 2]);
        $this->assertSame(CreditApplication::STATUS_CANCELLED, $path[array_key_last($path)]);
        $this->assertLegalEdges($path);
    }

    /** @return iterable<string, array{string}> */
    public static function cancellationSources(): iterable
    {
        yield 'draft' => [CreditApplication::STATUS_DRAFT];
        yield 'step 1' => [CreditApplication::STATUS_STEP_1_COMPLETED];
        yield 'step 2' => [CreditApplication::STATUS_STEP_2_COMPLETED];
        yield 'step 3' => [CreditApplication::STATUS_STEP_3_COMPLETED];
        yield 'ready for validation 1' => [CreditApplication::STATUS_READY_FOR_VALIDATION_1];
        yield 'validation 1 complete' => [CreditApplication::STATUS_VALIDATION_1_COMPLETED];
        yield 'validation 2' => [CreditApplication::STATUS_VALIDATION_2];
        yield 'submitted' => [CreditApplication::STATUS_SUBMITTED];
        yield 'staff approved' => [CreditApplication::STATUS_STAFF_APPROVED];
    }

    public function test_appointment_paths_include_the_required_review_and_proposal_states(): void
    {
        $confirmed = $this->paths->for(CreditApplication::STATUS_APPOINTMENT_CONFIRMED);
        $locked = $this->paths->for(CreditApplication::STATUS_APPOINTMENT_LOCKED);

        foreach ([$confirmed, $locked] as $path) {
            $this->assertTrue($this->paths->includes($path, CreditApplication::STATUS_SUBMITTED));
            $this->assertTrue($this->paths->includes($path, CreditApplication::STATUS_STAFF_APPROVED));
            $this->assertTrue($this->paths->includes($path, CreditApplication::STATUS_APPROVED));
            $this->assertTrue($this->paths->includes($path, CreditApplication::STATUS_APPOINTMENT_PROPOSED));
        }
    }

    public function test_unknown_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->paths->for('NOT_A_REAL_STATUS');
    }

    /** @param list<string> $path */
    private function assertLegalEdges(array $path): void
    {
        for ($index = 1; $index < count($path); $index++) {
            $from = $path[$index - 1];
            $to = $path[$index];
            $application = new CreditApplication;
            $application->forceFill(['status' => $from]);

            $this->assertTrue(
                $this->machine->canTransition($application, $to, $this->actorFor($from, $to)),
                "Synthetic path contains illegal transition {$from} -> {$to}.",
            );
        }
    }

    private function actorFor(string $from, string $to): User|StaffUser
    {
        if (in_array($to, [
            CreditApplication::STATUS_STAFF_APPROVED,
            CreditApplication::STATUS_STAFF_REJECTED,
        ], true)) {
            return $this->staff('staff');
        }

        if (in_array($to, [
            CreditApplication::STATUS_APPROVED,
            CreditApplication::STATUS_REJECTED,
        ], true)) {
            return $this->staff('admin');
        }

        if ($to === CreditApplication::STATUS_CANCELLED && in_array($from, [
            CreditApplication::STATUS_SUBMITTED,
            CreditApplication::STATUS_STAFF_APPROVED,
        ], true)) {
            return $this->staff('staff');
        }

        if ($from === CreditApplication::STATUS_APPROVED && $to === CreditApplication::STATUS_APPOINTMENT_PROPOSED) {
            return $this->staff('admin');
        }

        return new User;
    }

    private function staff(string $role): StaffUser
    {
        $staff = new StaffUser;
        $staff->forceFill(['role' => $role]);

        return $staff;
    }
}
