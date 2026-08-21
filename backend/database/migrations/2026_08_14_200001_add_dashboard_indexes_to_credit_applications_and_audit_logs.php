<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes backing the admin dashboard's read paths (DashboardStatsService) and the
 * activity trail (ActivityLogService):
 *
 *   - credit_applications.status   -> the KPI/pipeline queries group by status
 *   - credit_applications.created_at -> the timeline query filters created_at >= date
 *   - audit_logs.credit_application_id -> average-decision-hours and activity queries
 *     filter by this FK; InnoDB auto-creates an index for FKs, SQLite does not, and an
 *     explicit one is needed for the MySQL production shape anyway.
 *
 * All additive — no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('credit_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['credit_application_id']);
        });

        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status']);
        });
    }
};