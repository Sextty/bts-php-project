<?php

namespace Tests\Feature\MariaDb;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuditConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'mysql' || ! preg_match('/^bts_p0p1_concurrency_[0-9]+$/', $database)) {
            $this->markTestSkipped('Requires a dedicated bts_p0p1_concurrency_* MariaDB database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_concurrent_audit_writes_keep_one_valid_chain(): void
    {
        $user = User::factory()->create();
        $startAt = microtime(true) + 1.0;
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
while (microtime(true) < (float) $argv[2]) { usleep(1000); }
try {
    $row = $app->make(App\Services\AuditLogService::class)->log('synthetic.concurrent', App\Models\User::findOrFail((int) $argv[3]), newState: ['worker' => (int) $argv[4]]);
    echo json_encode(['ok' => true, 'id' => $row->id]);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception::class]);
}
PHP;

        $processes = [];
        foreach (range(1, 8) as $worker) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $startAt, (string) $user->id, (string) $worker], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertTrue((bool) json_decode($process->getOutput(), true)['ok'], $process->getOutput());
        }

        $this->assertSame(8, AuditLog::where('action', 'synthetic.concurrent')->count());
        $result = app(AuditLogService::class)->verifyIntegrity();
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
        $this->assertSame(8, $result['checked']);
    }
}
