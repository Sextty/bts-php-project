<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->index(['user_id', 'id'], 'credit_apps_user_id_desc_index');
            $table->index(['branch_id', 'status', 'created_at', 'id'], 'credit_apps_branch_status_created_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['branch_id', 'scheduled_date', 'status', 'scheduled_time'], 'appointments_branch_date_status_time_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at', 'id'], 'audit_logs_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex('audit_logs_action_created_index'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_branch_date_status_time_index'));
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropIndex('credit_apps_user_id_desc_index');
            $table->dropIndex('credit_apps_branch_status_created_index');
        });
    }
};
