<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_daily_totals', function (Blueprint $table) {
            // Fund status IDs (UB_Def_TrxStatus 0-6) and trust status names
            // (UB_Def_TrustStatus NameEN) used to build each snapshot row.
            // The drilldown reads these back so its live query reproduces the
            // same count/amount. Empty trust_status_names means trust excluded.
            $table->json('status_ids')->nullable()->after('transaction_count');
            $table->json('trust_status_names')->nullable()->after('status_ids');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_daily_totals', function (Blueprint $table) {
            $table->dropColumn(['status_ids', 'trust_status_names']);
        });
    }
};
