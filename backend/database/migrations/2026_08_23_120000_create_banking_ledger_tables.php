<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('account_number', 34)->unique();
            $table->string('product_code', 32);
            $table->string('currency', 3)->default('TND');
            $table->enum('account_kind', ['customer', 'system']);
            // Balance is always derived from immutable entries: a liability/customer
            // account has credit as its normal side, an asset/system account debit.
            $table->enum('normal_side', ['debit', 'credit']);
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->unique(['user_id', 'product_code', 'currency']);
            $table->index(['branch_id', 'status']);
            $table->index(['account_kind', 'currency']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->enum('transaction_type', ['deposit', 'transfer']);
            $table->enum('status', ['posted']);
            $table->string('currency', 3);
            $table->string('description', 255);
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_staff_user_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_type', 'posted_at']);
            $table->index(['initiated_by_staff_user_id', 'posted_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->enum('direction', ['debit', 'credit']);
            $table->unsignedBigInteger('amount_millimes');
            $table->string('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ledger_transaction_id', 'sequence']);
            $table->index(['bank_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
