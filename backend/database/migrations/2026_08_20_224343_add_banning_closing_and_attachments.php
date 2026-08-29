<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('status');
            $table->text('banned_reason')->nullable()->after('banned_at');
            $table->foreignId('banned_by_staff_id')->nullable()->after('banned_reason')->constrained('staff_users')->nullOnDelete();
        });

        Schema::table('credit_applications', function (Blueprint $table) {
            $table->timestamp('report_closed_at')->nullable()->after('branch_id');
            $table->foreignId('report_closed_by_staff_id')->nullable()->after('report_closed_at')->constrained('staff_users')->nullOnDelete();
            $table->string('report_closed_reason', 500)->nullable()->after('report_closed_by_staff_id');
        });

        Schema::table('report_messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('body');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_type', 100)->nullable()->after('attachment_name');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['banned_by_staff_id']);
            $table->dropColumn(['banned_at', 'banned_reason', 'banned_by_staff_id']);
        });

        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropForeign(['report_closed_by_staff_id']);
            $table->dropColumn(['report_closed_at', 'report_closed_by_staff_id', 'report_closed_reason']);
        });

        Schema::table('report_messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_type', 'attachment_size']);
        });
    }
};
