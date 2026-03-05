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
            // Drop the old constraint that prevented multiple VieFund from matching same Fundserv
            $table->dropUnique('unique_right_match');

            // Add new constraint: prevent same VieFund-Fundserv pair from being created twice
            // But allow multiple VieFund to match same Fundserv (for grouping)
            $table->unique(['left_id', 'right_id', 'match_rule'], 'unique_match_pair');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            // Restore original constraint
            $table->dropUnique('unique_match_pair');
            $table->unique(['right_type', 'right_id', 'match_rule'], 'unique_right_match');
        });
    }
};
