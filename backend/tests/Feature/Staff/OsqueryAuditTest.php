<?php

namespace Tests\Feature\Staff;

use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OsqueryAuditTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'staff'): StaffUser
    {
        return StaffUser::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Member',
            'email' => $role.'-'.uniqid().'@bts.test',
            'password' => bcrypt('password12345'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_retrieve_osquery_status(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/staff/insights/osquery/status')->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'native_available',
                'engine_mode',
                'version',
                'tables_count',
                'tables',
                'presets_count',
            ],
        ]);

        $this->assertGreaterThanOrEqual(10, $response->json('data.tables_count'));
        $this->assertArrayHasKey('system_info', $response->json('data.tables'));
        $this->assertArrayHasKey('listening_ports', $response->json('data.tables'));
        $this->assertArrayHasKey('interface_details', $response->json('data.tables'));
    }

    public function test_staff_cannot_access_osquery_endpoints(): void
    {
        $staff = $this->staff('staff');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/staff/insights/osquery/status')->assertForbidden();
        $this->postJson('/api/staff/insights/osquery/query', ['sql' => 'SELECT * FROM system_info;'])->assertForbidden();
        $this->getJson('/api/staff/insights/osquery/presets')->assertForbidden();
        $this->getJson('/api/staff/insights/osquery/quick-audit')->assertForbidden();
    }

    public function test_customer_cannot_access_osquery_endpoints(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['*']);

        $this->getJson('/api/staff/insights/osquery/status')->assertForbidden();
        $this->postJson('/api/staff/insights/osquery/query', ['sql' => 'SELECT * FROM system_info;'])->assertForbidden();
    }

    public function test_admin_can_retrieve_osquery_presets(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/staff/insights/osquery/presets')->assertOk();

        $this->assertIsArray($response->json('data'));
        $this->assertNotEmpty($response->json('data'));

        $firstCategory = $response->json('data.0');
        $this->assertArrayHasKey('category', $firstCategory);
        $this->assertArrayHasKey('title', $firstCategory);
        $this->assertArrayHasKey('queries', $firstCategory);
        $this->assertNotEmpty($firstCategory['queries']);
    }

    public function test_admin_can_execute_system_info_query(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT hostname, hardware_vendor, hardware_model, cpu_brand, physical_memory FROM system_info;',
        ])->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'sql',
                'columns',
                'rows',
                'count',
                'execution_time_ms',
                'engine_mode',
            ],
        ]);

        $this->assertSame(1, $response->json('data.count'));
        $this->assertContains('hostname', $response->json('data.columns'));
        $this->assertContains('cpu_brand', $response->json('data.columns'));
    }

    public function test_admin_can_execute_listening_ports_query(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT pid, port, protocol, address, process_name, state FROM listening_ports WHERE port != 0 ORDER BY port ASC;',
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('data.count'));
        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('port', $rows[0]);
        $this->assertArrayHasKey('process_name', $rows[0]);
    }

    public function test_destructive_or_invalid_sql_is_rejected(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        // DROP query
        $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'DROP TABLE system_info;',
        ])->assertStatus(400);

        // DELETE query
        $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'DELETE FROM processes WHERE pid = 4;',
        ])->assertStatus(400);

        // UPDATE query
        $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'UPDATE users SET username = "hacker";',
        ])->assertStatus(400);

        // Unknown table
        $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT * FROM unknown_table_xyz;',
        ])->assertStatus(400);
    }

    public function test_osquery_query_execution_creates_audit_log_entry(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $sql = 'SELECT interface, mac, type, is_primary FROM interface_details;';

        $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => $sql,
        ])->assertOk();

        $log = AuditLog::where('action', 'audit.osquery_query')
            ->where('staff_user_id', $admin->id)
            ->first();

        $this->assertNotNull($log, 'An audit log entry should be recorded when an admin runs an Osquery query.');
        $this->assertSame(hash('sha256', $sql), $log->previous_state['sql_sha256']);
        $this->assertSame(['interface_details'], $log->previous_state['tables']);
        $this->assertArrayHasKey('result_rows', $log->new_state);
        $this->assertArrayHasKey('execution_time_ms', $log->new_state);
    }

    public function test_admin_can_query_audit_logs_table_via_osquery(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        AuditLog::create([
            'staff_user_id' => $admin->id,
            'action' => 'auth.test_event',
            'ip_address' => '10.0.0.1',
        ]);

        $response = $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT id, action, actor_name, ip_address FROM audit_logs WHERE action = "auth.test_event" LIMIT 10;',
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('data.count'));
        $this->assertContains('action', $response->json('data.columns'));
        $this->assertContains('actor_name', $response->json('data.columns'));
    }

    public function test_admin_can_query_memory_info_and_routes(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $responseMem = $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT memory_total, memory_free, memory_available FROM memory_info;',
        ])->assertOk();

        $this->assertSame(1, $responseMem->json('data.count'));

        $responseRoutes = $this->postJson('/api/staff/insights/osquery/query', [
            'sql' => 'SELECT destination, netmask, gateway, interface FROM routes;',
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, $responseRoutes->json('data.count'));
    }

    public function test_admin_can_run_quick_audit_scan(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/staff/insights/osquery/quick-audit')->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => [
                'system',
                'os',
                'open_ports_count',
                'open_ports',
                'logged_users_count',
                'logged_users',
                'active_interfaces_count',
                'active_interfaces',
                'disks',
                'execution_time_ms',
                'scanned_at',
            ],
        ]);
    }
}
