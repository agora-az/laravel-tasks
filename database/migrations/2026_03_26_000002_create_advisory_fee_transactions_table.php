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
        Schema::create('advisory_fee_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('rep_code')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable(); // Combined name field for easy searching
            $table->string('plan_id')->nullable();
            $table->string('plan_info')->nullable();
            $table->string('transaction_type'); // Sell of fund, Paid to client, Deposit, etc
            $table->string('fund_code')->nullable();
            $table->string('fund_id')->nullable();
            $table->string('fund_description')->nullable();
            $table->string('description')->nullable();
            $table->string('trust_status')->nullable(); // Settled, Unsettled, Deleted
            $table->date('effective_date')->nullable();
            $table->date('settlement_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CAD');
            $table->string('created_user_id')->nullable();
            $table->string('last_modified_user_id')->nullable();
            $table->timestamps();
            
            // Indices for common searches
            $table->index('rep_code');
            $table->index('full_name');
            $table->index('settlement_date');
            $table->index('transaction_type');
            $table->index('plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advisory_fee_transactions');
    }
};
