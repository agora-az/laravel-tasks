<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('viefund_transaction_mirror_funds', 'transaction_type_count')) {
            Schema::table('viefund_transaction_mirror_funds', function (Blueprint $table): void {
                $table->unsignedInteger('transaction_type_count')->nullable();
            });
        }
        if (!Schema::hasIndex('viefund_transaction_mirror_funds', 'viefund_mirror_report_type_idx')) {
            Schema::table('viefund_transaction_mirror_funds', function (Blueprint $table): void {
                $table->index(['fund_id', 'transaction_type_count', 'cash_transaction_id'], 'viefund_mirror_report_type_idx');
            });
        }
        if (!Schema::hasColumn('viefund_transaction_mirror_rows', 'trust_amount_used')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->decimal('trust_amount_used', 20, 4)->nullable();
            });
        }
        foreach (['created', 'trade', 'processing', 'settlement'] as $basis) {
            $index = "viefund_mirror_{$basis}_cursor_idx";
            if (!Schema::hasIndex('viefund_transaction_mirror_rows', $index)) {
                Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table) use ($basis, $index): void {
                    $table->index([$basis.'_date', 'cash_transaction_id'], $index);
                });
            }
        }
        if (!Schema::hasColumn('viefund_transaction_mirror_rows', 'trust_amount_left')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->decimal('trust_amount_left', 20, 4)->nullable();
            });
        }
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
            foreach (['created_date', 'trade_date', 'processing_date', 'settlement_date'] as $date) {
                $table->dateTime($date, 6)->nullable()->change();
            }
        });
        foreach (['cashagch_verification' => 'json', 'cashagch_verified_at' => 'timestamp',
            'cashagch_scope_hash' => 'string', 'cashagch_verification_error' => 'text'] as $name => $type) {
            if (!Schema::hasColumn('viefund_transaction_mirror_syncs', $name)) {
                Schema::table('viefund_transaction_mirror_syncs', function (Blueprint $table) use ($name, $type): void {
                    $table->$type($name)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_mirror_syncs', function (Blueprint $table): void {
            $table->dropColumn(['cashagch_verification', 'cashagch_verified_at', 'cashagch_scope_hash', 'cashagch_verification_error']);
        });
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
            $table->dropColumn(['trust_amount_used', 'trust_amount_left']);
            foreach (['created', 'trade', 'processing', 'settlement'] as $basis) {
                $table->dropIndex("viefund_mirror_{$basis}_cursor_idx");
            }
        });
        Schema::table('viefund_transaction_mirror_funds', function (Blueprint $table): void {
            $table->dropIndex('viefund_mirror_report_type_idx');
            $table->dropColumn('transaction_type_count');
        });
    }
};
