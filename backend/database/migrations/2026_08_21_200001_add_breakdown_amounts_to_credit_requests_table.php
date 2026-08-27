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
        Schema::table('credit_requests', function (Blueprint $table) {
            $table->decimal('montant_eqp', 14, 3)->nullable()->default(0)->after('montant_global_sollicite');
            $table->decimal('montant_fdr', 14, 3)->nullable()->default(0)->after('montant_eqp');
            $table->decimal('montant_amg', 14, 3)->nullable()->default(0)->after('montant_fdr');
            $table->decimal('montant_chp', 14, 3)->nullable()->default(0)->after('montant_amg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_requests', function (Blueprint $table) {
            $table->dropColumn(['montant_eqp', 'montant_fdr', 'montant_amg', 'montant_chp']);
        });
    }
};
