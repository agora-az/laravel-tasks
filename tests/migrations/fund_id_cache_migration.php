<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = new Manager();
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$database->setAsGlobal();
Schema::swap($database->schema());

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_23_000001_add_fund_id_to_viefund_transaction_cache_rows.php';
$tables = [
    'viefund_transaction_mirror_rows' => [
        'index' => 'viefund_mirror_fund_id_idx',
        'columns' => ['fund_id'],
    ],
    'viefund_transaction_working_set_rows' => [
        'index' => 'viefund_working_set_fund_idx',
        'columns' => ['working_set_id', 'generation', 'fund_id'],
    ],
];

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach (range(0, 2) as $mirrorState) {
    foreach (range(0, 2) as $workingSetState) {
        foreach ($tables as $tableName => $definition) {
            $state = $tableName === 'viefund_transaction_mirror_rows' ? $mirrorState : $workingSetState;
            Schema::dropIfExists($tableName);
            Schema::create($tableName, function (Blueprint $table) use ($state, $definition): void {
                $table->unsignedBigInteger('cash_transaction_id')->primary();
                $table->unsignedBigInteger('working_set_id');
                $table->string('generation');
                $table->string('source_id', 100)->nullable();

                if ($state >= 1) {
                    $table->string('fund_id', 100)->nullable();
                }
                if ($state === 2) {
                    $table->index($definition['columns'], $definition['index']);
                }
            });

            $row = [
                'cash_transaction_id' => 1,
                'working_set_id' => 1,
                'generation' => 'test-generation',
                'source_id' => 'existing-source',
            ];
            if ($state >= 1) {
                $row['fund_id'] = 'CASHagch';
            }
            $database->getConnection()->table($tableName)->insert($row);
        }

        $migration->up();
        $migration->up();

        foreach ($tables as $tableName => $definition) {
            $state = $tableName === 'viefund_transaction_mirror_rows' ? $mirrorState : $workingSetState;
            $check(Schema::hasColumn($tableName, 'fund_id'), "$tableName: missing fund_id");
            $check(Schema::hasIndex($tableName, $definition['index']), "$tableName: missing index");
            $index = array_values(array_filter(
                Schema::getIndexes($tableName),
                static fn (array $index): bool => $index['name'] === $definition['index']
            ))[0];
            $check($index['columns'] === $definition['columns'], "$tableName: incorrect index columns");

            $query = $database->getConnection()->table($tableName);
            $check($query->count() === 1, "$tableName: existing row lost");
            $row = $query->first();
            $check($row->source_id === 'existing-source', "$tableName: source changed");
            $check($row->fund_id === ($state >= 1 ? 'CASHagch' : null), "$tableName: fund changed");
        }

        $migration->down();
        foreach ($tables as $tableName => $definition) {
            $check(! Schema::hasColumn($tableName, 'fund_id'), "$tableName: rollback left column");
            $check(! Schema::hasIndex($tableName, $definition['index']), "$tableName: rollback left index");
            $check($database->getConnection()->table($tableName)->count() === 1, "$tableName: rollback lost row");
        }

        $migration->up();
        foreach ($tables as $tableName => $definition) {
            $check(Schema::hasColumn($tableName, 'fund_id'), "$tableName: reapply missing column");
            $check(Schema::hasIndex($tableName, $definition['index']), "$tableName: reapply missing index");
        }

        echo "PASS: mirror state $mirrorState, working-set state $workingSetState\n";
    }
}
