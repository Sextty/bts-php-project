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
            // Nullable, mirrors credit_application_id: most events have no staff actor at all
            // (customer auth, customer-side application edits).
            $table->foreignId('staff_user_id')->nullable()->after('user_id')
                ->constrained('staff_users')->nullOnDelete();

            $table->index(['staff_user_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_user_id');
        });
    }
};
