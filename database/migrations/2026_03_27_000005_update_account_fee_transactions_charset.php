<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the unique constraint on record_hash
        $this->dropUniqueConstraintIfExists('account_fee_transactions', 'record_hash');
        
        // 2. Convert table to UTF-8MB4
        DB::statement('ALTER TABLE account_fee_transactions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        // 3. Delete duplicate records (keep only the first occurrence of each hash)
        DB::statement(
            'DELETE FROM account_fee_transactions 
             WHERE id NOT IN (
                SELECT MIN(id) FROM (
                    SELECT MIN(id) as id FROM account_fee_transactions 
                    GROUP BY record_hash
                ) AS keep
             )'
        );
        
        // 4. Add the unique constraint back
        Schema::table('account_fee_transactions', function (Blueprint $table) {
            $table->unique('record_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop unique constraint and revert charset
        $this->dropUniqueConstraintIfExists('account_fee_transactions', 'record_hash');
        DB::statement('ALTER TABLE account_fee_transactions CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
    }

    /**
     * Helper method to drop unique constraint if it exists
     */
    private function dropUniqueConstraintIfExists(string $table, string $column): void
    {
        $indexName = $table . '_' . $column . '_unique';
        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        } catch (\Exception $e) {
            // Index might not exist, that's okay
        }
    }
};
