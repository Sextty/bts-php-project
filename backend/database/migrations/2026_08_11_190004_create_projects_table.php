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
        // Étape 3 — Informations Projet. One project per application (1:1). Field list is
        // taken verbatim from the spec's own example list — there is no legacy "project"
        // entity anywhere in the archived old/ system to reconcile against (confirmed by
        // repo-wide search), so nothing was added or dropped from what the spec gave.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_projet')->nullable();
            $table->string('identifiant_personne')->nullable();
            $table->string('nom_ou_rs')->nullable();
            $table->string('prenom_ou_dc')->nullable();
            $table->string('type_projet')->nullable();
            $table->string('objet')->nullable();
            $table->string('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->string('code_postal')->nullable();
            $table->string('activite')->nullable();
            $table->text('description')->nullable();
            $table->string('delegation')->nullable();
            $table->string('localisation')->nullable();
            $table->decimal('cout', 14, 3)->nullable();
            $table->decimal('investissement_personnel', 14, 3)->nullable();
            $table->decimal('financement', 14, 3)->nullable();
            $table->decimal('revenus', 14, 3)->nullable();
            $table->decimal('depenses', 14, 3)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
