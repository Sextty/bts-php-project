<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The original ENUM only contained the customer stages even though the domain state
     * machine also persists staff, admin, appointment, rejection and cancellation stages.
     * A VARCHAR keeps the database compatible with the complete state machine; transitions
     * remain constrained centrally by CreditApplicationStateMachine.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE credit_applications MODIFY status VARCHAR(50) NOT NULL DEFAULT 'DRAFT'");
        }
    }

    /**
     * Deliberately irreversible: restoring the incomplete ENUM could make valid production
     * rows impossible to represent and would risk truncating workflow state.
     */
    public function down(): void
    {
        // No destructive rollback for workflow state.
    }
};
