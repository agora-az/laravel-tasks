<?php

use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\Reconciliation\EftBankMatchStatusService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('viefund_transaction_working_set_rows')
            ->where('has_asm_match', true)
            ->where('match_status', EftBankMatchStatusService::UNKNOWN)
            ->update(['match_status' => AllTransactionMatchStatusResolver::ASM_ONLY]);
    }

    public function down(): void
    {
        DB::table('viefund_transaction_working_set_rows')
            ->where('match_status', AllTransactionMatchStatusResolver::ASM_ONLY)
            ->update(['match_status' => EftBankMatchStatusService::UNKNOWN]);
    }
};
