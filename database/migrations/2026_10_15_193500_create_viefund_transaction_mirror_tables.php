<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_transaction_mirror_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_transaction_id')->primary();
            $table->string('transaction_id', 40);
            $table->unsignedBigInteger('fund_transaction_id')->nullable();
            $table->unsignedBigInteger('trust_transaction_id')->nullable();
            $table->string('ledger_relationship', 80)->nullable();
            $table->string('source_id', 100)->nullable()->index();
            $table->string('customer_name', 255)->nullable();
            $table->string('plan_account_id', 100)->nullable()->index();
            $table->string('transaction_type', 255)->nullable();
            $table->unsignedTinyInteger('cash_status_id')->nullable()->index();
            $table->string('cash_status', 100)->nullable();
            $table->string('trust_status', 100)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_date')->nullable();
            $table->dateTime('trade_date')->nullable();
            $table->dateTime('processing_date')->nullable();
            $table->dateTime('settlement_date')->nullable();
            $table->string('currency_code', 2)->nullable()->index();
            $table->decimal('amount', 20, 4)->nullable();
            $table->string('match_status', 20)->default('Unknown')->index();
            $table->boolean('has_eft_match')->default(false);
            $table->boolean('has_agra_fsp_match')->default(false);
            $table->boolean('has_7960_fsp_match')->default(false);
            $table->boolean('has_asm_match')->default(false);
            $table->json('payload');
            $table->json('enrichment')->nullable();
            $table->uuid('sync_generation')->index();
            $table->char('source_fingerprint', 64);
            $table->timestamp('source_synced_at');
            $table->timestamps();

            $table->index(
                ['settlement_date', 'currency_code', 'cash_status_id', 'cash_transaction_id'],
                'viefund_mirror_settlement_filter_idx'
            );
            $table->index(
                ['trade_date', 'currency_code', 'cash_status_id', 'cash_transaction_id'],
                'viefund_mirror_trade_filter_idx'
            );
            $table->index(
                ['processing_date', 'currency_code', 'cash_status_id', 'cash_transaction_id'],
                'viefund_mirror_processing_filter_idx'
            );
            $table->index(
                ['created_date', 'currency_code', 'cash_status_id', 'cash_transaction_id'],
                'viefund_mirror_created_filter_idx'
            );
        });

        Schema::create('viefund_transaction_mirror_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->default('initial');
            $table->string('state', 24)->default('idle')->index();
            $table->string('phase', 40)->nullable();
            $table->date('target_from_date')->nullable();
            $table->date('target_through_date')->nullable();
            $table->string('current_currency', 2)->nullable();
            $table->json('currencies')->nullable();
            $table->json('status_ids')->nullable();
            $table->json('cursor')->nullable();
            $table->uuid('sync_generation')->nullable();
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('expected_rows')->nullable();
            $table->decimal('active_seconds', 14, 3)->default(0);
            $table->json('batch_metrics')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_batch_started_at')->nullable();
            $table->timestamp('last_batch_completed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('synced_through_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_transaction_mirror_rows');
        Schema::dropIfExists('viefund_transaction_mirror_syncs');
    }
};
