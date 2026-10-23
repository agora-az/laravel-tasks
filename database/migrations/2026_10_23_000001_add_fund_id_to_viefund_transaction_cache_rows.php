<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
            $table->string('fund_id', 100)->nullable();
            $table->index('fund_id', 'viefund_mirror_fund_id_idx');
        });

        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
            $table->string('fund_id', 100)->nullable()->after('source_id');
            $table->index(['working_set_id', 'generation', 'fund_id'], 'viefund_working_set_fund_idx');
        });
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
