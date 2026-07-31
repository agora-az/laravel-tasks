<?php

use App\Models\VieFundDailyTotal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_daily_totals', function (Blueprint $table) {
            // The snapshot is now multi-variant: one row per (date, date_basis, criteria).
            // variant_key is a deterministic hash of basis + status_ids + trust_status_names
            // (see VieFundDailyTotal::variantKey) so each synced combo is recalled independently.
            $table->string('date_basis')->default('settlement_date')->after('transaction_count');
            $table->string('variant_key', 40)->nullable()->after('date_basis');
        });

        // Backfill existing rows to the settlement-date variant, hashing their
        // already-stored criteria with the canonical helper.
        VieFundDailyTotal::query()->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $row->date_basis = 'settlement_date';
                $row->variant_key = VieFundDailyTotal::variantKey(
                    'settlement_date',
                    (array) $row->status_ids,
                    (array) $row->trust_status_names
                );
                $row->saveQuietly();
            }
        });

        Schema::table('viefund_daily_totals', function (Blueprint $table) {
            // One row per date collapses to one row per (variant, date). Leading
            // variant_key serves the page query (WHERE variant_key = ? AND date BETWEEN).
            $table->dropUnique('viefund_daily_totals_total_date_unique');
            $table->unique(['variant_key', 'total_date']);
        });
    }

    public function down(): void
    {
        Schema::table('viefund_daily_totals', function (Blueprint $table) {
            $table->dropUnique(['variant_key', 'total_date']);
            $table->dropColumn(['date_basis', 'variant_key']);
            $table->unique('total_date');
        });
    }
};
