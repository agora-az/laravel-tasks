<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_cash_daily_snapshots', function (Blueprint $table) {
            $table->string('reviewed_by_label')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_cash_daily_snapshots', function (Blueprint $table) {
            $table->dropColumn('reviewed_by_label');
        });
    }
};
