<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityCenterTest extends TestCase
{
    use RefreshDatabase;

    private function createStaff(string $role = 'staff', ?Branch $branch = null): StaffUser
    {
        return StaffUser::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Officer',
            'email' => $role.'-'.uniqid().'@bts.test',
            'password' => bcrypt('password12345'),
            'role' => $role,
            'status' => 'active',
            'branch_id' => $branch?->id,
        ]);
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/security/dashboard')->assertUnauthorized();
        $this->getJson('/api/security/activity')->assertUnauthorized();
        $this->getJson('/api/security/users')->assertUnauthorized();
    }

    public function test_customer_user_is_rejected_from_security_endpoints_with_403(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['*']);

        $this->getJson('/api/security/dashboard')->assertForbidden();
        $this->getJson('/api/security/activity')->assertForbidden();
        $this->getJson('/api/security/users')->assertForbidden();
        $this->getJson('/api/security/telemetry')->assertForbidden();
    }

    public function test_regular_staff_is_rejected_from_security_endpoints_with_403(): void
    {
        $staff = $this->createStaff('staff');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/security/dashboard')->assertForbidden();
        $this->getJson('/api/security/activity')->assertForbidden();
        $this->getJson('/api/security/users')->assertForbidden();
        $this->postJson('/api/security/users/1/suspend', ['reason' => 'Test'])->assertForbidden();
        $this->getJson('/api/security/telemetry')->assertForbidden();
        $this->getJson('/api/security/osquery/status')->assertForbidden();
    }

    public function test_security_user_can_login_via_security_login_endpoint(): void
    {
        $securityUser = StaffUser::create([
            'first_name' => 'Farid',
            'last_name' => 'Security',
            'email' => 'sec-operator@bts.test',
            'password' => bcrypt('secretpassword123'),
            'role' => 'security',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/security/auth/login', [
            'email' => 'sec-operator@bts.test',
            'password' => 'secretpassword123',
        ])->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'access_token',
                'staff_user' => ['id', 'email', 'role'],
            ],
        ]);

        $this->assertSame('security', $response->json('data.staff_user.role'));
    }

    public function test_security_user_can_access_dashboard_and_retrieve_real_metrics(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        AuditLog::create([
            'action' => 'auth.test_login',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->getJson('/api/security/dashboard')->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'metrics' => [
                    'events_today',
                    'total_events',
                    'active_customers',
                    'suspended_customers',
                    'active_staff',
                    'open_ports_count',
                ],
                'system' => ['os_name', 'platform', 'uptime_days'],
                'traffic',
                'recent_events',
            ],
        ]);

        $this->assertGreaterThanOrEqual(1, $response->json('data.metrics.total_events'));
    }

    public function test_security_user_can_list_and_filter_activity(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        AuditLog::create([
            'action' => 'security.threat_detected',
            'ip_address' => '192.168.1.50',
            'user_agent' => 'Mozilla/5.0 Test Browser',
        ]);

        $response = $this->getJson('/api/security/activity?action=security.threat_detected')->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'items',
                'meta' => ['current_page', 'total'],
                'available_actions',
            ],
        ]);

        $this->assertGreaterThanOrEqual(1, $response->json('data.meta.total'));
        $this->assertSame('security.threat_detected', $response->json('data.items.0.action'));
    }

    public function test_security_user_can_suspend_customer_with_mandatory_reason_and_audit_log(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        $customer = User::factory()->create(['status' => 'active']);
        $customer->createToken('test-customer-token');
        $this->assertGreaterThan(0, $customer->tokens()->count());

        // Test without reason -> 422
        $this->postJson("/api/security/users/{$customer->id}/suspend", [])
            ->assertStatus(422);

        // Test with reason -> 200
        $response = $this->postJson("/api/security/users/{$customer->id}/suspend", [
            'reason' => 'Suspicious automated login attempts detected',
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('suspended', $customer->status);
        $this->assertSame('Suspicious automated login attempts detected', $customer->banned_reason);
        $this->assertSame(0, $customer->tokens()->count(), 'Tokens must be revoked on suspension.');

        $log = AuditLog::where('action', 'security.user_suspended')
            ->where('user_id', $customer->id)
            ->first();
        $this->assertNotNull($log, 'Suspension must generate an audit log record.');
        $this->assertSame($security->id, $log->staff_user_id);
    }

    public function test_security_user_can_unsuspend_customer_and_audit_log(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        $customer = User::factory()->create([
            'status' => 'suspended',
            'banned_at' => now(),
            'banned_reason' => 'Previous security breach',
        ]);

        $response = $this->postJson("/api/security/users/{$customer->id}/unsuspend")->assertOk();

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertNull($customer->banned_at);

        $log = AuditLog::where('action', 'security.user_unsuspended')
            ->where('user_id', $customer->id)
            ->first();
        $this->assertNotNull($log);
    }

    public function test_security_user_can_revoke_user_tokens(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        $customer = User::factory()->create();
        $customer->createToken('token-1');
        $customer->createToken('token-2');
        $this->assertSame(2, $customer->tokens()->count());

        $response = $this->postJson("/api/security/users/{$customer->id}/revoke-tokens")->assertOk();

        $this->assertSame(2, $response->json('data.revoked_count'));
        $this->assertSame(0, $customer->tokens()->count());

        $log = AuditLog::where('action', 'security.tokens_revoked')
            ->where('user_id', $customer->id)
            ->first();
        $this->assertNotNull($log);
    }

    public function test_security_user_can_revoke_staff_tokens_without_touching_same_id_customer(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        $targetStaff = $this->createStaff('staff');
        $targetStaff->createToken('staff-token');
        $customer = User::factory()->create(['id' => $targetStaff->id]);
        $customer->createToken('customer-token');

        $response = $this->postJson(
            "/api/security/users/{$targetStaff->id}/revoke-tokens",
            ['type' => 'staff'],
        )->assertOk();

        $this->assertSame(1, $response->json('data.revoked_count'));
        $this->assertSame(0, $targetStaff->tokens()->count());
        $this->assertSame(1, $customer->tokens()->count());

        $log = AuditLog::where('action', 'security.tokens_revoked')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertSame('staff', $log->previous_state['target_type']);
        $this->assertSame($targetStaff->id, $log->previous_state['target_id']);
    }

    public function test_security_user_can_access_data_audit_endpoints(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        $branch = Branch::factory()->create();
        $app = CreditApplication::factory()->create(['branch_id' => $branch->id]);

        $responseApps = $this->getJson('/api/security/data/applications')->assertOk();
        $this->assertGreaterThanOrEqual(1, $responseApps->json('data.meta.total'));

        $responseDocs = $this->getJson('/api/security/data/documents')->assertOk();
        $this->assertArrayHasKey('items', $responseDocs->json('data'));

        $responseApts = $this->getJson('/api/security/data/appointments')->assertOk();
        $this->assertArrayHasKey('items', $responseApts->json('data'));
    }

    public function test_security_user_can_query_osquery_and_execute_vulnerability_scan(): void
    {
        $security = $this->createStaff('security');
        Sanctum::actingAs($security, ['*']);

        // Status
        $this->getJson('/api/security/osquery/status')->assertOk();

        // Query
        $responseQ = $this->postJson('/api/security/osquery/query', [
            'sql' => 'SELECT hostname, cpu_brand FROM system_info;',
        ])->assertOk();
        $this->assertSame(1, $responseQ->json('data.count'));

        // Scan vulnerabilities
        $responseScan = $this->postJson('/api/security/vulnerabilities/scan')->assertOk();
        $responseScan->assertJsonStructure([
            'success',
            'data' => [
                'ports_analyzed_count',
                'findings',
                'summary' => ['critical_count', 'warning_count', 'overall_risk'],
            ],
        ]);

        $log = AuditLog::where('action', 'security.vulnerability_scanned')->first();
        $this->assertNotNull($log);
    }

    public function test_staff_branch_isolation_remains_intact(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);

        $staffA = $this->createStaff('staff', $branchA);
        $appB = CreditApplication::factory()->create(['branch_id' => $branchB->id]);

        Sanctum::actingAs($staffA, ['*']);

        $this->getJson("/api/staff/applications/{$appB->id}")->assertForbidden();
    }
}
