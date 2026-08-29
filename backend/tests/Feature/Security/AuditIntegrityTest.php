<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_rows_form_a_verifiable_hash_chain(): void
    {
        $audit = app(AuditLogService::class);
        $user = User::factory()->create();

        $first = $audit->log('auth.login', $user, newState: ['result' => 'ok']);
        $second = $audit->log('credit_application.created', $user, newState: ['id' => 42]);

        $this->assertNotNull($first->integrity_hash);
        $this->assertSame($first->integrity_hash, $second->previous_hash);
        $this->assertTrue($audit->verifyIntegrity()['valid']);
    }

    public function test_tampering_is_detected_and_command_fails(): void
    {
        $audit = app(AuditLogService::class);
        $row = $audit->log('auth.login', User::factory()->create(), newState: ['result' => 'ok']);

        DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'auth.login.rewritten']);

        $this->assertFalse($audit->verifyIntegrity()['valid']);
        $this->artisan('audit:verify-integrity')->assertExitCode(1);
    }

    public function test_legacy_unchained_rows_before_activation_are_allowed(): void
    {
        AuditLog::create(['action' => 'legacy.imported']);

        $result = app(AuditLogService::class)->verifyIntegrity();

        $this->assertTrue($result['valid']);
        $this->assertSame(0, $result['checked']);
    }

    public function test_missing_chain_head_fails_closed(): void
    {
        DB::table('audit_chain_heads')->where('id', 1)->delete();

        $this->expectException(\RuntimeException::class);
        app(AuditLogService::class)->log('auth.login', User::factory()->create());
    }

    public function test_new_rows_record_the_configured_hmac_key_version(): void
    {
        config(['audit.active_key_version' => 'v-test', 'audit.keys.v-test' => 'synthetic-test-key']);

        $row = app(AuditLogService::class)->log('auth.login', User::factory()->create());

        $this->assertSame('v-test', $row->key_version);
        $this->assertTrue(app(AuditLogService::class)->verifyIntegrity()['valid']);
    }

    public function test_legacy_app_key_hash_format_remains_verifiable(): void
    {
        $createdAt = now()->startOfSecond();
        $data = [
            'previous_hash' => null,
            'user_id' => null,
            'staff_user_id' => null,
            'credit_application_id' => null,
            'action' => 'legacy.protected',
            'previous_state' => null,
            'new_state' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ];
        ksort($data);
        $hash = hash_hmac(
            'sha256',
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            (string) config('audit.keys.legacy-app-key'),
        );
        $row = AuditLog::create([
            'action' => 'legacy.protected',
            'previous_hash' => null,
            'integrity_hash' => $hash,
            'key_version' => 'legacy-app-key',
            'created_at' => $createdAt,
        ]);
        DB::table('audit_chain_heads')->where('id', 1)->update([
            'last_hash' => $hash,
            'last_audit_log_id' => $row->id,
            'key_version' => 'legacy-app-key',
        ]);

        $this->assertTrue(app(AuditLogService::class)->verifyIntegrity()['valid']);
    }

    public function test_sensitive_payload_values_are_redacted_before_hashing(): void
    {
        $row = app(AuditLogService::class)->log('security.test', newState: [
            'result' => 'denied',
            'password' => 'NeverPersistThis',
            'nested' => ['access_token' => 'secret-token', 'safe' => 'kept'],
        ]);

        $this->assertSame('[REDACTED]', $row->new_state['password']);
        $this->assertSame('[REDACTED]', $row->new_state['nested']['access_token']);
        $this->assertSame('kept', $row->new_state['nested']['safe']);
        $this->assertTrue(app(AuditLogService::class)->verifyIntegrity()['valid']);
    }

    public function test_eloquent_cannot_modify_or_delete_audit_rows(): void
    {
        $row = app(AuditLogService::class)->log('security.test');

        try {
            $row->update(['action' => 'security.rewritten']);
            $this->fail('Audit row update should have failed.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit logs are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $row->delete();
    }
}
