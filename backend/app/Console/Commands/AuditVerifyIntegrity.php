<?php

namespace App\Console\Commands;

use App\Services\AuditLogService;
use Illuminate\Console\Command;

class AuditVerifyIntegrity extends Command
{
    protected $signature = 'audit:verify-integrity';

    protected $description = 'Verify the tamper-evident hash chain protecting audit logs';

    public function handle(AuditLogService $audit): int
    {
        $result = $audit->verifyIntegrity();

        if ($result['valid']) {
            $this->info("Audit integrity valid ({$result['checked']} protected rows checked).");

            return self::SUCCESS;
        }

        $this->error("Audit integrity FAILED ({$result['checked']} protected rows checked).");
        foreach ($result['errors'] as $error) {
            $this->line("- {$error}");
        }

        return self::FAILURE;
    }
}
