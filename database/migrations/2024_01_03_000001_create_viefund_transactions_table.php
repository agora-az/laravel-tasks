<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('client_name')->nullable();
            $table->string('rep_code')->nullable();
            $table->string('plan_description')->nullable();
            $table->string('institution')->nullable();
            $table->string('account_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->decimal('available_cad', 15, 2)->default(0);
            $table->decimal('balance_cad', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('record_hash', 64)->unique()->comment('SHA256 hash for duplicate detection');
            $table->timestamps();
            
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_transactions');
    }
};
