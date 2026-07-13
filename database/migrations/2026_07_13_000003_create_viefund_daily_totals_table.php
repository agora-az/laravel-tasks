<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('total_date')->unique()->index();
            $table->decimal('net_total', 18, 4)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->date('source_window_start')->nullable();
            $table->date('source_window_end')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_daily_totals');
    }
};
