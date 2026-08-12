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
        // Was a MySQL ENUM. Widening an ENUM needs a driver-specific ALTER ... MODIFY (no
        // cross-DB schema-builder helper for it), which also doesn't run at all on SQLite — this
        // project's test suite runs on SQLite (see phpunit.xml), so that raw statement broke
        // every test touching credit_applications the moment this migration ran, caught live.
        // Converting to a plain string column instead sidesteps the whole class of problem: the
        // valid-value set is already enforced at the application layer (CreditApplication's
        // STATUS_* constants, CreditApplicationService/CreditApplicationReviewService's
        // transition checks), so the ENUM was redundant belt-and-braces at best, and a recurring
        // migration headache at worst — every future status addition would hit this same issue.
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->string('status', 50)->default('DRAFT')->change();
        });

        Schema::table('credit_applications', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('submitted_at');
            $table->foreignId('decided_by_staff_user_id')->nullable()->after('rejection_reason')
                ->constrained('staff_users')->nullOnDelete();
            $table->foreignId('decided_by_admin_user_id')->nullable()->after('decided_by_staff_user_id')
                ->constrained('staff_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_admin_user_id');
            $table->dropConstrainedForeignId('decided_by_staff_user_id');
            $table->dropColumn('rejection_reason');
        });

        // Not reverted back to an ENUM — down() restoring the exact prior column type isn't worth
        // reintroducing the cross-DB ALTER problem this migration exists to avoid; a plain string
        // column with the original (smaller) value set enforced at the application layer is a
        // strictly safe rollback.
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->string('status', 50)->default('DRAFT')->change();
        });
    }
};
