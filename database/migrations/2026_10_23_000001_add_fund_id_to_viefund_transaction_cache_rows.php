<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL keeps completed DDL when startup interrupts a migration.
        if (! Schema::hasColumn('viefund_transaction_mirror_rows', 'fund_id')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->string('fund_id', 100)->nullable();
            });
        }

        if (! Schema::hasIndex('viefund_transaction_mirror_rows', 'viefund_mirror_fund_id_idx')) {
            Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
                $table->index('fund_id', 'viefund_mirror_fund_id_idx');
            });
        }

        if (! Schema::hasColumn('viefund_transaction_working_set_rows', 'fund_id')) {
            Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
                $table->string('fund_id', 100)->nullable();
            });
        }

        if (! Schema::hasIndex('viefund_transaction_working_set_rows', 'viefund_working_set_fund_idx')) {
            Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
                $table->index(['working_set_id', 'generation', 'fund_id'], 'viefund_working_set_fund_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
            $table->dropIndex('viefund_working_set_fund_idx');
            $table->dropColumn('fund_id');
        });

        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
            $table->dropIndex('viefund_mirror_fund_id_idx');
            $table->dropColumn('fund_id');
        });
    }
};
