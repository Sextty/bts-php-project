<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['app_notification_id', 'channel'], 'notification_delivery_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
