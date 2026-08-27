<?php

namespace Tests\Feature\Staff;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin overview and Logs & Traffic authorization boundaries.
 */
class DashboardAndActivityTest extends TestCase
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

    private function seedAuditTrail(): CreditApplication
    {
        $customer = User::factory()->create();
        $application = CreditApplication::query()->forceCreate([
            'user_id' => $customer->id,
            'status' => CreditApplication::STATUS_SUBMITTED,
            'submitted_at' => now()->subHours(4),
        ]);

        AuditLog::create([
            'user_id' => $customer->id,
            'credit_application_id' => $application->id,
            'action' => 'credit_application.created',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (test)',
        ]);

        // An authentication event
        AuditLog::create([
            'user_id' => $customer->id,
            'action' => 'auth.login.password_verified',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (test)',
        ]);

        AuditLog::create([
            'user_id' => $customer->id,
            'action' => 'otp.requested',
            'ip_address' => '203.0.113.7',
        ]);

        return $application;
    }

    public function test_admin_dashboard_reports_kpis_pipeline_and_team(): void
    {
        $admin = $this->staff('admin');
        $application = $this->seedAuditTrail();

        AuditLog::create([
            'staff_user_id' => $admin->id,
            'credit_application_id' => $application->id,
            'action' => 'credit_application.admin_approved',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/staff/dashboard')->assertOk();

        $response->assertJsonPath('data.kpis.total', 1);
        $response->assertJsonPath('data.kpis.awaiting_staff', 1);
        $this->assertSame('SUBMITTED', $response->json('data.pipeline.0.status'));
        $this->assertSame($admin->id, $response->json('data.team.0.staff_user_id'));
        $this->assertSame(1, $response->json('data.team.0.approvals'));
        $this->assertCount(30, $response->json('data.timeline'));
    }

    public function test_staff_cannot_reach_the_admin_dashboard(): void
    {
        Sanctum::actingAs($this->staff('staff'), ['*']);

        $this->getJson('/api/staff/dashboard')->assertForbidden();
    }

    public function test_staff_cannot_reach_activity_since_audit_is_separated(): void
    {
        $this->seedAuditTrail();
        Sanctum::actingAs($this->staff('staff'), ['*']);

        $this->getJson('/api/staff/activity')->assertForbidden();
        $this->getJson('/api/staff/activity/traffic')->assertForbidden();
    }

    public function test_admin_sees_authentication_rows_and_network_details(): void
    {
        $this->seedAuditTrail();
        Sanctum::actingAs($this->staff('admin'), ['*']);

        $response = $this->getJson('/api/staff/activity')->assertOk();

        $this->assertSame(3, $response->json('data.meta.total'));
        $this->assertContains('auth.login.password_verified', $response->json('data.available_actions'));

        $withIp = collect($response->json('data.logs'))->firstWhere('ip_address', '203.0.113.7');
        $this->assertNotNull($withIp, 'Admin should receive ip_address.');
        $this->assertArrayHasKey('user_agent', $withIp);
    }

    public function test_security_user_sees_authentication_rows_and_network_details(): void
    {
        $this->seedAuditTrail();
        Sanctum::actingAs($this->staff('security'), ['*']);

        $response = $this->getJson('/api/security/activity')->assertOk();

        $this->assertSame(3, $response->json('data.meta.total'));
        $this->assertContains('auth.login.password_verified', $response->json('data.available_actions'));

        $withIp = collect($response->json('data.items'))->firstWhere('ip_address', '203.0.113.7');
        $this->assertNotNull($withIp, 'Security should receive ip_address.');
        $this->assertArrayHasKey('user_agent', $withIp);
    }

    public function test_a_customer_token_cannot_reach_activity_or_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/staff/activity')->assertForbidden();
        $this->getJson('/api/staff/dashboard')->assertForbidden();
    }
}
