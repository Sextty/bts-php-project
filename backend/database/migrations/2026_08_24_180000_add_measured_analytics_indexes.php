<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE audit_logs ADD COLUMN analytics_status VARCHAR(50) GENERATED ALWAYS AS (json_extract(new_state, '$.status')) VIRTUAL");
        } else {
            DB::statement("ALTER TABLE audit_logs ADD COLUMN analytics_status VARCHAR(50) AS (JSON_UNQUOTE(JSON_EXTRACT(new_state, '$.status'))) PERSISTENT");
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(
                ['action', 'analytics_status', 'credit_application_id', 'created_at'],
                'audit_logs_action_status_app_created_index',
            );
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index(['ville', 'credit_application_id'], 'projects_ville_application_index');
            $table->index(['delegation', 'credit_application_id'], 'projects_delegation_application_index');
            $table->index(['type_projet', 'credit_application_id'], 'projects_type_application_index');
            $table->index(['activite', 'credit_application_id'], 'projects_activity_application_index');
        });

        Schema::table('credit_requests', function (Blueprint $table) {
            $table->index(['type_demande', 'credit_application_id'], 'credit_requests_type_application_index');
        });
    }

    public function down(): void
    {
        Schema::table('credit_requests', function (Blueprint $table) {
            $table->dropIndex('credit_requests_type_application_index');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_ville_application_index');
            $table->dropIndex('projects_delegation_application_index');
            $table->dropIndex('projects_type_application_index');
            $table->dropIndex('projects_activity_application_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_status_app_created_index');
            $table->dropColumn('analytics_status');
        });
    }
};
