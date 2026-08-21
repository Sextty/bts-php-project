<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which branch an application is routed to. Filled at submission time from the same
     * BranchMatchingService that picks the appointment branch later, so branch-isolated staff can
     * be scoped with a plain column comparison instead of re-deriving the match on every query.
     * Nullable: applications submitted while no branch is configured stay unassigned (global to
     * unassigned staff and admins; invisible to branch-isolated staff).
     */
    public function up(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
