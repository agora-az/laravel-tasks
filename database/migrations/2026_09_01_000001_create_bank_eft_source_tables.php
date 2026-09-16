<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_eft_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->string('source_file')->unique();
            $table->unsignedInteger('sequence_number')->index();
            $table->date('file_date')->nullable()->index();
            $table->string('client_number', 16)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('logical_record_count')->nullable();
            $table->unsignedInteger('declared_transaction_count');
            $table->unsignedInteger('parsed_transaction_count');
            $table->decimal('declared_total_amount', 18, 2);
            $table->decimal('parsed_total_amount', 18, 2);
            $table->string('header_hash', 64);
            $table->string('trailer_hash', 64);
            $table->timestamps();

            $table->index(['sequence_number', 'file_date']);
        });

        Schema::create('bank_eft_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_eft_file_id')->constrained('bank_eft_files')->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->string('source_file')->index();
            $table->unsignedInteger('sequence_number')->index();
            $table->unsignedInteger('line_number');
            $table->unsignedTinyInteger('segment_number');
            $table->string('transaction_code', 3)->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('effective_date')->nullable()->index();
            $table->string('bank_code', 3)->nullable();
            $table->string('bank_transit', 5)->nullable();
            $table->string('bank_account_last4', 4)->nullable();
            $table->string('bank_account_hash', 64)->nullable()->index();
            $table->string('holder_name', 64)->nullable();
            $table->string('holder_id', 64)->nullable()->index();
            $table->string('originator_short_name', 32)->nullable();
            $table->string('originator_long_name', 64)->nullable();
            $table->string('originator_id', 32)->nullable();
            $table->string('trust_account_last4', 4)->nullable();
            $table->string('trust_account_hash', 64)->nullable();
            $table->string('record_hash', 64);
            $table->timestamps();

            $table->unique(['bank_eft_file_id', 'line_number', 'segment_number'], 'bank_eft_txn_file_line_segment_unique');
            $table->index(['sequence_number', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_eft_transactions');
        Schema::dropIfExists('bank_eft_files');
    }
};
