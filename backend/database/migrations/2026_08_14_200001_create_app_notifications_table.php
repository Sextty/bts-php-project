<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per logical notification (not per channel). A notification of the same type for
     * the same recipient with the same dedupe_key is unique, so replaying an event (queue
     * retry, double submit) can never produce duplicate rows. The unique constraint is on the
     * logical identity only — channel delivery is fan-out in DeliverNotificationJob, not rows.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            // Polymorphic notifiable: User (customer) or StaffUser (staff member).
            $table->morphs('notifiable');
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            // Business-scoped dedupe key (e.g. "document-12", "appointment-9"); null when the
            // type must not be deduplicated. Combined with type+notifiable by the unique index.
            $table->string('dedupe_key')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'type', 'dedupe_key'], 'app_notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};