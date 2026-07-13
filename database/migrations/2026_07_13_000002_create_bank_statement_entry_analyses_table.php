<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_entry_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_entry_id')
                ->constrained('bank_statement_entries')
                ->onDelete('cascade');
            $table->string('parser_version', 20)->default('v1');
            $table->string('memo_type', 100)->nullable()->index();
            $table->string('settlement_number', 100)->nullable()->index();
            $table->string('wire_payment_reference', 100)->nullable()->index();
            $table->string('counterparty', 255)->nullable()->index();
            $table->string('inferred_channel', 100)->nullable()->index();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('normalized_additional_info')->nullable();
            $table->json('parse_flags')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_entry_id', 'parser_version'], 'entry_parser_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_entry_analyses');
    }
};
