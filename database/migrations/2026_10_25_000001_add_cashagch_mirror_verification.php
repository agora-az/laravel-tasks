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
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->upgradeMySqlRows();
        } else {
            foreach (['trust_amount_used', 'trust_amount_left'] as $name) {
                if (!Schema::hasColumn('viefund_transaction_mirror_rows', $name)) {
                    Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table) use ($name): void {
                        $table->decimal($name, 20, 4)->nullable();
                    });
                }
            }
            foreach (['created', 'trade', 'processing', 'settlement'] as $basis) {
                $index = "viefund_mirror_{$basis}_cursor_idx";
                if (!Schema::hasIndex('viefund_transaction_mirror_rows', $index)) {
                    Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table) use ($basis, $index): void {
                        $table->index([$basis.'_date', 'cash_transaction_id'], $index);
                    });
                }
            }
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                foreach (['created_date', 'trade_date', 'processing_date', 'settlement_date'] as $date) {
                    $table->dateTime($date, 6)->nullable()->change();
                }
            });
        }
        foreach (['cashagch_verification' => 'json', 'cashagch_verified_at' => 'timestamp',
            'cashagch_scope_hash' => 'string', 'cashagch_verification_error' => 'text'] as $name => $type) {
            if (!Schema::hasColumn('viefund_transaction_mirror_syncs', $name)) {
                Schema::table('viefund_transaction_mirror_syncs', function (Blueprint $table) use ($name, $type): void {
                    $table->$type($name)->nullable();
                });
            }
        }
    }

    private function upgradeMySqlRows(): void
    {
        $table = 'viefund_transaction_mirror_rows';
        $columns = array_column(Schema::getColumns($table), null, 'name');
        $changes = [];

        foreach (['trust_amount_used', 'trust_amount_left'] as $name) {
            if (!isset($columns[$name])) {
                $changes[] = "ADD COLUMN `{$name}` DECIMAL(20, 4) NULL";
            }
        }
        foreach (['created', 'trade', 'processing', 'settlement'] as $basis) {
            $date = $basis.'_date';
            if (($columns[$date]['type'] ?? null) !== 'datetime(6)' || !$columns[$date]['nullable']) {
                $changes[] = "MODIFY COLUMN `{$date}` DATETIME(6) NULL";
            }
            $index = "viefund_mirror_{$basis}_cursor_idx";
            if (!Schema::hasIndex($table, $index)) {
                $changes[] = "ADD INDEX `{$index}` (`{$date}`, `cash_transaction_id`)";
            }
        }

        // Widen timestamps and build missing indexes in one large-table rebuild.
        if ($changes !== []) {
            $connection = Schema::getConnection();
            $connection->statement('ALTER TABLE '.$connection->getSchemaGrammar()->wrapTable($table).' '.implode(', ', $changes));
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
