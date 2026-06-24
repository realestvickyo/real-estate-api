<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('secure_documents')) {
            Schema::create('secure_documents', function (Blueprint $table) {
                $table->id();
                // Ensure your document columns are mapped here, for example:
                $table->foreignId('lead_id')->constrained()->onDelete('cascade');
                $table->string('document_name');
                $table->string('file_path');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secure_documents');
    }
};