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
        // Append-only history of validation attempts (both pass and fail), not just the
        // latest — mirrors the audit_logs/otp_codes "never update, only insert" convention.
        Schema::create('validation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->constrained()->cascadeOnDelete();
            $table->enum('step', ['validation_1', 'validation_2']);
            $table->enum('status', ['passed', 'failed']);
            $table->json('errors')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['credit_application_id', 'step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_steps');
    }
};
