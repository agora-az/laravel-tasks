<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('cascade');
            $table->string('source_file')->index();
            $table->string('message_id')->nullable()->index();
            $table->string('statement_id')->nullable()->index();
            $table->string('account_number')->nullable()->index();
            $table->unsignedInteger('entry_index')->default(0);
            $table->string('entry_reference')->nullable()->index();
            $table->date('booking_date')->nullable()->index();
            $table->date('value_date')->nullable()->index();
            $table->string('credit_debit_indicator', 4)->nullable()->index();
            $table->string('status', 20)->nullable();
            $table->string('currency', 10)->nullable()->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('bank_domain_code', 10)->nullable()->index();
            $table->string('bank_family_code', 10)->nullable()->index();
            $table->string('bank_sub_family_code', 10)->nullable()->index();
            $table->text('additional_info')->nullable();
            $table->longText('raw_xml')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->index(['source_file', 'entry_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_entries');
    }
};
