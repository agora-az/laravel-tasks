<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundserv_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->date('settlement_date')->nullable();
            $table->string('code')->nullable();
            $table->string('src', 10)->nullable();
            $table->date('trade_date')->nullable();
            $table->string('fund_id')->nullable();
            $table->string('dealer_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('source_identifier')->nullable();
            $table->string('tx_type')->nullable();
            $table->decimal('settlement_amt', 15, 2)->default(0);
            $table->string('record_hash', 64)->unique()->comment('SHA256 hash for duplicate detection');
            $table->timestamps();
            
            $table->index('settlement_date');
            $table->index('trade_date');
            $table->index('dealer_account_id');
            $table->unique(['order_id', 'source_identifier'], 'order_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundserv_transactions');
    }
};
