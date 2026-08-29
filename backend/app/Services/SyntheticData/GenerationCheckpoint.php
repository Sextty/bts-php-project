<?php

namespace App\Services\SyntheticData;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class GenerationCheckpoint
{
    private const FORMAT_VERSION = 1;

    /** @var resource|null */
    private mixed $lockHandle = null;

    private readonly bool $persistent;

    private readonly string $path;

    private readonly int $totalCustomers;

    /** @var array<string, mixed> */
    private readonly array $identity;

    public function __construct(ConnectionInterface $connection, GenerationOptions $options)
    {
        $database = (string) $connection->getDatabaseName();
        $this->persistent = $database !== ':memory:';
        $this->totalCustomers = $options->customers;
        $schemaHash = hash('sha256', json_encode(
            $connection->table('migrations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            JSON_THROW_ON_ERROR,
        ));
        $branchHash = hash('sha256', json_encode(
            $connection->table('branches')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            JSON_THROW_ON_ERROR,
        ));
        $configurationHash = hash('sha256', json_encode([
            'generator_version' => config('synthetic_data.generator_version', 1),
            'profile' => config('synthetic_data.profiles.'.$options->profile),
            'status_weights' => $options->statusWeights,
            'year_weights' => $options->yearWeights,
            'month_weights' => $options->monthWeights,
            'anchor_date' => config('synthetic_data.anchor_date'),
            'namespace' => config('synthetic_data.namespace'),
            'email_domain' => config('synthetic_data.email_domain'),
            'document_disk' => config('synthetic_data.document_disk'),
            'document_directory' => config('synthetic_data.document_directory'),
        ], JSON_THROW_ON_ERROR));
        $this->identity = [
            'format_version' => self::FORMAT_VERSION,
            'generator_version' => (int) config('synthetic_data.generator_version', 1),
            'connection' => $connection->getName(),
            'database' => $database,
            'run_key' => $options->runKey,
            'profile' => $options->profile,
            'seed_hash' => hash('sha256', $options->seed),
            'customers' => $options->customers,
            'applications' => $options->applications,
            'materialize_documents' => $options->materializeDocuments,
            'schema_hash' => $schemaHash,
            'branch_hash' => $branchHash,
            'configuration_hash' => $configurationHash,
        ];
        $fingerprint = substr(hash('sha256', json_encode([
            'connection' => $connection->getName(),
            'database' => $database,
            'run_key' => $options->runKey,
            'customers' => $options->customers,
            'applications' => $options->applications,
            'materialize_documents' => $options->materializeDocuments,
        ], JSON_THROW_ON_ERROR)), 0, 24);
        $directory = rtrim((string) config('synthetic_data.checkpoint_directory'), DIRECTORY_SEPARATOR);
        $this->path = $directory.DIRECTORY_SEPARATOR.$fingerprint.'.json';
    }

    public function acquire(): void
    {
        if (! $this->persistent) {
            return;
        }

        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create synthetic data checkpoint directory.');
        }

        // One database-wide lock: separate run keys must never contend for the
        // same appointment slots or write synthetic data concurrently.
        $connectionKey = $this->identity['connection'].'|'.$this->identity['database'];
        $lockName = 'database-'.substr(hash('sha256', $connectionKey), 0, 24).'.lock';
        $handle = fopen($directory.DIRECTORY_SEPARATOR.$lockName, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another synthetic data generator is already running for this database and workload.');
        }

        $this->lockHandle = $handle;
    }

    public function completedCustomerOrdinal(): int
    {
        if (! $this->persistent || ! is_file($this->path)) {
            return 0;
        }

        $payload = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($payload) || ! isset($payload['identity'], $payload['completed_customer_ordinal'], $payload['complete'], $payload['checksum'])) {
            throw new RuntimeException('Synthetic data checkpoint is malformed; refusing an unsafe automatic resume.');
        }

        $expectedChecksum = hash('sha256', json_encode([
            'identity' => $payload['identity'],
            'completed_customer_ordinal' => $payload['completed_customer_ordinal'],
            'complete' => $payload['complete'],
        ], JSON_THROW_ON_ERROR));
        if ($payload['identity'] !== $this->identity || ! hash_equals($expectedChecksum, (string) $payload['checksum'])) {
            throw new RuntimeException('Synthetic data checkpoint identity or checksum is invalid.');
        }

        return max(0, (int) $payload['completed_customer_ordinal']);
    }

    public function save(int $completedCustomerOrdinal): void
    {
        if (! $this->persistent) {
            return;
        }

        $core = [
            'identity' => $this->identity,
            'completed_customer_ordinal' => $completedCustomerOrdinal,
            'complete' => $completedCustomerOrdinal >= $this->totalCustomers,
        ];
        $payload = json_encode([
            'synthetic_test_data' => true,
            ...$core,
            'checksum' => hash('sha256', json_encode($core, JSON_THROW_ON_ERROR)),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $temporaryPath = $this->path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
        if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write synthetic data checkpoint.');
        }
        $handle = fopen($temporaryPath, 'r+');
        if (is_resource($handle)) {
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
        }
        if (! rename($temporaryPath, $this->path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Cannot atomically replace synthetic data checkpoint.');
        }
    }

    public function release(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
