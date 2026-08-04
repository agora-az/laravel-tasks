<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_cash_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 32);
            $table->string('status', 16)->default('running');
            $table->string('criteria_key', 40)->index();
            $table->string('algorithm_version', 32);
            $table->string('date_basis', 32);
            $table->string('currency_code', 8);
            $table->json('status_ids');
            $table->date('requested_from');
            $table->date('requested_to');
            $table->timestamp('source_observed_at');
            $table->unsignedInteger('days_checked')->default(0);
            $table->unsignedInteger('days_inserted')->default(0);
            $table->unsignedInteger('days_changed')->default(0);
            $table->unsignedInteger('days_unchanged')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('viefund_cash_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('total_date');
            $table->string('criteria_key', 40);
            $table->string('algorithm_version', 32);
            $table->string('date_basis', 32);
            $table->string('currency_code', 8);
            $table->json('status_ids');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('net_total', 20, 4)->default(0);
            $table->decimal('closing_balance', 20, 4)->default(0);
            $table->timestamp('first_observed_at');
            $table->timestamp('last_verified_at');
            $table->timestamp('latest_changed_at')->nullable();
            $table->unsignedInteger('change_count')->default(0);
            $table->boolean('has_unreviewed_change')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['criteria_key', 'total_date'], 'cash_snapshots_criteria_date_unique');
            $table->index(['criteria_key', 'has_unreviewed_change', 'total_date'], 'cash_snapshots_change_review_index');
        });

        Schema::create('viefund_cash_daily_snapshot_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('viefund_cash_daily_snapshots')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('viefund_cash_snapshot_runs')->cascadeOnDelete();
            $table->unsignedInteger('previous_transaction_count');
            $table->unsignedInteger('new_transaction_count');
            $table->integer('transaction_count_delta');
            $table->decimal('previous_net_total', 20, 4);
            $table->decimal('new_net_total', 20, 4);
            $table->decimal('net_total_delta', 20, 4);
            $table->string('algorithm_version', 32);
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['snapshot_id', 'detected_at'], 'cash_snapshot_changes_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_cash_daily_snapshot_changes');
        Schema::dropIfExists('viefund_cash_daily_snapshots');
        Schema::dropIfExists('viefund_cash_snapshot_runs');
    }
};