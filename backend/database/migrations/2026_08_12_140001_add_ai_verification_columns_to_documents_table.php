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
        // Verified once, not repeatedly — plain nullable columns on the document itself rather
        // than an append-only history table (unlike validation_steps/appointments, there's no
        // "attempt history" a document verification needs to preserve).
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('ai_verified_at')->nullable()->after('size_bytes');
            $table->boolean('ai_is_valid')->nullable()->after('ai_verified_at');
            $table->string('ai_confidence', 10)->nullable()->after('ai_is_valid');
            $table->text('ai_comment')->nullable()->after('ai_confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['ai_verified_at', 'ai_is_valid', 'ai_confidence', 'ai_comment']);
        });
    }
};
