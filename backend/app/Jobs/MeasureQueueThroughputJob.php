<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MeasureQueueThroughputJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly int $sequence) {}

    public function handle(): void
    {
        // Deliberately empty: measures database queue overhead, not business work.
    }
}
