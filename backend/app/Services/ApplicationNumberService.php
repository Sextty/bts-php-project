<?php

namespace App\Services;

use App\Models\ApplicationNumberCounter;
use Illuminate\Support\Facades\DB;

/**
 * Generates "CR-{year}-{sequence}" credit-application numbers (Part 1 spec, Étape 2:
 * "N° Demande MUST be generated automatically... reliable under concurrent requests").
 *
 * One counter row per calendar year. `lockForUpdate()` takes a row-level lock inside the
 * transaction, so two concurrent requests generating a number in the same year serialize at
 * the database instead of racing — the second request blocks until the first commits, then
 * reads the already-incremented value. No application-level mutex needed.
 */
class ApplicationNumberService
{
    public function generate(): string
    {
        return DB::transaction(function () {
            $year = (int) now()->year;

            // insertOrIgnore is safe under concurrency (MySQL silently ignores the duplicate-key
            // conflict instead of throwing), so two requests racing to create the same year's
            // counter for the first time can't collide here — whichever loses just finds the row
            // already present at the SELECT below.
            DB::table('application_number_counters')->insertOrIgnore([
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = ApplicationNumberCounter::query()
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $counter->increment('last_number');

            return sprintf('CR-%d-%06d', $year, $counter->last_number);
        });
    }
}
