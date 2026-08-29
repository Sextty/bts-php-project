<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every document row must point at exactly one stored file. disk_path is generated
 * server-side as a UUID-based name (never user input), so a duplicate path would mean
 * two rows pointing at the same file — which would also break soft-delete cleanup.
 * The unique constraint makes that impossible at the database instead of relying on
 * the application layer alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unique('disk_path');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['disk_path']);
        });
    }
};