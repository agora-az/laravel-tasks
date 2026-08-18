<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('cascade');
            $table->string('source_file')->index();
            $table->unsignedInteger('statement_index')->default(0);
            $table->string('message_id')->nullable()->index();
            $table->timestamp('group_created_at')->nullable();
            $table->string('statement_id')->nullable()->index();
            $table->timestamp('statement_created_at')->nullable();
            $table->string('account_number')->nullable()->index();

            $table->string('opening_balance_type_code', 30)->nullable();
            $table->decimal('opening_balance_amount', 18, 2)->nullable();
            $table->string('opening_balance_currency', 10)->nullable();
            $table->string('opening_balance_indicator', 4)->nullable();
            $table->decimal('opening_balance_signed_amount', 18, 2)->nullable();
            $table->date('opening_balance_date')->nullable();

            $table->string('closing_balance_type_code', 30)->nullable();
            $table->decimal('closing_balance_amount', 18, 2)->nullable();
            $table->string('closing_balance_currency', 10)->nullable();
            $table->string('closing_balance_indicator', 4)->nullable();
            $table->decimal('closing_balance_signed_amount', 18, 2)->nullable();
            $table->date('closing_balance_date')->nullable();

            $table->unsignedInteger('total_credit_entries')->nullable();
            $table->decimal('total_credit_sum', 18, 2)->nullable();
            $table->unsignedInteger('total_debit_entries')->nullable();
            $table->decimal('total_debit_sum', 18, 2)->nullable();

            $table->longText('raw_statement_xml')->nullable();
            $table->json('raw_statement_json')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'statement_index'], 'bank_statement_summaries_import_statement_unique');
            $table->index(['source_file', 'statement_id']);
        });

        Schema::create('bank_statement_summary_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_statement_summary_id');
            $table->unsignedInteger('balance_index')->default(0);
            $table->string('balance_type_code', 30)->nullable()->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 10)->nullable()->index();
            $table->string('credit_debit_indicator', 4)->nullable();
            $table->decimal('signed_amount', 18, 2)->nullable();
            $table->date('balance_date')->nullable()->index();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_summary_id', 'balance_index'], 'bank_statement_summary_balances_unique');
            $table->foreign('bank_statement_summary_id', 'bank_stmt_sum_balances_summary_fk')
                ->references('id')
                ->on('bank_statement_summaries')
                ->onDelete('cascade');
        });

        Schema::table('bank_statement_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_statement_summary_id')->nullable()->after('import_id');
            $table->foreign('bank_statement_summary_id', 'bank_stmt_entries_summary_fk')
                ->references('id')
                ->on('bank_statement_summaries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_entries', function (Blueprint $table) {
            $table->dropForeign('bank_stmt_entries_summary_fk');
            $table->dropColumn('bank_statement_summary_id');
        });

        Schema::dropIfExists('bank_statement_summary_balances');
        Schema::dropIfExists('bank_statement_summaries');
    }
};
