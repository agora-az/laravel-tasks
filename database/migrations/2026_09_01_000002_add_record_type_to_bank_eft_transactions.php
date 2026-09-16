<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_eft_transactions', function (Blueprint $table) {
            $table->char('record_type', 1)->nullable()->after('segment_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bank_eft_transactions', function (Blueprint $table) {
            $table->dropIndex(['record_type']);
            $table->dropColumn('record_type');
        });
    }
};
