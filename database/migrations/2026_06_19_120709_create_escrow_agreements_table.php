<?php
// 📁 File: database/migrations/xxxx_xx_xx_xxxxxx_create_escrow_agreements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('property_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('client_name');
            $table->string('client_email');
            $table->decimal('total_amount', 12, 2);
            $table->string('status')->default('active'); // active, completed, refunded
            $table->integer('current_milestone_index')->default(0);
            $table->string('paystack_reference')->unique()->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_agreements');
    }
};