<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transaction_working_sets', function (Blueprint $table) {
            $table->uuid('balance_generation')->nullable()->after('build_generation');
            $table->json('balance_report')->nullable()->after('balance_generation');
            $table->timestamp('balance_calculated_at')->nullable()->after('balance_report');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_working_sets', function (Blueprint $table) {
            $table->dropColumn(['balance_generation', 'balance_report', 'balance_calculated_at']);
        });
    }
};