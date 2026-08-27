<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('previous_hash', 64)->nullable()->after('user_agent');
            $table->char('integrity_hash', 64)->nullable()->unique()->after('previous_hash');
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('last_hash', 64)->nullable();
            $table->unsignedBigInteger('last_audit_log_id')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('audit_chain_heads')->insert([
            'id' => 1,
            'last_hash' => null,
            'last_audit_log_id' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique(['integrity_hash']);
            $table->dropColumn(['previous_hash', 'integrity_hash']);
        });
    }
};
