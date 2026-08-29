<?php

namespace Tests\Feature\Analytics;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\CreditRequest;
use App\Models\Project;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private StaffUser $admin;

    private StaffUser $staffA;

    /** @var list<CreditApplication> */
    private array $applications = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-24 10:00:00');

        $this->branchA = Branch::factory()->create(['name' => 'Agence Tunis', 'ville' => 'Tunis', 'daily_capacity' => 4]);
        $this->branchB = Branch::factory()->create(['name' => 'Agence Sfax', 'ville' => 'Sfax', 'daily_capacity' => 4]);
        $this->admin = StaffUser::factory()->admin()->create();
        $this->staffA = StaffUser::factory()->forBranch($this->branchA)->create();

        $this->seedDeterministicPortfolio();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_analytics_are_mathematically_correct(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/staff/analytics/overview?from=2026-01-01&to=2026-12-31')->assertOk();

        $response->assertJsonPath('data.kpis.total_applications', 100);
        $response->assertJsonPath('data.kpis.approved', 60);
        $response->assertJsonPath('data.kpis.rejected', 25);
        $response->assertJsonPath('data.kpis.pending', 15);
        $response->assertJsonPath('data.kpis.approval_rate', 70.6);
        $response->assertJsonPath('data.kpis.requested_amount_total', 100000);
        $response->assertJsonPath('data.credit.financing_composition.EQP', 50000);
        $response->assertJsonPath('data.credit.financing_composition.FDR', 20000);
        $response->assertJsonPath('data.credit.financing_composition.AMG', 20000);
        $response->assertJsonPath('data.credit.financing_composition.CHP', 10000);
        $this->assertSame(100, collect($response->json('data.timeline'))->sum('created'));
        $this->assertSame(60, collect($response->json('data.timeline'))->sum('approved'));
        $this->assertSame(25, collect($response->json('data.timeline'))->sum('rejected'));
        $this->assertSame(100, collect($response->json('data.branches'))->sum('application_volume'));
        $this->assertSame(
            CreditApplication::STATUS_APPROVED,
            DB::table('audit_logs')->where('action', 'credit_application.status_changed')->value('analytics_status'),
        );
    }

    public function test_staff_analytics_are_branch_scoped_and_parameter_tampering_is_denied(): void
    {
        Sanctum::actingAs($this->staffA, ['*']);

        $response = $this->getJson('/api/staff/analytics/overview?from=2026-01-01&to=2026-12-31')->assertOk();
        $response->assertJsonPath('data.meta.scope', 'branch');
        $response->assertJsonPath('data.meta.branch_id', $this->branchA->id);
        $response->assertJsonPath('data.kpis.total_applications', 50);
        $response->assertJsonCount(1, 'data.branches');

        $this->getJson("/api/staff/analytics/overview?branch_id={$this->branchB->id}")->assertForbidden();
    }

    public function test_security_and_customer_accounts_cannot_read_financial_analytics(): void
    {
        Sanctum::actingAs(StaffUser::factory()->create(['role' => 'security']), ['*']);
        $this->getJson('/api/staff/analytics/overview')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson('/api/staff/analytics/overview')->assertForbidden();
    }

    public function test_date_and_status_filters_are_bounded_and_correct(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/staff/analytics/overview?from=2026-01-01&to=2026-12-31&status=APPROVED')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_applications', 60);

        $this->getJson('/api/staff/analytics/overview?from=2026-12-31&to=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/staff/analytics/overview?from=2020-01-01&to=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/staff/analytics/overview?status=INVENTED')->assertUnprocessable();
    }

    public function test_appointment_and_workflow_metrics_are_aggregated_without_raw_customer_data(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/staff/analytics/overview?from=2026-01-01&to=2026-12-31')->assertOk();

        $response->assertJsonPath('data.appointments.total', 4);
        $response->assertJsonPath('data.appointments.accepted', 2);
        $response->assertJsonPath('data.appointments.proposed', 1);
        $response->assertJsonPath('data.appointments.rejected', 1);
        $response->assertJsonPath('data.workflow.stages.0.count', 100);
        $this->assertNotNull($response->json('data.workflow.average_hours.creation_to_submission'));
        $this->assertStringNotContainsString('@example', $response->getContent());
    }

    public function test_aggregate_export_respects_branch_scope_and_is_audited(): void
    {
        Sanctum::actingAs($this->staffA, ['*']);

        $response = $this->get('/api/staff/analytics/export?from=2026-01-01&to=2026-12-31&dataset=branches&format=csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Agence Tunis', $content);
        $this->assertStringNotContainsString('Agence Sfax', $content);
        $this->assertStringNotContainsString('@example', $content);
        $this->assertDatabaseHas('audit_logs', [
            'staff_user_id' => $this->staffA->id,
            'action' => 'analytics.aggregate_exported',
        ]);
    }

    public function test_aggregate_export_refuses_results_above_the_configured_limit(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        config()->set('analytics.export_max_rows', 1);

        $this->getJson('/api/staff/analytics/export?from=2026-01-01&to=2026-12-31&dataset=timeline&format=json')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ANALYTICS_EXPORT_LIMIT');
    }

    public function test_data_quality_reports_real_violations_and_is_admin_only(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson('/api/staff/analytics/data-quality')
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.total_violations', 0);

        CreditRequest::query()->firstOrFail()->forceFill(['montant_fdr' => 201])->save();
        $this->getJson('/api/staff/analytics/data-quality')
            ->assertOk()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.checks.financing_breakdown_matches_requested_total.violations', 1);

        Sanctum::actingAs($this->staffA, ['*']);
        $this->getJson('/api/staff/analytics/data-quality')->assertForbidden();
    }

    private function seedDeterministicPortfolio(): void
    {
        for ($index = 0; $index < 100; $index++) {
            $branch = $index < 50 ? $this->branchA : $this->branchB;
            $status = $index < 60
                ? CreditApplication::STATUS_APPROVED
                : ($index < 85 ? CreditApplication::STATUS_REJECTED : CreditApplication::STATUS_SUBMITTED);
            $createdAt = Carbon::parse('2026-08-01 08:00:00')->addMinutes($index);
            $submittedAt = $createdAt->copy()->addHours(24);
            $user = User::factory()->create();
            $application = CreditApplication::query()->forceCreate([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'status' => $status,
                'submitted_at' => $submittedAt,
                'decided_by_staff_user_id' => $status === CreditApplication::STATUS_SUBMITTED ? null : $this->staffA->id,
                'decided_by_admin_user_id' => $status === CreditApplication::STATUS_APPROVED ? $this->admin->id : null,
                'created_at' => $createdAt,
                'updated_at' => $submittedAt->copy()->addHours(4),
            ]);
            CreditRequest::query()->create([
                'credit_application_id' => $application->id,
                'n_demande' => sprintf('AN-%04d', $index + 1),
                'type_demande' => $index % 2 === 0 ? 'création' : 'extension',
                'code_devise' => 'TND',
                'montant_global_sollicite' => 1000,
                'montant_eqp' => 500,
                'montant_fdr' => 200,
                'montant_amg' => 200,
                'montant_chp' => 100,
            ]);
            Project::query()->create([
                'credit_application_id' => $application->id,
                'code_projet' => sprintf('PR-AN-%04d', $index + 1),
                'ville' => $branch->ville,
                'delegation' => $branch->ville.' Centre',
                'type_projet' => 'micro-entreprise',
                'activite' => $index % 2 === 0 ? 'Commerce' : 'Services',
            ]);

            if ($status !== CreditApplication::STATUS_SUBMITTED) {
                AuditLog::query()->create([
                    'credit_application_id' => $application->id,
                    'staff_user_id' => $this->admin->id,
                    'action' => $index === 0
                        ? 'credit_application.status_changed'
                        : ($status === CreditApplication::STATUS_APPROVED
                            ? 'credit_application.admin_approved'
                            : 'credit_application.admin_rejected'),
                    'new_state' => $index === 0 ? ['status' => CreditApplication::STATUS_APPROVED] : null,
                    'created_at' => $submittedAt->copy()->addHours(4),
                ]);
            }

            $this->applications[] = $application;
        }

        $appointments = [
            [0, Appointment::STATUS_ACCEPTED, $this->branchA, '2026-09-01', '08:00:00'],
            [1, Appointment::STATUS_ACCEPTED, $this->branchA, '2026-09-02', '08:00:00'],
            [50, Appointment::STATUS_PROPOSED, $this->branchB, '2026-09-03', '08:00:00'],
            [51, Appointment::STATUS_REJECTED, $this->branchB, '2026-09-04', '08:00:00'],
        ];
        foreach ($appointments as [$applicationIndex, $status, $branch, $date, $time]) {
            Appointment::query()->create([
                'credit_application_id' => $this->applications[$applicationIndex]->id,
                'branch_id' => $branch->id,
                'attempt_number' => 1,
                'scheduled_date' => $date,
                'scheduled_time' => $time,
                'status' => $status,
                'is_auto_scheduled_future' => true,
                'decided_at' => $status === Appointment::STATUS_PROPOSED ? null : now(),
                'created_at' => '2026-08-25 08:00:00',
            ]);
        }
    }
}
