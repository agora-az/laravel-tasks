<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asm_settlement_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->string('source_file')->index();
            $table->string('file_hash', 64)->unique();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->decimal('total_settlement_amount', 18, 2)->default(0);
            $table->date('min_settlement_date')->nullable()->index();
            $table->date('max_settlement_date')->nullable()->index();
            $table->dateTime('min_create_date')->nullable();
            $table->dateTime('max_create_date')->nullable();
            $table->json('currency_totals')->nullable();
            $table->json('payment_type_totals')->nullable();
            $table->json('raw_summary_json')->nullable();
            $table->timestamps();
        });

        Schema::create('asm_settlement_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->foreignId('asm_settlement_summary_id')
                ->constrained('asm_settlement_summaries')
                ->cascadeOnDelete();
            $table->string('source_file')->index();
            $table->unsignedInteger('row_number');
            $table->date('settlement_date')->nullable()->index();
            $table->string('payment_id', 64)->nullable()->index();
            $table->string('sender_company_code', 32)->nullable()->index();
            $table->string('sender_company_name')->nullable();
            $table->string('receiver_company_code', 32)->nullable()->index();
            $table->string('receiver_company_name')->nullable();
            $table->string('payment_type', 16)->nullable()->index();
            $table->string('payment_type_description')->nullable();
            $table->string('currency', 8)->nullable()->index();
            $table->string('sender_payment_stream', 64)->nullable();
            $table->string('receiver_payment_stream', 64)->nullable();
            $table->decimal('settlement_amount', 18, 2)->default(0);
            $table->string('sender_account_number', 64)->nullable()->index();
            $table->string('sender_account_holder_name')->nullable();
            $table->string('sender_account_holder_sin', 32)->nullable();
            $table->string('sender_dealer_code', 32)->nullable()->index();
            $table->string('sender_rep_code', 32)->nullable()->index();
            $table->string('sender_account_type', 32)->nullable();
            $table->string('sender_account_type_description')->nullable();
            $table->boolean('sender_locked_in_account')->nullable();
            $table->string('sender_jurisdiction', 32)->nullable();
            $table->boolean('sender_spousal_account')->nullable();
            $table->string('sender_spousal_name')->nullable();
            $table->string('sender_spousal_sin', 32)->nullable();
            $table->text('sender_additional_information')->nullable();
            $table->string('receiver_dealer_account_number', 64)->nullable();
            $table->string('receiver_fund_company_account_number', 64)->nullable();
            $table->string('receiver_intermediary_account_number', 64)->nullable();
            $table->string('receiver_dealer_code', 32)->nullable()->index();
            $table->string('receiver_rep_code', 32)->nullable()->index();
            $table->string('receiver_account_holder_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->dateTime('create_date')->nullable()->index();
            $table->string('record_hash', 64)->unique();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['asm_settlement_summary_id', 'row_number'],
                'asm_settlement_summary_row_unique'
            );
            $table->index(
                ['settlement_date', 'currency', 'payment_type'],
                'asm_settlement_date_currency_type_idx'
            );
            $table->index('receiver_dealer_account_number', 'asm_receiver_dealer_acct_idx');
            $table->index('receiver_fund_company_account_number', 'asm_receiver_fund_acct_idx');
            $table->index('receiver_intermediary_account_number', 'asm_receiver_intermediary_acct_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asm_settlement_transactions');
        Schema::dropIfExists('asm_settlement_summaries');
    }
};
