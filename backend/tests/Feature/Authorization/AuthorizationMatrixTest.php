<?php

namespace Tests\Feature\Authorization;

use App\Auth\PermissionRegistry;
use App\Broadcasting\ApplicationReportChannel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CreditApplication\CreditApplicationTestCase;

/**
 * The authorization matrix: every important role × action combination, checked over HTTP the way
 * the frontend would hit it — a permission that only exists in a unit test is a permission that
 * doesn't exist at all.
 *
 * Three layers are exercised here:
 *   1. capability — permission middleware + PermissionRegistry (who may do what);
 *   2. ownership — policies (whose rows), the IDOR/BOLA defence;
 *   3. scope — branch isolation (which branch a staff member sees), applied to lists, single
 *      records, the audit trail and the Reverb channels alike.
 */
class AuthorizationMatrixTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function as(mixed $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function staff(string $role = 'staff', ?Branch $branch = null): StaffUser
    {
        return StaffUser::factory()->create([
            'role' => $role,
            'branch_id' => $branch?->id,
        ]);
    }

    /** Drives one application through the customer flow and submits it (branch routing included). */
    private function submittedApplication(string $ville = 'Tunis'): CreditApplication
    {
        $this->as($this->user);

        $application = $this->newApplication();

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());

        $project = $this->validProjectPayload();
        $project['ville'] = $ville;
        $this->putJson("/api/applications/{$application->id}/project", $project);

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        return $application->fresh();
    }

    /** Runs an application through staff approval, admin final approval and three rejections —
     * ending APPOINTMENT_LOCKED, i.e. visible on the staff report inbox. */
    private function lockForReport(CreditApplication $application): void
    {
        $this->as($this->staff('admin'));
        $this->postJson("/api/staff/applications/{$application->id}/approve");
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve");

        $this->as($this->user);
        for ($i = 0; $i < \App\Models\Appointment::MAX_ATTEMPTS; $i++) {
            $this->postJson("/api/applications/{$application->id}/appointment/reject");
        }
    }

    private function subscribe(string $channel): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => Str::startsWith($channel, 'private-') ? $channel : 'private-'.$channel,
            'socket_id' => '1234567890.123456789',
        ]);
    }

    // ---- the registry and the role gate ------------------------------------------------

    public function test_registry_defines_every_requested_permission(): void
    {
        $requested = [
            'application.view',
            'application.create',
            'application.update',
            'application.review',
            'application.reject',
            'application.approve',
            'application.final_approve',
            'document.view',
            'document.verify',
            'appointment.manage',
            'reports.view',
            'audit.view',
            'users.manage',
        ];

        foreach ($requested as $value) {
            $this->assertNotNull(Permission::tryFrom($value), "missing permission: {$value}");
            $this->assertTrue(PermissionRegistry::has(Role::Admin->value, Permission::from($value)));
        }
    }

    public function test_future_roles_resolve_permission_sets_without_code_changes(): void
    {
        $this->assertTrue(PermissionRegistry::has(Role::SuperAdmin->value, Permission::UsersManage));
        $this->assertTrue(PermissionRegistry::has(Role::SuperAdmin->value, Permission::ApplicationFinalApprove));

        $this->assertTrue(PermissionRegistry::has(Role::SeniorStaff->value, Permission::DocumentVerify));
        $this->assertTrue(PermissionRegistry::has(Role::BranchManager->value, Permission::DocumentVerify));
        $this->assertTrue(PermissionRegistry::has(Role::BranchManager->value, Permission::AppointmentManage));
        $this->assertTrue(PermissionRegistry::has(Role::CreditOfficer->value, Permission::ApplicationApprove));

        // Plain staff never hold admin-level capabilities, even after the registry grows.
        $this->assertFalse(PermissionRegistry::has(Role::Staff->value, Permission::ApplicationFinalApprove));
        $this->assertFalse(PermissionRegistry::has(Role::Staff->value, Permission::UsersManage));
        $this->assertFalse(PermissionRegistry::has(Role::Staff->value, Permission::DocumentVerify));

        // Unknown role → zero permissions, not an exception (deny by default).
        $this->assertSame([], PermissionRegistry::permissionsFor('not_a_role'));
    }

    public function test_role_gate_is_hierarchy_aware(): void
    {
        $admin = $this->staff('admin');
        $staff = $this->staff('staff');

        $this->assertTrue($admin->isAtLeast('admin'));
        $this->assertTrue($staff->isAtLeast('staff'));
        $this->assertFalse($staff->isAtLeast('admin'));
    }

    // ---- client role ------------------------------------------------------------------

    public function test_client_can_use_their_own_customer_endpoints(): void
    {
        $application = $this->newApplication();

        $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())->assertOk();
    }

    public function test_client_cannot_touch_another_clients_application(): void
    {
        $other = User::factory()->create();
        $otherApplication = CreditApplication::factory()->create([
            'user_id' => $other->id,
            'status' => CreditApplication::STATUS_DRAFT,
        ]);

        $this->getJson("/api/applications/{$otherApplication->id}")->assertForbidden();
        $this->putJson("/api/applications/{$otherApplication->id}/client", $this->validClientPayload())->assertForbidden();
        $this->postJson("/api/applications/{$otherApplication->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ])->assertForbidden();
        $this->postJson("/api/applications/{$otherApplication->id}/report/messages", ['body' => 'hello'])
            ->assertForbidden();
    }

    public function test_client_cannot_reach_any_staff_route(): void
    {
        $this->as($this->user);

        $this->getJson('/api/staff/applications')->assertForbidden();
        $this->getJson('/api/staff/activity')->assertForbidden();
        $this->getJson('/api/staff/dashboard')->assertForbidden();
    }

    public function test_staff_token_cannot_reach_customer_routes(): void
    {
        $this->as($this->staff());

        $this->getJson('/api/applications')->assertForbidden();
        $this->postJson('/api/applications')->assertForbidden();
    }

    // ---- staff role -------------------------------------------------------------------

    public function test_staff_permissions_cover_the_review_flow(): void
    {
        Branch::factory()->default()->create();
        $application = $this->submittedApplication();
        $this->as($this->staff());

        $this->getJson('/api/staff/applications')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/staff/applications/{$application->id}")->assertOk();
        $this->postJson("/api/staff/applications/{$application->id}/approve")->assertOk();
    }

    public function test_staff_is_blocked_from_admin_only_actions(): void
    {
        $application = $this->submittedApplication();
        $this->as($this->staff());

        $this->getJson('/api/staff/dashboard')->assertForbidden();
        $this->postJson("/api/staff/applications/{$application->id}/admin-approve")->assertForbidden();
        $this->postJson("/api/staff/applications/{$application->id}/admin-reject", ['reason' => 'N/A'])
            ->assertForbidden();
    }

    public function test_suspended_staff_is_blocked_on_every_staff_route(): void
    {
        $this->as(StaffUser::factory()->create(['status' => 'suspended']));

        $this->getJson('/api/staff/applications')->assertForbidden();
        $this->getJson('/api/staff/activity')->assertForbidden();
        $this->getJson('/api/staff/dashboard')->assertForbidden();
    }

    // ---- admin role -------------------------------------------------------------------

    public function test_admin_can_reach_every_staff_endpoint(): void
    {
        Branch::factory()->default()->create();

        $application = $this->submittedApplication();
        $this->as($this->staff('admin'));

        $this->getJson('/api/staff/dashboard')->assertOk();
        $this->getJson('/api/staff/activity')->assertOk();
        $this->getJson('/api/staff/reports')->assertOk();
        $this->getJson("/api/staff/applications/{$application->id}")->assertOk();
        $this->postJson("/api/staff/applications/{$application->id}/approve")->assertOk();
    }

    // ---- branch isolation ---------------------------------------------------------------

    public function test_branch_assigned_staff_are_isolated_to_their_own_branch(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appTunis = $this->submittedApplication('Tunis');
        $appSfax = $this->submittedApplication('Sfax');

        $this->assertSame($tunis->id, $appTunis->fresh()->branch_id);
        $this->assertSame($sfax->id, $appSfax->fresh()->branch_id);

        $this->as($this->staff('staff', $tunis));

        // List scoping: the review queue only ever contains this branch's files.
        $this->getJson('/api/staff/applications')->assertOk()->assertJsonPath('data.meta.total', 1);

        // A guessed URL to the other branch's file is denied with 403 — the denial must not be
        // a silent filter, or the response itself would leak that the file exists.
        $this->getJson("/api/staff/applications/{$appSfax->id}")->assertForbidden();
        $this->postJson("/api/staff/applications/{$appTunis->id}/approve")->assertOk();
        $this->postJson("/api/staff/applications/{$appSfax->id}/approve")->assertForbidden();

        // Symmetric for the other branch.
        $this->as($this->staff('staff', $sfax));
        $this->getJson('/api/staff/applications')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/staff/applications/{$appTunis->id}")->assertForbidden();
    }

    public function test_unrouted_applications_are_invisible_to_branch_assigned_staff(): void
    {
        // Submitted while no branch was configured → branch_id stays null.
        $appUnrouted = $this->submittedApplication('Sidi Bouzid');

        $branch = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $appRouted = $this->submittedApplication('Tunis');

        $this->assertNull($appUnrouted->fresh()->branch_id);
        $this->assertSame($branch->id, $appRouted->fresh()->branch_id);

        $this->as($this->staff('staff', $branch));
        $this->getJson('/api/staff/applications')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/staff/applications/{$appUnrouted->id}")->assertForbidden();

        // Unassigned staff and admins stay global.
        $this->as($this->staff());
        $this->getJson('/api/staff/applications')->assertOk()->assertJsonPath('data.meta.total', 2);

        $this->as($this->staff('admin'));
        $this->getJson("/api/staff/applications/{$appUnrouted->id}")->assertOk();
    }

    public function test_admin_is_never_branch_restricted(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appSfax = $this->submittedApplication('Sfax');
        $this->as($this->staff('admin', $tunis));

        $this->getJson("/api/staff/applications/{$appSfax->id}")->assertOk();
    }

    public function test_report_surface_is_branch_scoped(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appTunis = $this->submittedApplication('Tunis');
        $appSfax = $this->submittedApplication('Sfax');
        $this->lockForReport($appTunis);
        $this->lockForReport($appSfax);

        $this->as($this->staff('staff', $tunis));

        $this->getJson('/api/staff/reports')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/staff/reports/{$appTunis->id}/messages")->assertOk();
        $this->getJson("/api/staff/reports/{$appSfax->id}/messages")->assertForbidden();
        $this->postJson("/api/staff/reports/{$appSfax->id}/messages", ['body' => 'hello'])
            ->assertForbidden();
    }

    public function test_activity_trail_is_scoped_to_the_viewers_branch(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appTunis = $this->submittedApplication('Tunis');
        $appSfax = $this->submittedApplication('Sfax');

        $this->as($this->staff('staff', $tunis));
        $this->getJson("/api/staff/activity?application_id={$appSfax->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
        $this->assertGreaterThan(
            0,
            $this->getJson("/api/staff/activity?application_id={$appTunis->id}")->json('data.meta.total'),
        );

        // An unassigned staff member sees both branches' rows.
        $this->as($this->staff());
        $this->assertGreaterThan(
            0,
            $this->getJson("/api/staff/activity?application_id={$appSfax->id}")->json('data.meta.total'),
        );
    }

    // ---- Reverb channels ----------------------------------------------------------------

    public function test_report_channel_is_limited_to_the_owner_and_authorized_staff(): void
    {
        // The test env's default broadcaster is `null`, whose auth() always grants — the
        // channel callbacks would never run. The pusher driver performs the whole access
        // decision locally (denied → 403; granted → signed response, no network), so it is the
        // right driver to exercise the authorization logic over the real /broadcasting/auth
        // endpoint. Channel handlers are registered on the *default* connection when
        // routes/channels.php loads at boot, so the report-channel gate must be re-registered
        // here on the pusher connection — same handler class, same rules.
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'cluster' => 'mt1',
                    'host' => 'api-mt1.pusher.com',
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'client_options' => [],
            ],
        ]);
        Broadcast::channel('application.{applicationId}.report', ApplicationReportChannel::class);

        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appTunis = $this->submittedApplication('Tunis');
        $appSfax = $this->submittedApplication('Sfax');

        // The owner may subscribe to their own application's channel.
        $this->as($this->user);
        $this->subscribe("private-application.{$appTunis->id}.report")->assertOk();

        // Another customer may not — the same IDOR rule as the HTTP report routes.
        $this->as(User::factory()->create());
        $this->subscribe("private-application.{$appTunis->id}.report")->assertForbidden();

        // Branch-assigned staff only subscribe to their branch's channels.
        $this->as($this->staff('staff', $tunis));
        $this->subscribe("private-application.{$appTunis->id}.report")->assertOk();
        $this->subscribe("private-application.{$appSfax->id}.report")->assertForbidden();

        // Admins are never branch-restricted.
        $this->as($this->staff('admin'));
        $this->subscribe("private-application.{$appSfax->id}.report")->assertOk();

        // Suspended staff subscribe nowhere.
        $this->as(StaffUser::factory()->create(['status' => 'suspended']));
        $this->subscribe("private-application.{$appTunis->id}.report")->assertForbidden();
    }
}
