<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS viefund_mirror_funds_cash_lower_idx ON viefund_transaction_mirror_funds (cash_transaction_id, LOWER(fund_id))');
        DB::statement('CREATE INDEX IF NOT EXISTS viefund_mirror_funds_lower_type_idx ON viefund_transaction_mirror_funds (LOWER(fund_id), transaction_type_count, cash_transaction_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS viefund_mirror_rows_lower_checked_idx ON viefund_transaction_mirror_rows (LOWER(fund_id), fund_id_checked_at, cash_transaction_id)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS viefund_mirror_funds_cash_lower_idx');
        DB::statement('DROP INDEX IF EXISTS viefund_mirror_funds_lower_type_idx');
        DB::statement('DROP INDEX IF EXISTS viefund_mirror_rows_lower_checked_idx');
    }
};
