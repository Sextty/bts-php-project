<?php

namespace App\Services\LoadTesting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class LoadTestSafetyGate
{
    public const MARKER = 'SYNTHETIC_LOAD_TEST_ONLY';

    public function assertCanInitialize(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Synthetic load testing is permanently blocked in production.');
        }

        if (! app()->environment('loadtest') || ! config('load_testing.enabled', false)) {
            throw new RuntimeException('Set APP_ENV=loadtest and BTS_LOAD_TEST_ENABLED=true in an isolated process.');
        }

        if (! preg_match((string) config('load_testing.database_pattern'), $this->databaseName())) {
            throw new RuntimeException('The database name is not a dedicated bts_load_<timestamp> database.');
        }
    }

    public function assertActive(): array
    {
        $this->assertCanInitialize();
        $marker = $this->readMarker();

        if (($marker['marker'] ?? null) !== self::MARKER
            || ($marker['database'] ?? null) !== $this->databaseName()
            || ! is_string($marker['campaign_id'] ?? null)
            || ! hash_equals($this->signature($marker), (string) ($marker['signature'] ?? ''))) {
            throw new RuntimeException('The synthetic load-test database marker is missing or invalid.');
        }

        return $marker;
    }

    public function isActive(): bool
    {
        try {
            $this->assertActive();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function createMarker(string $campaignId, string $seed): array
    {
        $this->assertCanInitialize();
        $marker = [
            'marker' => self::MARKER,
            'database' => $this->databaseName(),
            'campaign_id' => $campaignId,
            'seed' => $seed,
            'created_at' => now()->toIso8601String(),
        ];
        $marker['signature'] = $this->signature($marker);

        File::ensureDirectoryExists($this->markerDirectory());
        File::put($this->markerPath(), json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $marker;
    }

    public function databaseName(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    private function readMarker(): array
    {
        if (! File::isFile($this->markerPath())) {
            return [];
        }

        $decoded = json_decode((string) File::get($this->markerPath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function markerDirectory(): string
    {
        return (string) config('load_testing.marker_directory');
    }

    private function markerPath(): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $this->databaseName());

        return $this->markerDirectory().DIRECTORY_SEPARATOR.$safeName.'.json';
    }

    private function signature(array $marker): string
    {
        $payload = implode('|', [
            (string) ($marker['marker'] ?? ''),
            (string) ($marker['database'] ?? ''),
            (string) ($marker['campaign_id'] ?? ''),
            (string) ($marker['seed'] ?? ''),
            (string) ($marker['created_at'] ?? ''),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
