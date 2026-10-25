<?php

use App\Jobs\BuildVieFundTransactionSummary;
use App\Http\Controllers\RemoteVieFundController;
use App\Models\VieFundTransactionMirrorSync;
use App\Models\VieFundTransactionSummary;
use App\Models\VieFundTransactionWorkingSet;
use App\Services\VieFund\VieFundMatchStatusSummaryAccumulator;
use App\Services\VieFund\VieFundTransactionSummaryService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-summary-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$directory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (Closure $operation, string $message): Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
};
$exitCode = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]],
        'cache.default' => 'array', 'view.compiled' => $directory,
    ]);
    Bus::fake([BuildVieFundTransactionSummary::class]);
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    foreach ([
        '2026_10_06_000001_create_viefund_transaction_working_sets',
        '2026_10_07_000002_add_balance_report_to_viefund_transaction_working_sets',
        '2026_10_08_000001_add_hydration_control_to_viefund_transaction_working_sets',
        '2026_10_15_193500_create_viefund_transaction_mirror_tables',
        '2026_10_25_000002_create_viefund_transaction_summaries',
    ] as $name) {
        (require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php')->up();
    }
    (require dirname(__DIR__, 2).'/database/migrations/2026_10_25_000002_create_viefund_transaction_summaries.php')->up();
    Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
        $table->string('fund_id')->nullable();
        $table->text('enrichment')->default('{}');
        $table->boolean('has_asm_match')->default(false);
    });
    $filters = [
        'date_basis' => 'trade_date', 'date_from' => '2026-01-01', 'date_to' => '2026-07-31',
        'currency_code' => '00', 'status_ids' => [6, 9],
    ];
    $workingSet = VieFundTransactionWorkingSet::query()->create([
        'scope_hash' => str_repeat('a', 40), ...$filters, 'state' => 'ready',
        'active_generation' => '11111111-1111-4111-8111-111111111111',
        'rows_cached' => 2051, 'total_rows' => 2051,
    ]);
    $bank = ['id' => 5, 'amount' => 12.345, 'credit_debit_indicator' => 'DBIT', 'fsp_net_total' => -2.015];
    $eft = ['id' => 11, 'file_id' => 600, 'file_total' => 10.006, 'type_id' => 10];
    $linked = [
        'asm_items' => [['id' => 99, 'settlement_amount' => 5.125]],
        'eft_items' => [$eft, ['id' => 13, 'amount' => 3.006, 'type_id' => 20]],
        'fsp_items' => [['id' => 21]],
        'eft_bank_entries' => [$bank, ['id' => 6, 'amount' => 2.345, 'credit_debit_indicator' => 'CRDT']],
        'fsp_bank_entries' => [$bank],
    ];
    $expected = new VieFundMatchStatusSummaryAccumulator();
    $fixture = [];
    for ($id = 1; $id <= 2051; ++$id) {
        $enrichment = in_array($id, [1, 1001, 1002, 2001], true) ? $linked : [];
        if ($id === 1001) $enrichment['eft_items'][] = [...$eft, 'id' => 12];
        $enrichment['asm_items'][] = ['id' => 10000 + $id, 'settlement_amount' => 0.0049];
        $row = [
            'working_set_id' => $workingSet->id, 'generation' => $workingSet->active_generation,
            'cash_transaction_id' => $id, 'transaction_id' => 'C-'.$id,
            'basis_date' => '2026-01-02 00:00:00', 'trade_date' => '2026-01-02 00:00:00',
            'amount' => $id % 2 ? 0.0049 : -0.0031,
            'fund_id' => $id === 1 ? '0' : 'X',
            'match_status' => $id === 1002 ? 'Unknown' : ($id === 2001 ? 'Verify' : 'Complete'),
            'enrichment' => json_encode($enrichment, JSON_THROW_ON_ERROR), 'payload' => '{}',
        ];
        $fixture[] = $row;
        $expected->add((object) $row);
    }
    foreach (array_chunk($fixture, 100) as $chunk) DB::table('viefund_transaction_working_set_rows')->insert($chunk);
    $service = $app->make(VieFundTransactionSummaryService::class);
    DB::enableQueryLog();
    $run = $service->forWorkingSet($workingSet, null, $filters);
    $check(!collect(DB::getQueryLog())->contains(fn($query) => str_contains($query['query'], 'working_set_rows')),
        'Creating a summary must not scan source rows.');
    DB::disableQueryLog();
    $same = $service->forWorkingSet($workingSet, '', [
        ...array_reverse($filters, true), 'status_ids' => [9, 6, 6], 'match_statuses' => ['Complete'],
        'page' => 3, 'per_page' => 50, 'sort' => 'amount', 'sort_dir' => 'asc',
    ]);
    $check($same->id === $run->id && Bus::dispatched(BuildVieFundTransactionSummary::class)->count() === 1,
        'Canonical equivalent filters and match-status selection must reuse one queued summary.');
    $check(Bus::dispatched(BuildVieFundTransactionSummary::class)->first()->connection === 'database_viefund_cache',
        'Summaries must use the existing production cache worker.');
    $check($run->expected_rows === 2051, 'Unfiltered summaries should expose the saved transaction count.');
    $check($service->advance($run->id, $run->dispatch_token, 0) === 1000, 'A stage must stop after 1,000 rows.');
    $first = $run->fresh();
    $check($first->processed_rows === 1000 && $first->result === null, 'Intermediate totals must not be published.');
    $records = DB::table('viefund_transaction_summary_records')->where('summary_id', $run->id)->count();
    $check($service->advance($run->id, $run->dispatch_token, 0) === 1000
        && $run->fresh()->processed_rows === 1000, 'Duplicate stages must return the committed cursor without recounting.');
    (new BuildVieFundTransactionSummary($run->id, $run->dispatch_token))->handle($service);
    $check(Bus::dispatched(BuildVieFundTransactionSummary::class)->last()->cursor === 1000,
        'Replayed committed stages must repair a lost continuation.');

    $shuffledTotals = array_reverse($first->totals, true);
    foreach ($shuffledTotals as &$values) $values = array_reverse($values, true);
    unset($values);
    DB::table('viefund_transaction_summaries')->where('id', $run->id)
        ->update(['totals' => json_encode($shuffledTotals, JSON_THROW_ON_ERROR)]);
    DB::table('viefund_transaction_working_set_rows')->where('cash_transaction_id', 1001)->update(['enrichment' => '{']);
    $exception = $throws(fn() => $service->advance($run->id, $run->dispatch_token, 1000),
        'Malformed cached enrichment must fail explicitly.');
    $check($run->fresh()->processed_rows === 1000
        && DB::table('viefund_transaction_summary_records')->where('summary_id', $run->id)->count() === $records,
        'Failed batches must roll back totals, claims and cursor together.');
    $failedJob = new BuildVieFundTransactionSummary($run->id, $run->dispatch_token, 1000);
    $failedJob->failed($exception);
    $check($service->status($run->fresh())['retryable'], 'Failed runs must expose an explicit retry.');
    DB::table('viefund_transaction_working_set_rows')->where('cash_transaction_id', 1001)
        ->update(['enrichment' => $fixture[1000]['enrichment']]);
    $retry = $service->forWorkingSet($workingSet, null, $filters, true);
    $check($retry->dispatch_token !== $run->dispatch_token && $retry->cursor === 1000, 'Retry must preserve saved progress.');
    $failedJob->failed(new RuntimeException('Stale failure.'));
    $check($retry->fresh()->state === 'queued'
        && $service->advance($retry->id, $run->dispatch_token, 1000) === null,
        'Old jobs and failure callbacks must not change a retried run.');
    $check($service->advance($retry->id, $retry->dispatch_token, 1000) === 2000, 'Retry must continue the next batch.');
    $check($service->advance($retry->id, $retry->dispatch_token, 2000) === null, 'Final stage must complete.');
    $result = $retry->fresh()->result;
    $check($result == $expected->result(), 'Background totals must match the original whole-source financial semantics.');
    $check($result['Complete']['eft_count'] === 3 && $result['Complete']['eft_total'] == 7
        && $result['Complete']['bank_count'] === 2 && $result['Complete']['fsp_count'] === 1,
        'Files, items and shared EFT/FSP bank records must remain deduplicated across stages.');
    $check($result['Unknown']['asm_count'] === 2 && $result['Unknown']['eft_count'] === 0
        && $result['Unknown']['bank_count'] === 0 && $result['Verify']['eft_count'] === 2,
        'Unknown ASM handling and per-status deduplication must be preserved.');
    $check($result['Complete']['asm_total'] == 15.17, 'Fractional amounts must be rounded once, after the final batch.');
    Carbon::setTestNow(now()->addMinutes(15));
    $dispatchCount = Bus::dispatched(BuildVieFundTransactionSummary::class)->count();
    $check($service->forWorkingSet($workingSet, null, $filters)->id === $run->id
        && Bus::dispatched(BuildVieFundTransactionSummary::class)->count() === $dispatchCount,
        'Completed persisted summaries must survive the former five-minute expiry without another job.');
    $zero = $service->forWorkingSet($workingSet, null, [...$filters, 'fund_id' => '0']);
    $check($zero->id !== $run->id && $zero->expected_rows === null, 'Literal zero filters must not be omitted.');
    $service->advance($zero->id, $zero->dispatch_token, 0);
    $check($zero->fresh()->processed_rows === 1, 'Literal zero must filter actual summary rows.');
    echo "PASS: queued-once requests, global cross-batch deduplication, final-only rounding, atomic retry and durable reuse\n";

    $stalled = $service->forWorkingSet($workingSet, null, [...$filters, 'fund_id' => 'X']);
    $service->advance($stalled->id, $stalled->dispatch_token, 0);
    $stalled->refresh()->forceFill(['updated_at' => now()->subMinutes(11)])->save();
    $check($service->status($stalled)['state'] === 'failed', 'Lost continuations must not poll forever.');
    $recovered = $service->forWorkingSet($workingSet, null, [...$filters, 'fund_id' => 'X'], true);
    $check($recovered->cursor === 1001 && $recovered->dispatch_token !== $stalled->dispatch_token,
        'Stalled retry must retain its actual cash-ID cursor.');
    $workingSet->forceFill(['active_generation' => '22222222-2222-4222-8222-222222222222'])->save();
    $throws(fn() => $service->advance($recovered->id, $recovered->dispatch_token, $recovered->cursor),
        'A changed cache generation must invalidate unfinished work.');
    $freshRun = $service->forWorkingSet($workingSet, null, $filters);
    $check($freshRun->id !== $run->id, 'Replacement generations must never return old financial totals.');
    $service->advance($freshRun->id, $freshRun->dispatch_token, 0);
    $check($freshRun->fresh()->state === 'complete' && $freshRun->fresh()->processed_rows === 0,
        'Empty source generations must complete with genuine zero totals.');
    echo "PASS: stalled-stage recovery, source-generation invalidation and empty summaries\n";

    $sync = VieFundTransactionMirrorSync::query()->create([
        'id' => 1, 'state' => 'ready', 'sync_generation' => '33333333-3333-4333-8333-333333333333',
        'ready_at' => now(), 'verified_at' => now(), 'synced_through_at' => now(),
    ]);
    $mirrorExpected = new VieFundMatchStatusSummaryAccumulator();
    foreach ([$fixture[0], $fixture[1000], $fixture[2000]] as $row) {
        $mirrorExpected->add((object) $row);
        DB::table('viefund_transaction_mirror_rows')->insert([
            'cash_transaction_id' => $row['cash_transaction_id'], 'transaction_id' => $row['transaction_id'],
            'amount' => $row['amount'], 'match_status' => $row['match_status'], 'enrichment' => $row['enrichment'],
            'payload' => '{}', 'trade_date' => $row['trade_date'], 'currency_code' => '00', 'cash_status_id' => 6,
            'sync_generation' => $sync->sync_generation, 'source_fingerprint' => str_repeat('b', 64),
            'source_synced_at' => now(),
        ]);
    }
    $mirrorFilters = [...$filters, 'status_ids' => [6]];
    config(['viefund.mirror.data_source' => 'local']);
    $controller = $app->make(RemoteVieFundController::class);
    $requestFilters = [
        'filter_date_basis' => 'trade_date', 'filter_date_from' => '2026-01-01',
        'filter_date_to' => '2026-07-31', 'filter_currency_code' => '00', 'filter_status' => [6],
    ];
    $request = Request::create('/viefund-transactions/match-summary', 'GET', $requestFilters);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $controller->allTransactionsMatchSummary($request);
    $check($response->getStatusCode() === 202
        && !collect(DB::getQueryLog())->contains(fn($query) => str_contains($query['query'], 'from "viefund_transaction_mirror_rows"')),
        'The mirror endpoint must enqueue a summary without scanning mirror rows.');
    DB::disableQueryLog();
    $mirrorRun = $service->forMirror($sync, null, $mirrorFilters);
    $mirrorRun->forceFill(['source_snapshot' => array_reverse($mirrorRun->source_snapshot, true)])->save();
    $service->advance($mirrorRun->id, $mirrorRun->dispatch_token, 0);
    $check($mirrorRun->fresh()->result == $mirrorExpected->result(), 'Mirror summaries must preserve the same financial rules.');
    $response = $controller->allTransactionsMatchSummary(Request::create(
        '/viefund-transactions/match-summary', 'GET', [...$requestFilters, 'filter_match_status' => ['Complete']]
    ));
    $data = $response->getData(true);
    $check($data['state'] === 'complete' && $data['source'] === 'mirror'
        && count($data['statuses']) === 5 && $data['totals']['transaction_count'] === 2,
        'The endpoint must preserve all status buckets while applying selected statuses to filtered cash totals.');
    $changing = $service->forMirror($sync, 'C-', $mirrorFilters);
    (new BuildVieFundTransactionSummary($changing->id, $changing->dispatch_token))->failed(new RuntimeException('Simulated failure.'));
    $failureRequest = [...$requestFilters, 'search' => 'C-'];
    $failedResponse = $controller->allTransactionsMatchSummary(Request::create('/viefund-transactions/match-summary', 'GET', $failureRequest));
    $check($failedResponse->getData(true)['state'] === 'failed' && $failedResponse->getData(true)['retryable'],
        'The endpoint must expose durable summary failures.');
    $retriedResponse = $controller->allTransactionsMatchSummary(Request::create(
        '/viefund-transactions/match-summary', 'GET', [...$failureRequest, 'retry_summary' => 1]
    ));
    $check($retriedResponse->getStatusCode() === 202, 'The retry control must restart the saved summary through the endpoint.');
    $changing->refresh();
    $sync->forceFill(['state' => 'syncing'])->save();
    $check($controller->allTransactionsMatchSummary($request)->getStatusCode() === 202,
        'The endpoint must wait for stable mirror synchronization.');
    $throws(fn() => $service->advance($changing->id, $changing->dispatch_token, 0), 'Active mirror updates must block a pinned stage.');
    $throws(fn() => $service->forMirror($sync, null, $filters), 'Requests must not enqueue summaries of an unstable mirror.');
    $sync->forceFill(['state' => 'ready', 'sync_generation' => '44444444-4444-4444-8444-444444444444'])->save();
    $check($service->forMirror($sync, null, $mirrorFilters)->id !== $mirrorRun->id, 'Mirror refreshes must create a new summary identity.');
    $throws(fn() => $service->advance($changing->id, $changing->dispatch_token, 0), 'Changed mirror identities must never mix source versions.');
    echo "PASS: mirrored financial parity, JSON key-order independence and stable mirror snapshots\n";

    $large = VieFundTransactionWorkingSet::query()->create([
        'scope_hash' => str_repeat('c', 40), ...$filters, 'state' => 'ready',
        'active_generation' => '55555555-5555-4555-8555-555555555555', 'rows_cached' => 25001,
    ]);
    $batch = [];
    for ($id = 1; $id <= 25001; ++$id) {
        $batch[] = [
            'working_set_id' => $large->id, 'generation' => $large->active_generation,
            'cash_transaction_id' => $id, 'transaction_id' => 'C-'.$id, 'amount' => -0.0001,
            'match_status' => 'Unknown', 'payload' => '{}',
            'enrichment' => json_encode(['asm_items' => [['id' => $id, 'settlement_amount' => 0.0001]]], JSON_THROW_ON_ERROR),
        ];
        if (count($batch) === 100 || $id === 25001) {
            DB::table('viefund_transaction_working_set_rows')->insert($batch);
            $batch = [];
        }
    }
    unset($fixture);
    $largeRun = $service->forWorkingSet($large, null, $filters);
    memory_reset_peak_usage();
    $startMemory = memory_get_usage(true);
    $start = microtime(true);
    $cursor = 0;
    $stages = 0;
    do {
        $cursor = $service->advance($largeRun->id, $largeRun->dispatch_token, $cursor);
        ++$stages;
    } while ($cursor !== null);
    $largeRun->refresh();
    $elapsed = microtime(true) - $start;
    $growth = memory_get_peak_usage(true) - $startMemory;
    $check($stages === 26 && $largeRun->processed_rows === 25001
        && $largeRun->result['Unknown']['asm_count'] === 25001
        && $largeRun->result['Unknown']['viefund_total'] == -2.5,
        'A large source must complete in bounded stages without truncation or rounding drift.');
    $check(strlen(json_encode($largeRun->totals)) < 5000 && $growth < 32 * 1024 * 1024 && $elapsed < 30,
        'Durable accumulator state, memory and local synthetic runtime must remain bounded.');
    echo sprintf("PASS: 25,001 rows in %d bounded stages, %.2fs, %.1f MiB peak growth (local SQLite)\n", $stages, $elapsed, $growth / 1048576);

    $largeRun->forceFill(['updated_at' => now()->subDays(15)])->save();
    $orphan = $service->forMirror($sync, 'abandoned', $filters);
    $orphan->forceFill(['updated_at' => now()->subDays(15)])->save();
    $check($service->prune() === 2 && $orphan->fresh() === null
        && !DB::table('viefund_transaction_summary_records')->where('summary_id', $largeRun->id)->exists(),
        'Scheduled retention must remove expired summaries and their deduplication records.');
    $workingSet->delete();
    $check(!VieFundTransactionSummary::query()->where('working_set_id', $workingSet->id)->exists(),
        'Deleting a period cache must cascade to its summaries.');
    echo "PASS: retention and parent-cache cascade cleanup\n";
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception.PHP_EOL);
    $exitCode = 1;
} finally {
    Carbon::setTestNow();
    DB::disconnect();
    (new Filesystem())->deleteDirectory($directory);
}
exit($exitCode);
