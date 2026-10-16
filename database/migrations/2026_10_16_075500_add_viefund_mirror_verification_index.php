<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table) {
            $table->index(
                ['currency_code', 'cash_status_id', 'created_date', 'sync_generation', 'cash_transaction_id'],
                'viefund_mirror_verification_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table) {
            $table->dropIndex('viefund_mirror_verification_idx');
        });
    }
};
