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
        Schema::create('account_fee_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('rep_code')->nullable();
            $table->string('client_name');
            $table->string('plan_description')->nullable();
            $table->string('account_description')->nullable();
            $table->string('transaction_type'); // Fee, Tax, Trustee Fee
            $table->string('wire_number')->nullable();
            $table->date('trade_date')->nullable();
            $table->date('settlement_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('order_status')->nullable(); // Confirmed, Deleted, etc
            $table->string('trust_status')->nullable(); // Settled, Unsettled
            $table->string('user_id')->nullable();
            $table->timestamps();
            
            // Indices for common searches
            $table->index('client_name');
            $table->index('settlement_date');
            $table->index('transaction_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_fee_transactions');
    }
};
