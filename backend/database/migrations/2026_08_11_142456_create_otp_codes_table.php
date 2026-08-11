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
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Never stored plaintext — OtpService hashes with the same hasher as passwords.
            $table->string('code_hash');
            $table->enum('purpose', ['registration', 'login', 'password_reset']);
            $table->enum('channel', ['sms', 'email'])->default('sms');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'purpose', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
