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
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Backend-enforced state machine (Part 1 spec, section 6) — the frontend never
            // decides this, every transition is validated server-side by CreditApplicationService.
            $table->enum('status', [
                'DRAFT',
                'STEP_1_COMPLETED',
                'STEP_2_COMPLETED',
                'STEP_3_COMPLETED',
                'READY_FOR_VALIDATION_1',
                'VALIDATION_1_COMPLETED',
                'VALIDATION_2',
                'FINAL_LOCKED',
                'SUBMITTED',
            ])->default('DRAFT');
            // dateTime, not timestamp: avoids MySQL's implicit ON UPDATE CURRENT_TIMESTAMP
            // quirk on this XAMPP instance (explicit_defaults_for_timestamp is off) — see the
            // otp_codes.expires_at fix for the same issue.
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
    }
};
