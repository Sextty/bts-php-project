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
        // Étape 1 — Client / Personne Physique. One client profile per application (1:1),
        // per the spec's relationship diagram. Field names/order match the spec verbatim.
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_client')->nullable();
            $table->string('civilite')->nullable();
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
            $table->string('nom_epoux')->nullable();
            $table->string('deuxieme_prenom')->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('lieu_naissance')->nullable();
            $table->string('pays_naissance')->nullable();
            $table->string('nationalite')->nullable();
            $table->string('pays_residence')->nullable();
            $table->string('etat_civil')->nullable();
            $table->unsignedSmallInteger('nombre_enfants')->nullable();
            $table->string('type_pid')->nullable();
            $table->string('numero_pid')->nullable();
            $table->date('date_delivrance_pid')->nullable();
            $table->string('lieu_delivrance_pid')->nullable();
            $table->string('numero_carte_sejour')->nullable();
            $table->string('profession')->nullable();
            $table->date('date_entree_relation')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
