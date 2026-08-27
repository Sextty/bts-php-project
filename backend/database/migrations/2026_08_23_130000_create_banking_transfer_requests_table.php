<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('source_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_millimes');
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by_staff_user_id')->constrained('staff_users')->restrictOnDelete();
            $table->foreignId('checked_by_staff_user_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['source_account_id', 'status', 'created_at'], 'btr_source_status_created_idx');
            $table->index(['status', 'created_at'], 'btr_status_created_idx');
            $table->index(['requested_by_staff_user_id', 'created_at'], 'btr_maker_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_transfer_requests');
    }
};
