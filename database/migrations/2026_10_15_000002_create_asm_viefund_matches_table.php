<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asm_viefund_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asm_settlement_transaction_id')
                ->constrained('asm_settlement_transactions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('viefund_plan_id')->nullable()->index();
            $table->unsignedBigInteger('viefund_cash_transaction_id')->nullable()->index();
            $table->string('dealer_account_id', 100)->nullable()->index();
            $table->string('match_status', 40)->index();
            $table->string('match_rule', 100);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['asm_settlement_transaction_id', 'match_status'],
                'asm_viefund_transaction_status_idx'
            );
        });

        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table) {
            $table->boolean('has_asm_match')
                ->default(false)
                ->after('has_7960_fsp_match');
        });
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table) {
            $table->dropColumn('has_asm_match');
        });

        Schema::dropIfExists('asm_viefund_matches');
    }
};
