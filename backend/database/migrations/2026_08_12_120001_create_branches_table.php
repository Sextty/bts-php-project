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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ville');
            $table->string('delegation')->nullable();
            $table->string('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Per-branch, not hardcoded: "4 appointments/day, 08:00-12:00" is this project's
            // current numbers, but different branches may need different capacity/hours later
            // without a migration.
            $table->unsignedTinyInteger('daily_capacity')->default(4);
            $table->time('slot_start_time')->default('08:00:00');
            $table->time('slot_end_time')->default('12:00:00');
            // Fallback branch when a project's `ville` doesn't exact-match any branch — exactly
            // one branch should have this set once real data exists.
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('ville');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
