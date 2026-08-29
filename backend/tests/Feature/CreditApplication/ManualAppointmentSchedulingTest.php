<?php

namespace Tests\Feature\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\AppNotification;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AppointmentSchedulingService;
use App\Services\AuditLogService;
use App\Services\BranchMatchingService;
use App\Services\CreditApplicationStateMachine;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ManualAppointmentSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-24 08:00:00'); // Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_reschedule_is_atomic_and_attempts_are_monotonic(): void
    {
        [$branch, $staff, $application] = $this->fixture();
        $service = app(AppointmentSchedulingService::class);

        $first = $service->scheduleManual($application, $staff, $branch->id, '2026-08-25', '10:00', false);
        $second = $service->scheduleManual($application->fresh(), $staff, $branch->id, '2026-08-26', '11:00', true);

        $this->assertSame(1, $first->attempt_number);
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame(Appointment::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(Appointment::STATUS_ACCEPTED, $second->fresh()->status);
        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $application->fresh()->status);
    }

    public function test_current_working_day_is_allowed(): void
    {
        [$branch, $staff, $application] = $this->fixture();

        $appointment = app(AppointmentSchedulingService::class)
            ->scheduleManual($application, $staff, $branch->id, '2026-08-24', '10:00');

        $this->assertSame('2026-08-24', $appointment->scheduled_date->toDateString());
    }

    public function test_replayed_http_request_does_not_duplicate_external_notification_delivery(): void
    {
        [$branch, $staff, $application] = $this->fixture();
        Sanctum::actingAs($staff, ['*']);
        $payload = [
            'branch_id' => $branch->id,
            'scheduled_date' => '2026-08-25',
            'scheduled_time' => '10:00',
            'direct_confirm' => true,
        ];

        $this->postJson("/api/staff/reports/{$application->id}/appointment", $payload)->assertOk();
        $this->postJson("/api/staff/reports/{$application->id}/appointment", $payload)->assertOk();

        $this->assertSame(1, Appointment::where('credit_application_id', $application->id)->count());
        $this->assertSame(1, AppNotification::where('type', 'appointment.scheduled')->count());
    }

    public function test_same_slot_and_daily_capacity_cannot_be_double_booked(): void
    {
        [$branch, $staff, $firstApplication] = $this->fixture(['daily_capacity' => 1]);
        $secondApplication = $this->application($branch);
        $service = app(AppointmentSchedulingService::class);
        $service->scheduleManual($firstApplication, $staff, $branch->id, '2026-08-25', '10:00');

        try {
            $service->scheduleManual($secondApplication, $staff, $branch->id, '2026-08-25', '11:00');
            $this->fail('Capacity exhaustion should reject the second appointment.');
        } catch (ApiException $exception) {
            $this->assertSame(ApiErrorCode::NoSlotsAvailable, $exception->error);
        }

        $this->assertSame(1, Appointment::count());
        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_LOCKED, $secondApplication->fresh()->status);
    }

    public function test_branch_restricted_staff_cannot_reroute_an_application(): void
    {
        [$branch, $staff, $application] = $this->fixture();
        $otherBranch = Branch::factory()->create();

        $this->expectException(ApiException::class);
        app(AppointmentSchedulingService::class)->scheduleManual($application, $staff, $otherBranch->id, '2026-08-25', '10:00');
    }

    public function test_audit_failure_rolls_back_state_branch_and_appointment(): void
    {
        $branch = Branch::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        $application = CreditApplication::factory()->create([
            'user_id' => User::factory(),
            'branch_id' => null,
            'status' => CreditApplication::STATUS_APPOINTMENT_LOCKED,
        ]);

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('log')->once()->andThrow(new RuntimeException('synthetic audit failure'));
        $service = new AppointmentSchedulingService(
            app(BranchMatchingService::class),
            $audit,
            new CreditApplicationStateMachine($audit),
            app(NotificationService::class),
        );

        try {
            $service->scheduleManual($application, $admin, $branch->id, '2026-08-25', '10:00');
            $this->fail('The synthetic audit failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }

        $application->refresh();
        $this->assertSame(CreditApplication::STATUS_APPOINTMENT_LOCKED, $application->status);
        $this->assertNull($application->branch_id);
        $this->assertSame(0, Appointment::count());
    }

    /** @return array{Branch, StaffUser, CreditApplication} */
    private function fixture(array $branchOverrides = []): array
    {
        $branch = Branch::factory()->create($branchOverrides);
        $staff = StaffUser::factory()->forBranch($branch)->create();

        return [$branch, $staff, $this->application($branch)];
    }

    private function application(Branch $branch): CreditApplication
    {
        return CreditApplication::factory()->create([
            'user_id' => User::factory(),
            'branch_id' => $branch->id,
            'status' => CreditApplication::STATUS_APPOINTMENT_LOCKED,
        ]);
    }
}
