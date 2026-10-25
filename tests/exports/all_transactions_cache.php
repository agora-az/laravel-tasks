<?php

use App\Http\Controllers\RemoteVieFundController;
use App\Jobs\HydrateVieFundTransactionWorkingSet;
use App\Models\VieFundTransactionWorkingSet;
use App\Services\VieFund\VieFundTransactionWorkingSetManager;
use App\Services\VieFund\VieFundTransactionWorkingSetQuery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$testDirectory = sys_get_temp_dir().'/agora-period-cache-test-'.bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$testDirectory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($testDirectory);
$app->useStoragePath($testDirectory);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$exitCode = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
        'cache.default' => 'array',
        'view.compiled' => $testDirectory,
    ]);
    $compiledView = $testDirectory.'/compiled-transactions.php';
    file_put_contents($compiledView, $app->make('blade.compiler')->compileString(
        file_get_contents(dirname(__DIR__, 2).'/resources/views/viefund-transactions/index.blade.php')
    ));
    (new Process([PHP_BINARY, '-l', $compiledView]))->mustRun();
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    foreach ([
        '2026_10_06_000001_create_viefund_transaction_working_sets',
        '2026_10_07_000002_add_balance_report_to_viefund_transaction_working_sets',
        '2026_10_08_000001_add_hydration_control_to_viefund_transaction_working_sets',
    ] as $name) {
        (require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php')->up();
    }
    Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
        $table->string('fund_id')->nullable();
        $table->text('enrichment')->default('{}');
    });

    Bus::fake([HydrateVieFundTransactionWorkingSet::class]);
    $manager = $app->make(VieFundTransactionWorkingSetManager::class);
    $query = $app->make(VieFundTransactionWorkingSetQuery::class);
    $filters = [
        'date_basis' => 'trade_date',
        'date_from' => '2026-01-01',
        'date_to' => '2026-07-31',
        'currency_code' => '00',
        'status_ids' => [6],
    ];
    $workingSet = $manager->ensure($filters);
    $generation = $workingSet->build_generation;
    $fixture = [];
    for ($id = 1; $id <= 120; ++$id) {
        $fixture[] = [
            'working_set_id' => $workingSet->id,
            'generation' => $generation,
            'cash_transaction_id' => $id,
            'transaction_id' => 'C-'.$id,
            'created_date' => sprintf('2026-01-%02d 12:00:00', 28 - $id % 28),
            'trade_date' => sprintf('2026-01-%02d 12:00:00', 1 + $id % 9),
            'processing_date' => sprintf('2026-01-%02d 12:00:00', 1 + $id % 15),
            'settlement_date' => sprintf('2026-01-%02d 12:00:00', 10 + $id % 10),
            'basis_date' => sprintf('2026-01-%02d 12:00:00', 1 + $id % 9),
            'amount' => $id % 2 === 0 ? -$id : $id,
            'fund_id' => $id % 2 === 0 ? 'CASHagch' : 'OTHER',
            'match_status' => 'Unknown',
            'payload' => json_encode(['cash_transaction_id' => $id, 'transaction_id' => 'C-'.$id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('viefund_transaction_working_set_rows')->insert($fixture);
    DB::table('viefund_transaction_working_set_rows')->insert([
        ...$fixture[0], 'generation' => 'different-generation', 'cash_transaction_id' => 9999,
    ]);
    $workingSet->forceFill([
        'state' => 'failed',
        'rows_cached' => 120,
        'total_rows' => 125,
        'hydration_cursor' => ['basis_date' => '2026-01-09 12:00:00', 'sort_id' => 120],
        'last_error' => 'Simulated timeout.',
    ])->save();

    foreach (['create_date' => 'created_date', 'trade_date' => 'trade_date',
        'processing_date' => 'processing_date', 'settlement_date' => 'settlement_date'] as $basis => $column) {
        $workingSet->date_basis = $basis;
        DB::table('viefund_transaction_working_set_rows')->update(['basis_date' => DB::raw($column)]);
        foreach (['created_date', 'trade_date', 'processing_date', 'settlement_date', 'amount'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $fixture;
                usort($expected, static function (array $left, array $right) use ($sort, $direction): int {
                    $comparison = ($left[$sort] <=> $right[$sort])
                        ?: ($left['cash_transaction_id'] <=> $right['cash_transaction_id']);
                    return $direction === 'asc' ? $comparison : -$comparison;
                });
                for ($page = 1; $page <= 3; ++$page) {
                    DB::enableQueryLog();
                    DB::flushQueryLog();
                    $result = $query->paginate($workingSet, 50, $page, null, [
                        ...$filters, 'sort' => $sort, 'sort_dir' => $direction,
                    ]);
                    $select = collect(DB::getQueryLog())->last();
                    $check(count(DB::getQueryLog()) === 1, 'Interactive cached pages must not execute a full-result COUNT.');
                    DB::disableQueryLog();
                    $check($result->hasMorePages() === ($page < 3), 'Previous/next navigation must match the available cached rows.');
                    $check(array_column($result->items(), 'cash_transaction_id')
                        === array_column(array_slice($expected, ($page - 1) * 50, 50), 'cash_transaction_id'),
                        "$basis / $sort / $direction: date, amount or tie ordering changed.");
                    $expectedColumn = $sort === $column ? 'basis_date' : $sort;
                    $check(str_contains($select['query'], 'order by "'.$expectedColumn.'"'),
                        "$basis / $sort must select the appropriate pagination column.");
                    if ($sort === $column) {
                        $plan = DB::select('EXPLAIN QUERY PLAN '.$select['query'], $select['bindings']);
                        $details = implode(' ', array_column($plan, 'detail'));
                        $check(str_contains($details, 'viefund_working_set_page_idx')
                            && !str_contains($details, 'TEMP B-TREE'),
                            "$basis / $direction must use the existing date index without a full sort.");
                    }
                }
            }
        }
    }
    $workingSet = $workingSet->fresh();
    DB::table('viefund_transaction_working_set_rows')->update(['basis_date' => DB::raw('trade_date')]);
    $fundPage = $query->paginate($workingSet, 50, 1, null, [...$filters, 'sort' => 'trade_date', 'fund_id' => 'CASHagch']);
    $check($query->count($workingSet, null, [...$filters, 'fund_id' => 'CASHagch']) === 60
        && collect($fundPage->items())->every(fn($row) => $row->cash_transaction_id % 2 === 0),
        'Indexed pagination must preserve fund filtering on partial failed caches.');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $check($query->count($workingSet, null, $filters) === 120 && DB::getQueryLog() === [],
        'Unfiltered totals must use the saved count rather than scan the cached rows.');
    DB::disableQueryLog();
    $check($query->count($workingSet, null, [...$filters, 'fund_id' => '0']) === 0,
        'A literal zero filter must not be mistaken for an unfiltered count.');
    $refreshing = new VieFundTransactionWorkingSet([
        ...$workingSet->getAttributes(), 'active_generation' => $generation, 'build_generation' => 'new-generation',
        'state' => 'refreshing', 'rows_cached' => 5,
    ]);
    $check($query->count($refreshing, null, $filters) === 120,
        'Refreshing caches must not report the new build count as the old readable generation total.');
    echo "PASS: four date bases, both directions, alternate sorts, page ties, filters and partial generations\n";

    $controller = $app->make(RemoteVieFundController::class);
    $request = Request::create('/transactions', 'GET', [
        'filter_date_basis' => 'trade_date', 'filter_date_from' => '2026-01-01',
        'filter_date_to' => '2026-07-31', 'filter_currency_code' => '00', 'filter_status' => [6],
    ]);
    foreach (['failed', 'paused', 'warming'] as $state) {
        foreach ([0, 120] as $rowsCached) {
            $workingSet->forceFill(['state' => $state, 'rows_cached' => $rowsCached])->save();
            foreach (['allTransactionsSummary', 'allTransactionsMatchSummary'] as $action) {
                $response = $controller->$action($request);
                $data = $response->getData(true);
                $check($response->getStatusCode() === ($state === 'warming' ? 202 : 200)
                    && $data['state'] === ($state === 'warming' ? 'processing' : $state),
                    "$action must distinguish $state caches even when zero rows have been written.");
                if ($state !== 'warming') {
                    $check(str_contains($data['message'], $state === 'failed' ? 'Retry' : 'Resume'),
                        'Stopped summaries must explain how to continue.');
                }
            }
        }
    }
    $workingSet->forceFill(['state' => 'failed', 'rows_cached' => 120])->save();
    $count = $controller->allTransactionsCountStatus($request)->getData(true);
    $check($count['state'] === 'partial' && $count['cache_state'] === 'failed' && $count['total'] === 120,
        'Partial totals must expose the stopped cache state without claiming completion.');
    Bus::fake([HydrateVieFundTransactionWorkingSet::class]);
    $manager->ensure($filters);
    $check(Bus::dispatched(HydrateVieFundTransactionWorkingSet::class)->isEmpty(),
        'Opening a failed cache must not silently restart it.');
    $resumed = $manager->resume($workingSet);
    $check($resumed->build_generation === $generation && $resumed->rows_cached === 120
        && $resumed->hydration_cursor === $workingSet->hydration_cursor, 'Retry must preserve generation, cursor and cached rows.');
    $check(Bus::dispatched(HydrateVieFundTransactionWorkingSet::class)->contains(
        fn($job) => $job->generation === $generation && $job->cursor === $workingSet->hydration_cursor
            && $job->connection === 'database_viefund_cache'), 'Retry must queue the saved cursor on the cache connection.');
    $check($controller->allTransactionsSummary($request)->getStatusCode() === 202,
        'Resuming must restart pending summary responses.');
    $resumed->forceFill([
        'state' => 'ready', 'active_generation' => $generation, 'build_generation' => null,
        'balance_report' => [
            'opening_balance' => 10, 'final_balance' => 15, 'balance_source' => 'Test cache',
            'uses_snapshots' => false, 'rows' => [['daily_net_transactions' => 5, 'transaction_count' => 120]],
        ],
    ])->save();
    $summary = $controller->allTransactionsSummary($request)->getData(true);
    $check($summary['period_net'] === 5 && $summary['transaction_count'] === 120,
        'Completed summaries must retain their existing financial response.');
    $check($controller->allTransactionsMatchSummary($request)->getData(true)['state'] === 'complete',
        'Match summaries must recover after completion.');
    echo "PASS: failed/paused/zero-row summaries, partial totals, saved-cursor retry and completed summary recovery\n";

    DB::table('viefund_transaction_working_set_rows')->where('working_set_id', $workingSet->id)->delete();
    for ($start = 1; $start <= 100001; $start += 500) {
        $rows = [];
        for ($id = $start; $id <= min(100001, $start + 499); ++$id) {
            $rows[] = [...$fixture[$id % 120], 'cash_transaction_id' => $id,
                'payload' => json_encode(['cash_transaction_id' => $id], JSON_THROW_ON_ERROR)];
        }
        DB::table('viefund_transaction_working_set_rows')->insert($rows);
    }
    $resumed->rows_cached = 100001;
    $started = microtime(true);
    $page = $query->paginate($resumed, 100, 1, null, [...$filters, 'sort' => 'trade_date', 'sort_dir' => 'desc']);
    $total = $query->count($resumed, null, $filters);
    $seconds = microtime(true) - $started;
    $check($total === 100001 && count($page->items()) === 100 && $page->hasMorePages() && $seconds < 2,
        'The 100,001-row cached first page, including its count, must complete within two seconds.');
    printf("PASS: 100,001-row indexed page plus total in %.3fs (local SQLite, not a production timing)\n", $seconds);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);
    $exitCode = 1;
} finally {
    $app->make('files')->deleteDirectory($testDirectory);
}
exit($exitCode);
