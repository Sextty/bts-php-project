<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('appointments')
            ->select('credit_application_id', 'attempt_number', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('credit_application_id', 'attempt_number')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates
                ->map(fn ($row) => "application={$row->credit_application_id}, attempt={$row->attempt_number}, count={$row->duplicate_count}")
                ->implode('; ');

            throw new RuntimeException(
                'Cannot add appointments_application_attempt_unique: duplicate appointment attempts exist. '
                .'Resolve them explicitly before deployment. Sample: '.$sample,
            );
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->unique(
                ['credit_application_id', 'attempt_number'],
                'appointments_application_attempt_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_application_attempt_unique');
        });
    }
};
