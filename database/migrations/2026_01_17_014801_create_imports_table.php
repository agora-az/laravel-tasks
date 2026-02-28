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
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'viefund' or 'fundserv'
            $table->string('filename');
            $table->integer('file_size'); // in bytes
            $table->integer('total_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->integer('empty_row_count')->default(0);
            $table->text('error_details')->nullable();
            $table->string('status')->default('completed'); // completed, failed, processing
            $table->timestamp('import_started_at')->nullable();
            $table->timestamp('import_completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
