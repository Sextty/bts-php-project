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
        // Telegram OTP delivery has been replaced by email — these columns have no remaining
        // reader. A new migration rather than editing the two that added them, since those have
        // already run against real databases.
        // The unique indexes must go first — SQLite rebuilds the table to drop a column, and that
        // rebuild fails if an index still references a column that's disappearing mid-migration.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropUnique(['telegram_link_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->unique();
            $table->string('telegram_link_token')->nullable()->unique();
        });
    }
};
