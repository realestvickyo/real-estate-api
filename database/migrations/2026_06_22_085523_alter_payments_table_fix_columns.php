<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 1. Make property_id nullable (if it's not already)
            $table->foreignId('property_id')->nullable()->change();
            
            // 2. Increase status length to 50 (or use a string column)
            $table->string('status', 50)->change();
            
            // 3. Ensure metadata column exists (JSON) – if not, add it
            if (!Schema::hasColumn('payments', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable(false)->change();
            $table->string('status', 20)->change();
            // drop metadata if we added it
            if (Schema::hasColumn('payments', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};