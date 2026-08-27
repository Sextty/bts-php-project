<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Support 'security' role in staff_users table
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE staff_users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff'");
        } else {
            // For SQLite and other drivers
            Schema::table('staff_users', function (Blueprint $table) {
                // In SQLite enum columns are already text
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE staff_users MODIFY COLUMN role ENUM('staff', 'admin') NOT NULL DEFAULT 'staff'");
        }
    }
};
