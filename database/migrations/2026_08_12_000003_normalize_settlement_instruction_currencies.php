<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settlement_instructions')->where('currency', '00')->update(['currency' => 'CAD']);
        DB::table('settlement_instructions')->where('currency', '01')->update(['currency' => 'USD']);

        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->index('currency', 'settlement_instructions_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->dropIndex('settlement_instructions_currency_idx');
        });

        DB::table('settlement_instructions')->where('currency', 'CAD')->update(['currency' => '00']);
        DB::table('settlement_instructions')->where('currency', 'USD')->update(['currency' => '01']);
    }
};