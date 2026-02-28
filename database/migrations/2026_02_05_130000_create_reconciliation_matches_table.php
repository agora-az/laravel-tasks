<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->string('left_type', 50);
            $table->unsignedBigInteger('left_id');
            $table->string('right_type', 50);
            $table->unsignedBigInteger('right_id');
            $table->string('match_rule', 100);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->decimal('matched_amount', 15, 2)->nullable();
            $table->string('status', 30)->default('matched');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['left_type', 'left_id']);
            $table->index(['right_type', 'right_id']);
            $table->index('match_rule');
            $table->unique(['left_type', 'left_id', 'match_rule'], 'unique_left_match');
            $table->unique(['right_type', 'right_id', 'match_rule'], 'unique_right_match');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_matches');
    }
};
