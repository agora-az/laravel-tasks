<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_viefund_customer_transactions', function (Blueprint $table) {
            // Intentionally minimal — this is a lookup table only.
            // cash_trx_id (UB_CashTrx.ID) is the natural key and primary key;
            // no surrogate id, no timestamps, no duplicated columns from remote.
            $table->unsignedBigInteger('cash_trx_id')->primary()->comment('UB_CashTrx.ID from the remote VieFund database');
            $table->unsignedBigInteger('viefund_customer_id')->comment('UB_Customer.ID — required to identify and clean up partial rows on a resumed sync');
            $table->decimal('amount', 15, 4)->default(0)->comment('UB_CashTrx.mAmount — stored for eyeball-verification against remote DB');
            $table->decimal('running_balance', 15, 4)->default(0)->comment('Pre-computed cumulative balance ordered by customer → trx_id → cash_trx_id');

            $table->index('viefund_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_viefund_customer_transactions');
    }
};
