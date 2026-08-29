<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedTinyInteger('reschedule_count')->default(0)->after('attempt_number');
        });

        // Historical `attempt_number` also includes Staff-created proposals, so it cannot be
        // reused as a customer quota. A rejected proposal is the durable evidence of one
        // committed customer change. For each historical row, count only earlier rejections.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE appointments AS current_appointment
                JOIN (
                    SELECT current_row.id, LEAST(4, COUNT(rejected_row.id)) AS historical_reschedule_count
                    FROM appointments AS current_row
                    LEFT JOIN appointments AS rejected_row
                        ON rejected_row.credit_application_id = current_row.credit_application_id
                        AND rejected_row.attempt_number < current_row.attempt_number
                        AND rejected_row.status = 'rejected'
                    GROUP BY current_row.id
                ) AS historical
                    ON historical.id = current_appointment.id
                SET current_appointment.reschedule_count = historical.historical_reschedule_count
                SQL);
        } else {
            DB::table('appointments')->orderBy('id')->chunkById(500, function ($appointments): void {
                foreach ($appointments as $appointment) {
                    $count = DB::table('appointments')
                        ->where('credit_application_id', $appointment->credit_application_id)
                        ->where('attempt_number', '<', $appointment->attempt_number)
                        ->where('status', 'rejected')
                        ->count();
                    DB::table('appointments')->where('id', $appointment->id)->update([
                        'reschedule_count' => min(4, $count),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reschedule_count');
        });
    }
};
