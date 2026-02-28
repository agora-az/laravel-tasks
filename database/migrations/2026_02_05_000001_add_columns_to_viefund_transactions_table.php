<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transactions', function (Blueprint $table) {
            $table->string('trx_id')->nullable()->after('account_id');
            $table->dateTime('created_date')->nullable()->after('trx_id');
            $table->string('trx_type')->nullable()->after('created_date');
            $table->date('trade_date')->nullable()->after('trx_type');
            $table->date('settlement_date')->nullable()->after('trade_date');
            $table->date('processing_date')->nullable()->after('settlement_date');
            $table->string('source_id')->nullable()->after('processing_date');
            $table->decimal('amount', 15, 2)->nullable()->after('source_id');
            $table->decimal('balance', 15, 2)->nullable()->after('amount');
            $table->string('fund_code')->nullable()->after('balance');
            $table->string('fund_trx_type')->nullable()->after('fund_code');
            $table->decimal('fund_trx_amount', 15, 2)->nullable()->after('fund_trx_type');
            $table->string('fund_settlement_source')->nullable()->after('fund_trx_amount');
            $table->string('fund_wo_number')->nullable()->after('fund_settlement_source');
            $table->string('fund_source_id')->nullable()->after('fund_wo_number');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'trx_id',
                'created_date',
                'trx_type',
                'trade_date',
                'settlement_date',
                'processing_date',
                'source_id',
                'amount',
                'balance',
                'fund_code',
                'fund_trx_type',
                'fund_trx_amount',
                'fund_settlement_source',
                'fund_wo_number',
                'fund_source_id',
            ]);
        });
    }
};
