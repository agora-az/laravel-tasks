<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_transaction_working_sets', function (Blueprint $table) {
            $table->id();
            $table->char('scope_hash', 40)->unique();
            $table->string('date_basis', 32);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('currency_code', 2);
            $table->json('status_ids');
            $table->string('state', 20)->default('warming')->index();
            $table->uuid('build_generation')->nullable();
            $table->uuid('active_generation')->nullable();
            $table->unsignedBigInteger('rows_cached')->default(0);
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('viefund_transaction_working_set_rows', function (Blueprint $table) {
            $table->foreignId('working_set_id')
                ->constrained('viefund_transaction_working_sets')
                ->cascadeOnDelete();
            $table->uuid('generation');
            $table->unsignedBigInteger('cash_transaction_id');
            $table->unsignedBigInteger('trust_transaction_id')->nullable();
            $table->unsignedBigInteger('fund_transaction_id')->nullable();
            $table->string('transaction_id', 40);
            $table->string('source_id', 100)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('plan_account_id', 100)->nullable();
            $table->string('transaction_type', 255)->nullable();
            $table->string('cash_status', 100)->nullable();
            $table->string('trust_status', 100)->nullable();
            $table->dateTime('created_date')->nullable();
            $table->dateTime('trade_date')->nullable();
            $table->dateTime('processing_date')->nullable();
            $table->dateTime('settlement_date')->nullable();
            $table->dateTime('basis_date')->nullable();
            $table->string('currency_code', 2)->nullable();
            $table->decimal('amount', 20, 4)->nullable();
            $table->string('match_status', 20)->default('Unknown');
            $table->boolean('has_eft_match')->default(false);
            $table->boolean('has_agra_fsp_match')->default(false);
            $table->boolean('has_7960_fsp_match')->default(false);
            $table->json('payload');
            $table->timestamps();

            $table->primary(
                ['working_set_id', 'generation', 'cash_transaction_id'],
                'viefund_working_set_rows_primary'
            );
            $table->index(
                ['working_set_id', 'generation', 'match_status', 'basis_date'],
                'viefund_working_set_match_date_idx'
            );
            $table->index(
                ['working_set_id', 'generation', 'basis_date', 'cash_transaction_id'],
                'viefund_working_set_page_idx'
            );
            $table->index(['working_set_id', 'generation', 'source_id'], 'viefund_working_set_source_idx');
            $table->index(['working_set_id', 'generation', 'plan_account_id'], 'viefund_working_set_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_transaction_working_set_rows');
        Schema::dropIfExists('viefund_transaction_working_sets');
    }
};