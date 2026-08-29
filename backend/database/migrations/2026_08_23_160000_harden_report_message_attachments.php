<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_messages', function (Blueprint $table) {
            $table->string('attachment_disk', 20)->default('local')->after('attachment_path');
            $table->string('malware_scan_status', 20)->default('unavailable')->after('attachment_size');
            $table->string('malware_signature')->nullable()->after('malware_scan_status');
            $table->timestamp('malware_scanned_at')->nullable()->after('malware_signature');
        });
    }

    public function down(): void
    {
        Schema::table('report_messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_disk', 'malware_scan_status', 'malware_signature', 'malware_scanned_at']);
        });
    }
};
