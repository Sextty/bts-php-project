<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assigns a staff member to a branch (nullable — an unassigned staff member has no branch
     * restriction; see StaffUser::canAccessApplication()). This is the hook the branch-isolation
     * rules hang off: a staff member with a branch_id may only see applications routed to that
     * branch, while admins and unassigned staff remain global. Future roles (branch_manager,
     * credit_officer) will be scoped by the same column.
     */
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
