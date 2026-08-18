<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_instruction_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('cascade');
            $table->string('source_file')->index();
            $table->string('source_type', 32)->index();

            $table->unsignedInteger('record_count')->default(0);
            $table->decimal('total_gross_amount', 18, 2)->nullable();
            $table->decimal('total_net_amount', 18, 2)->nullable();
            $table->decimal('total_settlement_amount', 18, 2)->nullable();

            $table->date('min_create_date')->nullable();
            $table->date('max_create_date')->nullable();
            $table->date('min_trade_date')->nullable();
            $table->date('max_trade_date')->nullable();
            $table->date('min_settlement_date')->nullable();
            $table->date('max_settlement_date')->nullable();

            $table->json('raw_summary_json')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'source_file'], 'settl_instr_summaries_import_file_unique');
            $table->index(['source_type', 'max_settlement_date'], 'settl_instr_sum_src_settl_idx');
        });

        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->unsignedBigInteger('settlement_instruction_summary_id')->nullable()->after('import_id');
            $table->foreign('settlement_instruction_summary_id', 'settl_instr_summary_fk')
                ->references('id')
                ->on('settlement_instruction_summaries')
                ->nullOnDelete();
        });

        $groups = DB::table('settlement_instructions')
            ->selectRaw(
                'import_id,
                 source_file,
                 source_type,
                 count(*) as record_count,
                 sum(gross_amount) as total_gross_amount,
                 sum(net_amount) as total_net_amount,
                 sum(settlement_amount) as total_settlement_amount,
                 min(create_date) as min_create_date,
                 max(create_date) as max_create_date,
                 min(trade_date) as min_trade_date,
                 max(trade_date) as max_trade_date,
                 min(settlement_date) as min_settlement_date,
                 max(settlement_date) as max_settlement_date'
            )
            ->groupBy('import_id', 'source_file', 'source_type')
            ->get();

        foreach ($groups as $group) {
            $summaryId = DB::table('settlement_instruction_summaries')->insertGetId([
                'import_id' => $group->import_id,
                'source_file' => $group->source_file,
                'source_type' => $group->source_type,
                'record_count' => (int) ($group->record_count ?? 0),
                'total_gross_amount' => $group->total_gross_amount,
                'total_net_amount' => $group->total_net_amount,
                'total_settlement_amount' => $group->total_settlement_amount,
                'min_create_date' => $group->min_create_date,
                'max_create_date' => $group->max_create_date,
                'min_trade_date' => $group->min_trade_date,
                'max_trade_date' => $group->max_trade_date,
                'min_settlement_date' => $group->min_settlement_date,
                'max_settlement_date' => $group->max_settlement_date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updateQuery = DB::table('settlement_instructions')
                ->where('source_file', $group->source_file)
                ->where('source_type', $group->source_type);

            if ($group->import_id === null) {
                $updateQuery->whereNull('import_id');
            } else {
                $updateQuery->where('import_id', $group->import_id);
            }

            $updateQuery->update([
                'settlement_instruction_summary_id' => $summaryId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->dropForeign('settl_instr_summary_fk');
            $table->dropColumn('settlement_instruction_summary_id');
        });

        Schema::dropIfExists('settlement_instruction_summaries');
    }
};
