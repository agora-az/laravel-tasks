<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table) {
            $table->json('enrichment')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table) {
            $table->dropColumn('enrichment');
        });
    }
};