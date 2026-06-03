<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_viefund_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('viefund_customer_id')->unique()->comment('UB_Customer.ID from the remote VieFund database');
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('full_name', 512)->nullable()->comment('Stored computed: TRIM(first_name + " " + last_name)');
            $table->boolean('transactions_completed')->default(false)->comment('True once ALL transactions for this customer have been written and verified by the sync command');
            $table->timestamp('synced_at')->nullable()->comment('Last time this row was pulled from the remote DB');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_viefund_customers');
    }
};
