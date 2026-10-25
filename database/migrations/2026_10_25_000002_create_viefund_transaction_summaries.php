<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('viefund_transaction_summaries')) {
            Schema::create('viefund_transaction_summaries', function (Blueprint $table): void {
                $table->id();
                $table->char('signature', 64)->unique();
                $table->foreignId('working_set_id')->nullable()
                    ->constrained('viefund_transaction_working_sets')->cascadeOnDelete();
                $table->json('source_snapshot');
                $table->json('filters');
                $table->text('search')->nullable();
                $table->string('state', 20)->default('queued');
                $table->uuid('dispatch_token');
                $table->unsignedBigInteger('cursor')->default(0);
                $table->unsignedBigInteger('processed_rows')->default(0);
                $table->unsignedBigInteger('expected_rows')->nullable();
                $table->json('totals');
                $table->json('result')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->index(['state', 'updated_at']);
            });
        }
        if (!Schema::hasTable('viefund_transaction_summary_records')) {
            Schema::create('viefund_transaction_summary_records', function (Blueprint $table): void {
                $table->foreignId('summary_id')->constrained('viefund_transaction_summaries')->cascadeOnDelete();
                $table->char('record_hash', 64);
                $table->primary(['summary_id', 'record_hash'], 'viefund_summary_records_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_transaction_summary_records');
        Schema::dropIfExists('viefund_transaction_summaries');
    }
};
