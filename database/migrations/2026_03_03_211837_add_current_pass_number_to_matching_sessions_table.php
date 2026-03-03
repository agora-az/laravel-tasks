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
        Schema::table('matching_sessions', function (Blueprint $table) {
            $table->integer('current_pass_number')->default(0)->nullable();
            $table->integer('total_records_in_pass')->default(0)->nullable();
            $table->decimal('progress_percentage', 5, 1)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matching_sessions', function (Blueprint $table) {
            $table->dropColumn(['current_pass_number', 'total_records_in_pass', 'progress_percentage']);
        });
    }
};
