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
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->enum('reconcile_type', ['auto', 'manual'])->nullable()->after('confidence');
            $table->timestamp('reconcile_date')->nullable()->after('reconcile_type');
            $table->text('reconcile_notes')->nullable()->after('reconcile_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->dropColumn(['reconcile_type', 'reconcile_date', 'reconcile_notes']);
        });
    }
};
