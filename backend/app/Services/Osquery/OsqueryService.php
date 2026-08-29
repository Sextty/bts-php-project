<?php

namespace App\Services\Osquery;

use App\Models\StaffUser;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class OsqueryService
{
    private readonly OsqueryEngine $engine;

    private ?string $binaryPath = null;

    private ?bool $hasNativeBinary = null;

    /** Forbidden keywords to prevent destructive or arbitrary commands */
    private const FORBIDDEN_KEYWORDS = [
        'DROP',
        'DELETE',
        'UPDATE',
        'INSERT',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'REPLACE',
        'ATTACH',
        'DETACH',
        'VACUUM',
        'GRANT',
        'REVOKE',
        'EXEC',
        'EXECUTE',
    ];

    public function __construct(?OsqueryEngine $engine = null, private readonly ?AuditLogService $auditLog = null)
    {
        $this->engine = $engine ?? new OsqueryEngine;
    }

    /**
     * Check if native osqueryi binary is available on the system.
     */
    public function hasNativeBinary(): bool
    {
        if ($this->hasNativeBinary !== null) {
            return $this->hasNativeBinary;
        }

        $configured = config('security.telemetry.osquery_binary');
        if ($configured && file_exists($configured)) {
            $this->binaryPath = $configured;
            $this->hasNativeBinary = true;

            return true;
        }

        // Check common paths
        $candidates = [
            'C:\\Program Files\\osquery\\osqueryi.exe',
            'C:\\ProgramData\\osquery\\osqueryi.exe',
            '/usr/bin/osqueryi',
            '/usr/local/bin/osqueryi',
            '/opt/osquery/bin/osqueryi',
        ];

        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                $this->binaryPath = $cand;
                $this->hasNativeBinary = true;

                return true;
            }
        }

        $found = (new ExecutableFinder)->find(PHP_OS_FAMILY === 'Windows' ? 'osqueryi.exe' : 'osqueryi');
        if ($found !== null) {
            $this->binaryPath = $found;
            $this->hasNativeBinary = true;

            return true;
        }

        $this->hasNativeBinary = false;

        return false;
    }

    /**
     * Get system status and engine metadata.
     *
     * @return array{
     *     native_available: bool,
     *     engine_mode: 'native'|'telemetry_engine',
     *     version: string,
     *     tables_count: int,
     *     tables: array<string, array<string, string>>,
     *     presets_count: int
     * }
     */
    public function getStatus(): array
    {
        $hasNative = $this->hasNativeBinary();
        $tables = $this->engine->getTables();

        $version = 'Osquery v5.14.0 (BTS Dual-Engine Core)';
        if ($hasNative && $this->binaryPath) {
            $process = new Process([$this->binaryPath, '--version']);
            $process->setTimeout((float) config('security.telemetry.timeout_seconds', 10))->run();
            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                $version = trim($process->getOutput());
            }
        }

        $presetCount = 0;
        foreach (OsqueryPresets::all() as $cat) {
            $presetCount += count($cat['queries']);
        }

        return [
            'native_available' => $hasNative,
            'engine_mode' => $hasNative ? 'native' : 'telemetry_engine',
            'version' => $version,
            'tables_count' => count($tables),
            'tables' => $tables,
            'presets_count' => $presetCount,
        ];
    }

    /**
     * Validate and execute an Osquery SQL query.
     *
     * @return array{
     *     sql: string,
     *     columns: array<string>,
     *     rows: array<array<string, mixed>>,
     *     count: int,
     *     execution_time_ms: float,
     *     engine_mode: 'native'|'telemetry_engine'
     * }
     */
    public function execute(string $sql, ?StaffUser $actor = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $startTime = microtime(true);
        $cleanSql = trim($sql);

        $this->assertSqlSafety($cleanSql);

        $result = null;
        $engineMode = 'telemetry_engine';

        // 1. Try native binary if present
        if ($this->hasNativeBinary() && $this->binaryPath) {
            try {
                $process = new Process([$this->binaryPath, '--json', $cleanSql]);
                $process->setTimeout((float) config('security.telemetry.timeout_seconds', 10))->run();
                $rawJson = $process->isSuccessful() ? $process->getOutput() : null;
                if ($rawJson !== null && $rawJson !== '') {
                    $rows = json_decode($rawJson, true);
                    if (is_array($rows)) {
                        $columns = ! empty($rows) ? array_keys($rows[0]) : [];
                        $result = [
                            'columns' => $columns,
                            'rows' => $rows,
                            'count' => count($rows),
                        ];
                        $engineMode = 'native';
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Osquery native query execution failed; using telemetry engine.', ['exception_class' => $e::class]);
            }
        }

        // 2. Fallback to built-in telemetry engine
        if ($result === null) {
            $result = $this->engine->query($cleanSql);
            $engineMode = 'telemetry_engine';
        }

        $maxRows = max(1, (int) config('security.telemetry.max_rows', 200));
        $result['rows'] = array_slice($result['rows'], 0, $maxRows);
        $result['count'] = count($result['rows']);
        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        // 3. Log to audit trail
        if ($actor) {
            $this->logAuditEvent($actor, $cleanSql, $result['count'], $executionTimeMs, $engineMode, $ipAddress, $userAgent);
        }

        return [
            'sql' => $cleanSql,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'count' => $result['count'],
            'execution_time_ms' => $executionTimeMs,
            'engine_mode' => $engineMode,
        ];
    }

    /**
     * Run quick security audit across listening ports, logged in users and system specs.
     *
     * @return array<string, mixed>
     */
    public function quickAudit(?StaffUser $actor = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $startTime = microtime(true);

        $system = $this->execute('SELECT hostname, hardware_vendor, hardware_model, cpu_brand, physical_memory FROM system_info;');
        $os = $this->execute('SELECT name, version, build, arch FROM os_version;');
        $ports = $this->execute('SELECT pid, port, protocol, address, process_name FROM listening_ports WHERE port != 0 ORDER BY port ASC;');
        $users = $this->execute('SELECT user, type, host, time, status FROM logged_in_users;');
        $interfaces = $this->execute('SELECT interface, mac, type, is_primary, ipv4, gateway, status FROM interface_details WHERE is_active = 1;');
        $disks = $this->execute('SELECT device, path, total_space, free_space, percent_used, status FROM disk_info;');

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        if ($actor) {
            $this->logAuditEvent($actor, 'QUICK_SECURITY_AUDIT_PACK', 6, $executionTimeMs, 'telemetry_engine', $ipAddress, $userAgent);
        }

        return [
            'system' => $system['rows'][0] ?? null,
            'os' => $os['rows'][0] ?? null,
            'open_ports_count' => $ports['count'],
            'open_ports' => $ports['rows'],
            'logged_users_count' => $users['count'],
            'logged_users' => $users['rows'],
            'active_interfaces_count' => $interfaces['count'],
            'active_interfaces' => $interfaces['rows'],
            'disks' => $disks['rows'],
            'execution_time_ms' => $executionTimeMs,
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Validate SQL safety to guarantee read-only queries.
     */
    private function assertSqlSafety(string $sql): void
    {
        if (empty($sql)) {
            throw new \InvalidArgumentException('La requête SQL Osquery ne peut pas être vide.');
        }

        // Must start with SELECT or PRAGMA
        if (! preg_match('/^(SELECT|PRAGMA)\s+/i', $sql)) {
            throw new \InvalidArgumentException('Seules les requêtes SELECT et PRAGMA sont autorisées dans le terminal Osquery.');
        }

        // Reject forbidden keywords
        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (preg_match('/\b'.preg_quote($kw, '/').'\b/i', $sql)) {
                throw new \InvalidArgumentException("Opération interdite : le mot-clé '{$kw}' est strictement interdit pour des raisons de sécurité.");
            }
        }

        // Prevent multiple chained queries with semicolons
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        if (count($statements) > 1) {
            throw new \InvalidArgumentException('L\'exécution de requêtes multiples enchaînées n\'est pas autorisée.');
        }

        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/i', $sql, $matches);
        $allowedTables = array_keys($this->engine->getTables());
        foreach (array_unique($matches[1] ?? []) as $table) {
            if (! in_array(strtolower($table), $allowedTables, true)) {
                throw new \InvalidArgumentException("Table Osquery non autorisée : {$table}.");
            }
        }
    }

    /**
     * Record Osquery execution into BTS Bank's Audit Trail.
     */
    private function logAuditEvent(
        StaffUser $actor,
        string $sql,
        int $resultCount,
        float $executionTimeMs,
        string $engineMode,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        try {
            ($this->auditLog ?? app(AuditLogService::class))->log(
                'audit.osquery_query',
                previousState: [
                    'sql_sha256' => hash('sha256', $sql),
                    'tables' => $this->tablesIn($sql),
                    'engine_mode' => $engineMode,
                ],
                newState: [
                    'result_rows' => $resultCount,
                    'execution_time_ms' => $executionTimeMs,
                ],
                ipAddress: $ipAddress ?? request()->ip(),
                userAgent: $userAgent ?? request()->userAgent(),
                staffUser: $actor,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to log osquery audit event.', ['exception_class' => $e::class]);
        }
    }

    /** @return list<string> */
    private function tablesIn(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/i', $sql, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}
