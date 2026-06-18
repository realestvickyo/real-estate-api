<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePaymentsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Safe check to see if the payments table is already built
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id');
                $table->foreignId('user_id');
                $table->foreignId('property_id');
                $table->decimal('amount', 10, 2);
                $table->string('merchant_request_id')->nullable();
                $table->string('checkout_request_id')->nullable();
                $table->string('receipt_number')->nullable();
                $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
}