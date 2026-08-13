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
        // Append-only, like validation_steps — every proposal (up to 3 attempts) stays in the
        // history instead of being overwritten; "the current one" is just the latest row for a
        // given application.
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedTinyInteger('attempt_number');
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->string('status', 20)->default('proposed');
            $table->dateTime('decided_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Drives AppointmentSchedulingService's capacity count for a branch+day.
            $table->index(['branch_id', 'scheduled_date']);
            $table->index(['credit_application_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
