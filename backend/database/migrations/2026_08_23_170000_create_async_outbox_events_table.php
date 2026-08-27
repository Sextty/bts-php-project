<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('async_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80);
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('queue_delay_ms')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'async_outbox_dispatch_index');
            $table->index(['aggregate_type', 'aggregate_id'], 'async_outbox_aggregate_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('async_outbox_events');
    }
};
