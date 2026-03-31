<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('advisory_fee_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('advisory_fee_transactions', 'import_id')) {
                $table->unsignedBigInteger('import_id')->nullable()->after('last_modified_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advisory_fee_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('advisory_fee_transactions', 'import_id')) {
                $table->dropColumn('import_id');
            }
        });
    }
};
