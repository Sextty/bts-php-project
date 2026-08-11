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
        // Belongs directly to credit_applications, not clients — matches the spec's
        // relationship diagram (Documents is a sibling of Client, not nested under it).
        // Required/optional document_type keys are config-driven (config/credit_documents.php),
        // not a separate lookup table, per the spec's "must be configurable" (not "admin-editable").
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('original_filename');
            $table->string('disk_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            // Soft-deleted while the application is still editable (spec: "Delete while
            // application is editable"); once FINAL_LOCKED, DocumentController::destroy
            // refuses the request entirely (403) rather than relying on this column.
            $table->softDeletes();

            $table->index(['credit_application_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
