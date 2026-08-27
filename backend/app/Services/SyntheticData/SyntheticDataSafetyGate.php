<?php

namespace App\Services\SyntheticData;

use App\Services\LoadTesting\LoadTestSafetyGate;
use RuntimeException;

final class SyntheticDataSafetyGate
{
    public function __construct(private readonly LoadTestSafetyGate $loadTestSafety) {}

    public function assertAllowed(GenerationOptions $options): void
    {
        // Only local and automated test environments may run without the
        // explicitly protected production acknowledgement. This fails closed
        // for staging and any custom/deployed environment as well.
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        if (app()->environment('loadtest')) {
            $this->loadTestSafety->assertActive();

            return;
        }

        $configuredToken = (string) config('synthetic_data.production.token', '');
        $expectedConfirmation = (string) config('synthetic_data.production.confirmation', 'GENERATE_SYNTHETIC_TEST_DATA');

        $allowed = (bool) config('synthetic_data.production.enabled', false)
            && $options->allowProduction
            && strlen($configuredToken) >= 32
            && hash_equals($configuredToken, $options->productionToken)
            && hash_equals($expectedConfirmation, $options->productionConfirmation);

        if (! $allowed) {
            throw new RuntimeException(
                'SYNTHETIC TEST DATA generation is blocked in production. '
                .'A dedicated environment switch, a 32+ character secret, and the exact confirmation phrase are all required.'
            );
        }
    }
}
