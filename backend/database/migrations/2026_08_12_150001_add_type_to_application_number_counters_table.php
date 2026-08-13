<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Generalizes the counter table beyond just "CR-" (n_demande) so the same
        // concurrency-safe per-year sequence mechanism can back other prefixes (e.g. "CL-" for
        // code_client) without their sequences colliding — each prefix gets its own counter row
        // per year instead of all prefixes sharing one.
        Schema::table('application_number_counters', function (Blueprint $table) {
            $table->string('type', 10)->default('CR')->after('year');
        });

        // Existing rows predate this column and were exclusively used for "CR-" numbers.
        DB::table('application_number_counters')->update(['type' => 'CR']);

        Schema::table('application_number_counters', function (Blueprint $table) {
            $table->dropUnique(['year']);
            $table->unique(['year', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_number_counters', function (Blueprint $table) {
            $table->dropUnique(['year', 'type']);
            $table->dropColumn('type');
            $table->unique(['year']);
        });
    }
};
