<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viefund_export_link_cache_keys', function (Blueprint $table) {
            $table->string('cache_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->timestamp('cached_at');

            $table->primary(['cache_type', 'entity_id'], 'viefund_export_link_cache_keys_primary');
            $table->index('cached_at');
        });

        Schema::create('viefund_export_cached_eft_items', function (Blueprint $table) {
            $table->unsignedBigInteger('remote_id')->primary();
            $table->unsignedBigInteger('linked_id')->index();
            $table->json('payload');
            $table->timestamp('cached_at')->index();
        });

        Schema::create('viefund_export_cached_fund_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_transaction_id');
            $table->string('source_id', 100);
            $table->timestamp('cached_at')->index();

            $table->primary(
                ['cash_transaction_id', 'source_id'],
                'viefund_export_cached_fund_sources_primary'
            );
            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viefund_export_cached_fund_sources');
        Schema::dropIfExists('viefund_export_cached_eft_items');
        Schema::dropIfExists('viefund_export_link_cache_keys');
    }
};
