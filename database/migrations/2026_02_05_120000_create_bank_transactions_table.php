<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('account_number')->nullable()->index();
            $table->string('currency', 10)->nullable();
            $table->date('txn_date')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('balance', 15, 2)->nullable();
            $table->string('record_hash', 64)->unique()->comment('SHA256 hash for duplicate detection');
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
