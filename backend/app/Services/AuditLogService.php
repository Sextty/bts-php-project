<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records security-relevant events (Part 9 of the auth plan). Never called with plaintext
 * passwords or OTP codes — only their existence/outcome (e.g. 'otp.failed', not the code that
 * failed). Takes ip/userAgent as explicit parameters rather than reaching into the global
 * `request()` helper, so this stays testable without a real HTTP request in scope.
 */
class AuditLogService
{
    public function log(
        string $action,
        ?User $user = null,
        array $previousState = [],
        array $newState = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?CreditApplication $application = null,
        ?StaffUser $staffUser = null,
    ): AuditLog {
        $data = [
            'user_id' => $user?->id,
            'staff_user_id' => $staffUser?->id,
            'credit_application_id' => $application?->id,
            'action' => $action,
            'previous_state' => $previousState ? $this->redactSensitiveValues($previousState) : null,
            'new_state' => $newState ? $this->redactSensitiveValues($newState) : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ];

        return DB::transaction(function () use ($data) {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            if ($head === null) {
                throw new RuntimeException('Audit integrity chain head is missing; refusing to persist an unchained audit event.');
            }

            $keyVersion = (string) config('audit.active_key_version');
            $key = $this->keyFor($keyVersion);
            $previousHash = $head->last_hash;
            $createdAt = now()->startOfSecond();
            $integrityHash = $this->hashFor($data, $previousHash, $createdAt, $keyVersion, $key);

            $log = AuditLog::create(array_merge($data, [
                'previous_hash' => $previousHash,
                'integrity_hash' => $integrityHash,
                'key_version' => $keyVersion,
                'created_at' => $createdAt,
            ]));

            DB::table('audit_chain_heads')->where('id', 1)->update([
                'last_hash' => $integrityHash,
                'last_audit_log_id' => $log->id,
                'key_version' => $keyVersion,
                'updated_at' => $createdAt,
            ]);

            return $log;
        });
    }

    /** @return array{valid: bool, checked: int, errors: list<string>} */
    public function verifyIntegrity(): array
    {
        $errors = [];
        $checked = 0;
        $expectedPrevious = null;
        $expectedHeadKeyVersion = null;
        $firstChainedId = AuditLog::query()->whereNotNull('integrity_hash')->min('id');

        if ($firstChainedId !== null && AuditLog::query()->where('id', '>', $firstChainedId)->whereNull('integrity_hash')->exists()) {
            $errors[] = 'Unchained audit rows exist after integrity protection was activated.';
        }

        foreach (AuditLog::query()->whereNotNull('integrity_hash')->orderBy('id')->cursor() as $log) {
            $checked++;

            if ($log->previous_hash !== $expectedPrevious) {
                $errors[] = "Audit log {$log->id} has a broken previous-hash link.";
            }

            $data = [
                'user_id' => $log->user_id,
                'staff_user_id' => $log->staff_user_id,
                'credit_application_id' => $log->credit_application_id,
                'action' => $log->action,
                'previous_state' => $log->previous_state,
                'new_state' => $log->new_state,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
            ];
            $keyVersion = (string) $log->key_version;
            if ($keyVersion === '') {
                $errors[] = "Audit log {$log->id} has no HMAC key version.";
                $expectedPrevious = $log->integrity_hash;

                continue;
            }

            try {
                $calculated = $this->hashFor(
                    $data,
                    $log->previous_hash,
                    $log->created_at,
                    $keyVersion,
                    $this->keyFor($keyVersion),
                );
            } catch (RuntimeException $exception) {
                $errors[] = "Audit log {$log->id} cannot be verified: {$exception->getMessage()}";
                $expectedPrevious = $log->integrity_hash;

                continue;
            }

            if (! hash_equals((string) $log->integrity_hash, $calculated)) {
                $errors[] = "Audit log {$log->id} content hash is invalid.";
            }

            $expectedPrevious = $log->integrity_hash;
            $expectedHeadKeyVersion = $keyVersion;
        }

        $head = DB::table('audit_chain_heads')->where('id', 1)->first();
        if ($head === null) {
            $errors[] = 'Audit chain head is missing.';
        } elseif ($head->last_hash !== $expectedPrevious) {
            $errors[] = 'Audit chain head does not match the final protected row.';
        } elseif ($expectedPrevious !== null && empty($head->key_version)) {
            $errors[] = 'Audit chain head has no HMAC key version.';
        } elseif ($expectedPrevious !== null && $head->key_version !== $expectedHeadKeyVersion) {
            $errors[] = 'Audit chain head key version does not match the final protected row.';
        }

        return ['valid' => $errors === [], 'checked' => $checked, 'errors' => $errors];
    }

    private function hashFor(array $data, ?string $previousHash, CarbonInterface $createdAt, string $keyVersion, string $key): string
    {
        $versioned = $keyVersion === 'legacy-app-key' ? [] : ['key_version' => $keyVersion];
        $payload = $this->canonicalize(array_merge($versioned, [
            'previous_hash' => $previousHash,
        ], $data, [
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]));

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $key);
    }

    private function keyFor(string $version): string
    {
        $key = (string) config("audit.keys.{$version}", '');
        if ($version === '' || $key === '') {
            throw new RuntimeException("HMAC key version [{$version}] is not configured.");
        }

        return $key;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** Defence-in-depth: secrets never enter the durable audit chain even if a caller errs. */
    private function redactSensitiveValues(array $value): array
    {
        $sensitiveKeys = [
            'password', 'password_confirmation', 'otp', 'otp_code', 'access_token',
            'pre_auth_token', 'google_id_token', 'authorization', 'cookie',
            'api_key', 'api_secret', 'client_secret', 'reset_token', 'token',
        ];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redactSensitiveValues($item);
            }
        }

        return $value;
    }
}
