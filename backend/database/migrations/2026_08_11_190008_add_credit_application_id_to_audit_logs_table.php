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
        Schema::table('audit_logs', function (Blueprint $table) {
            // Nullable: auth events (login, logout, OTP) have no application to attach to.
            $table->foreignId('credit_application_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->index(['credit_application_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_application_id');
        });
    }
};
