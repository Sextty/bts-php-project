<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsDataQualityService;
use Illuminate\Console\Command;

final class VerifyAnalyticsDataQuality extends Command
{
    protected $signature = 'analytics:verify-data-quality {--json= : Optional JSON output path}';

    protected $description = 'Read-only BTS analytics data-quality verification';

    public function handle(AnalyticsDataQualityService $quality): int
    {
        $result = $quality->verify();
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($path = $this->option('json')) {
            $directory = dirname((string) $path);
            if (! is_dir($directory)) {
                mkdir($directory, 0750, true);
            }
            file_put_contents((string) $path, $json.PHP_EOL, LOCK_EX);
        }

        $this->line($json);

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
