<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // e.g., 'fund_wo_order_id', 'settlement_date', etc.
            $table->string('description', 255);
            $table->decimal('weight', 8, 4)->default(1); // Weight for confidence calculation
            $table->integer('priority')->default(0); // Order of evaluation
            $table->timestamps();

            $table->index('code');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_criteria');
    }
};
