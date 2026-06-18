<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('escrow_agreements', function (Blueprint $table) {
        $table->id();
        $table->string('client_name')->nullable();
        $table->string('client_email');
        $table->string('provider_name')->nullable();
        $table->string('provider_email');
        $table->string('provider_phone')->nullable();
        $table->decimal('amount', 15, 2);
        $table->string('status')->default('pending_payment'); // pending_payment, held, inspection, released, refunded
        $table->string('payment_reference')->nullable();
        $table->decimal('amount_paid', 15, 2)->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->string('transfer_code')->nullable();
        $table->json('ai_verification_log')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escrow_agreements');
    }
};
