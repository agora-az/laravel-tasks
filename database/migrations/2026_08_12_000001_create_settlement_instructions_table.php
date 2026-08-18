<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('cascade');
            $table->string('source_file')->index();
            $table->string('source_type', 32)->index();
            $table->unsignedInteger('record_index');

            $table->date('create_date')->nullable()->index();
            $table->date('trade_date')->nullable()->index();
            $table->date('settlement_date')->nullable()->index();

            $table->string('management_code', 16)->nullable()->index();
            $table->string('fund_account_id', 64)->nullable()->index();
            $table->string('dealer_code', 32)->nullable()->index();
            $table->string('dealer_account_id', 64)->nullable()->index();
            $table->string('rep_code', 32)->nullable()->index();
            $table->string('intermediary_code', 32)->nullable()->index();
            $table->string('intermediary_account_id', 64)->nullable()->index();
            $table->string('account_type', 16)->nullable()->index();

            $table->string('order_id', 64)->nullable()->index();
            $table->string('order_source', 8)->nullable();
            $table->string('order_type', 16)->nullable();
            $table->string('source_id', 64)->nullable()->index();
            $table->string('order_status', 8)->nullable();

            $table->string('side', 8)->nullable()->index();
            $table->string('transaction_type', 16)->nullable();
            $table->string('fund_id', 32)->nullable()->index();
            $table->string('currency', 16)->nullable();
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->decimal('nav', 18, 6)->nullable();
            $table->decimal('units_transacted', 18, 6)->nullable();
            $table->string('settlement_method', 16)->nullable();
            $table->decimal('settlement_amount', 18, 2)->nullable();
            $table->string('settlement_source', 16)->nullable();

            $table->longText('raw_xml')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'record_index'], 'settlement_instructions_import_record_unique');
            $table->index(['source_type', 'settlement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_instructions');
    }
};
