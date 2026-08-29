<?php

namespace Tests\Feature\CreditApplication;

use App\Services\ApplicationNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sequential correctness check for ApplicationNumberService. This does NOT exercise real
 * concurrency — PHPUnit runs single-process/single-connection against SQLite here, which
 * doesn't take real row locks the way the app's actual MySQL database does. The genuine
 * concurrent-request race is verified separately, live, against MySQL (see the plan's
 * verification step 3 — parallel curl requests against `php artisan serve`).
 */
class ApplicationNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_calls_produce_unique_sequential_numbers(): void
    {
        $service = app(ApplicationNumberService::class);

        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $service->generate();
        }

        $this->assertCount(25, array_unique($numbers));

        $year = now()->year;
        $this->assertSame("CR-{$year}-000001", $numbers[0]);
        $this->assertSame("CR-{$year}-000025", $numbers[24]);
    }
}
