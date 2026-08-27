<?php

namespace App\Services\SyntheticData;

use App\Models\CreditApplication;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class GenerationOptions
{
    public function __construct(
        public string $profile,
        public int $customers,
        public int $applications,
        public string $seed,
        public string $runKey,
        public int $years,
        public int $chunkSize,
        public bool $materializeDocuments,
        /** @var array<string,int> */
        public array $statusWeights,
        /** @var list<int> */
        public array $yearWeights,
        /** @var list<int> */
        public array $monthWeights,
        public bool $allowProduction = false,
        public string $productionToken = '',
        public string $productionConfirmation = '',
    ) {}

    public static function fromInput(
        string $profile,
        int|string|null $customers = null,
        int|string|null $applications = null,
        int|string|null $seed = null,
        int|string|null $chunk = null,
        ?bool $materializeDocuments = null,
        bool $allowProduction = false,
        ?string $productionToken = null,
        ?string $productionConfirmation = null,
    ): self {
        $profiles = config('synthetic_data.profiles', []);
        if (! isset($profiles[$profile]) || ! is_array($profiles[$profile])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown profile "%s". Available profiles: %s.',
                $profile,
                implode(', ', array_keys($profiles)),
            ));
        }

        $profileConfig = $profiles[$profile];
        $customerCount = self::positiveInteger($customers ?? $profileConfig['customers'], 'customers');
        $applicationCount = self::positiveInteger($applications ?? $profileConfig['applications'], 'applications');
        $chunkSize = self::positiveInteger($chunk ?? $profileConfig['chunk'], 'chunk');
        $seedValue = trim((string) ($seed ?? config('synthetic_data.default_seed', '20260823')));
        $statusWeights = self::statusWeights($profileConfig['status_weights'] ?? config('synthetic_data.status_weights', []));
        $yearWeights = self::listWeights($profileConfig['year_weights'] ?? config('synthetic_data.year_weights', []), 'year_weights');
        $monthWeights = self::listWeights($profileConfig['month_weights'] ?? config('synthetic_data.month_weights', []), 'month_weights');

        if (! preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $seedValue)) {
            throw new InvalidArgumentException('Seed must contain 1-64 letters, numbers, dots, underscores, or hyphens.');
        }

        $limits = config('synthetic_data.limits', []);
        if ($customerCount > (int) ($limits['customers'] ?? 5_000_000)) {
            throw new InvalidArgumentException('Customer count exceeds configured safety limit.');
        }
        if ($applicationCount > (int) ($limits['applications'] ?? 20_000_000)) {
            throw new InvalidArgumentException('Application count exceeds configured safety limit.');
        }
        if ((int) ceil($applicationCount / $customerCount) > (int) ($limits['applications_per_customer'] ?? 10)) {
            throw new InvalidArgumentException('Applications per customer exceed configured safety limit.');
        }
        if ($chunkSize < (int) ($limits['chunk_min'] ?? 10) || $chunkSize > (int) ($limits['chunk_max'] ?? 5_000)) {
            throw new InvalidArgumentException(sprintf(
                'Chunk must be between %d and %d.',
                (int) ($limits['chunk_min'] ?? 10),
                (int) ($limits['chunk_max'] ?? 5_000),
            ));
        }
        $years = max(1, (int) ($profileConfig['years'] ?? 2));
        if (count($yearWeights) < $years) {
            throw new InvalidArgumentException('year_weights must contain at least one positive weight per configured year.');
        }
        if (count($monthWeights) !== 12) {
            throw new InvalidArgumentException('month_weights must contain exactly 12 positive weights.');
        }

        $namespace = (string) config('synthetic_data.namespace', 'bts-synthetic');
        $emailDomain = (string) config('synthetic_data.email_domain', 'synthetic.bts.invalid');
        $rawDocumentDirectory = (string) config('synthetic_data.document_directory', 'synthetic-data');
        $documentDirectory = trim($rawDocumentDirectory, '/\\');
        if (! preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $namespace)) {
            throw new InvalidArgumentException('Synthetic namespace contains unsafe characters.');
        }
        if (! preg_match('/\A[A-Za-z0-9.-]+\.invalid\z/i', $emailDomain)) {
            throw new InvalidArgumentException('Synthetic email domain must use the reserved .invalid top-level domain.');
        }
        if (
            $documentDirectory === ''
            || str_starts_with($rawDocumentDirectory, '/')
            || str_starts_with($rawDocumentDirectory, '\\')
            || str_contains($documentDirectory, '..')
            || str_contains($documentDirectory, '\\')
            || ! preg_match('/\A[A-Za-z0-9._\/-]+\z/', $documentDirectory)
        ) {
            throw new InvalidArgumentException('Synthetic document directory must be a safe relative storage path.');
        }
        $runKey = substr(hash('sha256', implode('|', [
            $namespace,
            $profile,
            $customerCount,
            $applicationCount,
            $seedValue,
        ])), 0, 12);

        return new self(
            profile: $profile,
            customers: $customerCount,
            applications: $applicationCount,
            seed: $seedValue,
            runKey: $runKey,
            years: $years,
            chunkSize: $chunkSize,
            materializeDocuments: $materializeDocuments ?? (bool) ($profileConfig['materialize_documents'] ?? false),
            statusWeights: $statusWeights,
            yearWeights: $yearWeights,
            monthWeights: $monthWeights,
            allowProduction: $allowProduction,
            productionToken: $productionToken ?? '',
            productionConfirmation: $productionConfirmation ?? '',
        );
    }

    public function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('synthetic_data.anchor_date', '2026-08-23 12:00:00'));
    }

    public function emailPrefix(): string
    {
        return 'syn-'.$this->runKey.'-c';
    }

    public function emailFor(int $ordinal): string
    {
        return $this->emailPrefix().$ordinal.'@'.config('synthetic_data.email_domain', 'synthetic.bts.invalid');
    }

    private static function positiveInteger(int|string $value, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException(ucfirst($name).' must be a positive integer.');
        }

        return (int) $value;
    }

    /** @return array<string,int> */
    private static function statusWeights(mixed $weights): array
    {
        if (! is_array($weights) || $weights === []) {
            throw new InvalidArgumentException('status_weights must be a non-empty map.');
        }

        $result = [];
        foreach ($weights as $status => $weight) {
            if (! in_array($status, CreditApplication::STATUS_ORDER, true)) {
                throw new InvalidArgumentException('status_weights contains unsupported status: '.$status);
            }
            if (! is_int($weight) || $weight < 1) {
                throw new InvalidArgumentException('Every status weight must be a positive integer.');
            }
            $result[$status] = $weight;
        }

        return $result;
    }

    /** @return list<int> */
    private static function listWeights(mixed $weights, string $name): array
    {
        if (! is_array($weights) || $weights === []) {
            throw new InvalidArgumentException($name.' must be a non-empty list.');
        }

        $result = [];
        foreach (array_values($weights) as $weight) {
            if (! is_int($weight) || $weight < 1) {
                throw new InvalidArgumentException('Every '.$name.' value must be a positive integer.');
            }
            $result[] = $weight;
        }

        return $result;
    }
}
