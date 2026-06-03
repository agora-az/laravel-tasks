<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_viefund_customer_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('viefund_customer_id')->comment('UB_Customer.ID — matches remote_viefund_customers.viefund_customer_id');
            $table->unsignedBigInteger('viefund_plan_id')->unique()->comment('UB_Plan.ID from the remote VieFund database');
            $table->string('plan_dealer_account_id', 100)->nullable()->comment('UB_Plan.DealerAccountID');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('viefund_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_viefund_customer_plans');
    }
};
