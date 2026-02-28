<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundserv_transactions', function (Blueprint $table) {
            $table->decimal('actual_amount', 15, 2)->nullable()->after('settlement_amt');
        });
    }

    public function down(): void
    {
        Schema::table('fundserv_transactions', function (Blueprint $table) {
            $table->dropColumn('actual_amount');
        });
    }
};
