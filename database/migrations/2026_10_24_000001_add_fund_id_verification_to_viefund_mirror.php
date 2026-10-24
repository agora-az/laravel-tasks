<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('viefund_transaction_mirror_funds')) {
            Schema::create('viefund_transaction_mirror_funds', function (Blueprint $table): void {
                $table->unsignedBigInteger('cash_transaction_id');
                $table->string('fund_id', 100);
                $table->unsignedBigInteger('fund_transaction_id')->nullable();
                $table->string('source_id', 100)->nullable();
                $table->string('transaction_type', 255)->nullable();
                $table->string('cash_source_id', 100)->nullable();
                $table->string('cash_transaction_type', 255)->nullable();
                $table->primary(['cash_transaction_id', 'fund_id'], 'viefund_mirror_funds_pk');
                $table->index('fund_id', 'viefund_mirror_funds_id_idx');
                $table->foreign('cash_transaction_id', 'viefund_mirror_funds_cash_fk')
                    ->references('cash_transaction_id')->on('viefund_transaction_mirror_rows')->cascadeOnDelete();
            });
        }
        if (! Schema::hasColumn('viefund_transaction_mirror_rows', 'fund_id_checked_at')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->timestamp('fund_id_checked_at')->nullable();
            });
        }
        if (! Schema::hasIndex('viefund_transaction_mirror_rows', 'viefund_mirror_fund_checked_idx')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->index(['fund_id_checked_at', 'cash_transaction_id'], 'viefund_mirror_fund_checked_idx');
            });
        }
        if (! Schema::hasColumn('viefund_transaction_mirror_syncs', 'fund_ids_verified_at')) {
            Schema::table('viefund_transaction_mirror_syncs', function (Blueprint $table): void {
                $table->timestamp('fund_ids_verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_transaction_mirror_funds');
        Schema::table('viefund_transaction_mirror_syncs', function (Blueprint $table): void {
            $table->dropColumn('fund_ids_verified_at');
        });
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
            $table->dropIndex('viefund_mirror_fund_checked_idx');
            $table->dropColumn('fund_id_checked_at');
        });
    }
};
