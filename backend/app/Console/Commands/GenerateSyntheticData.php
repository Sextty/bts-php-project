<?php

namespace App\Console\Commands;

use App\Services\SyntheticData\GenerationOptions;
use App\Services\SyntheticData\SyntheticDataGenerator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class GenerateSyntheticData extends Command
{
    protected $signature = 'bts:generate-data
                            {--profile=small : small, medium, large, or massive}
                            {--customers= : Override customer count}
                            {--applications= : Override credit application count}
                            {--seed= : Reproducible seed (letters, numbers, ._-)}
                            {--chunk= : Customer transaction chunk size}
                            {--materialize-documents : Write unique synthetic document files}
                            {--metadata-only-documents : Create document rows without files}
                            {--allow-production : First production safety factor}
                            {--production-confirmation= : Exact production confirmation phrase}';

    protected $description = 'Generate restartable SYNTHETIC TEST DATA for BTS Bank analytics and stress tests';

    public function handle(SyntheticDataGenerator $generator): int
    {
        if ($this->option('materialize-documents') && $this->option('metadata-only-documents')) {
            $this->error('--materialize-documents and --metadata-only-documents are mutually exclusive.');

            return self::INVALID;
        }

        $documentOverride = null;
        if ($this->option('materialize-documents')) {
            $documentOverride = true;
        } elseif ($this->option('metadata-only-documents')) {
            $documentOverride = false;
        }

        $productionToken = '';
        if (! app()->environment(['local', 'testing']) && $this->option('allow-production') && $this->input->isInteractive()) {
            $productionToken = (string) $this->secret('Production authorization token');
        }

        try {
            $options = GenerationOptions::fromInput(
                profile: (string) $this->option('profile'),
                customers: $this->option('customers'),
                applications: $this->option('applications'),
                seed: $this->option('seed'),
                chunk: $this->option('chunk'),
                materializeDocuments: $documentOverride,
                allowProduction: (bool) $this->option('allow-production'),
                productionToken: $productionToken,
                productionConfirmation: is_string($this->option('production-confirmation')) ? $this->option('production-confirmation') : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $connection = app('db')->connection();
        $this->components->warn('SYNTHETIC TEST DATA ONLY — no real customer or banking data is generated.');
        $this->table(['Setting', 'Value'], [
            ['Connection', $connection->getName()],
            ['Database', (string) $connection->getDatabaseName()],
            ['Profile', $options->profile],
            ['Run key', $options->runKey],
            ['Seed', $options->seed],
            ['Customers', number_format($options->customers)],
            ['Applications', number_format($options->applications)],
            ['Chunk', number_format($options->chunkSize)],
            ['Documents', $options->materializeDocuments ? 'materialized files' : 'metadata only'],
        ]);

        $bar = $this->output->createProgressBar($options->customers);
        $bar->setFormat(' %current%/%max% customers [%bar%] %percent:3s%% | %message%');
        $bar->setMessage('starting');
        $bar->start();

        try {
            $summary = $generator->generate($options, function (array $event) use ($bar): void {
                if (($event['phase'] ?? null) === 'checkpoint_rescan') {
                    $bar->setMessage('checkpoint rescan');

                    return;
                }
                if (($event['phase'] ?? null) !== 'customers') {
                    return;
                }
                $bar->setProgress((int) $event['current']);
                $bar->setMessage(sprintf(
                    '%s/s | %s memory',
                    number_format((int) ($event['rate'] ?? 0)),
                    $this->formatBytes((int) ($event['memory_bytes'] ?? 0)),
                ));
            });
            $bar->setProgress($options->customers);
            $bar->finish();
            $this->newLine(2);
        } catch (Throwable $exception) {
            $bar->clear();
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Synthetic generation complete. Existing records were preserved.');
        $this->table(['Result', 'Count'], [
            ['Users inserted this run', number_format((int) $summary['users_inserted'])],
            ['Applications inserted this run', number_format((int) $summary['applications_inserted'])],
            ['Related rows inserted this run', number_format((int) $summary['related_rows_inserted'])],
            ['Document files created this run', number_format((int) $summary['documents_materialized'])],
            ['Document bytes created this run', number_format((int) $summary['document_bytes_materialized'])],
            ['Resumed after customer', number_format((int) $summary['resumed_after_customer'])],
            ['Elapsed seconds', number_format((int) $summary['elapsed_seconds'])],
        ]);

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1_024) {
            return $bytes.' B';
        }
        if ($bytes < 1_048_576) {
            return number_format($bytes / 1_024, 1).' KiB';
        }

        return number_format($bytes / 1_048_576, 1).' MiB';
    }
}
