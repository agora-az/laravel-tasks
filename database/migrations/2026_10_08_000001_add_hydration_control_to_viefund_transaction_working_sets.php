<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viefund_transaction_working_sets', function (Blueprint $table) {
            $table->json('hydration_cursor')->nullable()->after('build_generation');
            $table->timestamp('paused_at')->nullable()->after('started_at');
        });

        DB::table('viefund_transaction_working_sets')
            ->whereIn('state', ['warming', 'refreshing'])
            ->update([
                'state' => 'failed',
                'build_generation' => null,
                'last_error' => 'Superseded by working-set cache version 3.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('viefund_transaction_working_sets', function (Blueprint $table) {
            $table->dropColumn(['hydration_cursor', 'paused_at']);
        });
    }
};
