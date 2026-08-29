<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured AI verification result: the processing status (verified / needs_human_review /
     * failed), the derived issue list, and the human-review flag — the machine-readable version
     * of the document's advisory verdict, in addition to the raw is_valid/confidence fields.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('ai_processing_status')->nullable()->after('ai_mismatches');
            $table->json('ai_detected_issues')->nullable()->after('ai_processing_status');
            $table->boolean('ai_requires_human_review')->nullable()->after('ai_detected_issues');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['ai_processing_status', 'ai_detected_issues', 'ai_requires_human_review']);
        });
    }
};