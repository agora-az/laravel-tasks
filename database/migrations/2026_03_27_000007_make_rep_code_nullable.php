<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_fee_transactions', function (Blueprint $table) {
            // Make rep_code nullable since some records don't have values
            $table->string('rep_code')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_fee_transactions', function (Blueprint $table) {
            $table->string('rep_code')->nullable(false)->change();
        });
    }
};
