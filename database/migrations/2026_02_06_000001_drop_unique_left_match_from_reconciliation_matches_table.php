<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->dropUnique('unique_left_match');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->unique(['left_type', 'left_id', 'match_rule'], 'unique_left_match');
        });
    }
};
