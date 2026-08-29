<?php

namespace App\Services;

use App\Models\ApplicationNumberCounter;
use Illuminate\Support\Facades\DB;

/**
 * Generates "{prefix}-{year}-{sequence}" identifiers (Part 1 spec, Étape 2: "N° Demande MUST be
 * generated automatically... reliable under concurrent requests") — originally just credit
 * application numbers ("CR-"), now shared by any field that needs the same guarantee (e.g.
 * "CL-" for code_client), each prefix keeping its own sequence via the counter table's
 * (year, type) key so two prefixes in the same year never collide or share numbers.
 *
 * One counter row per (year, prefix). `lockForUpdate()` takes a row-level lock inside the
 * transaction, so two concurrent requests generating a number for the same year+prefix serialize
 * at the database instead of racing — the second request blocks until the first commits, then
 * reads the already-incremented value. No application-level mutex needed.
 */
class ApplicationNumberService
{
    public function generate(string $prefix = 'CR'): string
    {
        return DB::transaction(function () use ($prefix) {
            $year = (int) now()->year;

            // insertOrIgnore is safe under concurrency (MySQL silently ignores the duplicate-key
            // conflict instead of throwing), so two requests racing to create the same
            // year+prefix counter for the first time can't collide here — whichever loses just
            // finds the row already present at the SELECT below.
            DB::table('application_number_counters')->insertOrIgnore([
                'year' => $year,
                'type' => $prefix,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = ApplicationNumberCounter::query()
                ->where('year', $year)
                ->where('type', $prefix)
                ->lockForUpdate()
                ->first();

            $counter->increment('last_number');

            return sprintf('%s-%d-%06d', $prefix, $year, $counter->last_number);
        }, 5);
    }
}
