<?php

use App\Http\Controllers\RemoteVieFundController;
use App\Jobs\CountVieFundAllTransactions;
use App\Models\VieFundTransactionMirrorSync;
use App\Models\VieFundTransactionWorkingSet;
use App\Services\VieFund\VieFundAllTransactionsCountService;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionMirrorQuery;
use App\Services\VieFund\VieFundTransactionWorkingSetManager;
use App\Services\VieFund\VieFundTransactionWorkingSetQuery;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-count-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$directory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$exit = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]],
        'cache.default' => 'array',
    ]);
    Bus::fake();
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    foreach ([
        '2026_10_06_000001_create_viefund_transaction_working_sets',
        '2026_10_07_000002_add_balance_report_to_viefund_transaction_working_sets',
        '2026_10_08_000001_add_hydration_control_to_viefund_transaction_working_sets',
        '2026_10_15_193500_create_viefund_transaction_mirror_tables',
    ] as $name) {
        (require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php')->up();
    }
    Schema::table('viefund_transaction_working_set_rows', function (Blueprint $table): void {
        $table->string('fund_id')->nullable();
        $table->boolean('has_asm_match')->default(false);
    });
    Schema::table('viefund_transaction_mirror_rows', fn(Blueprint $table) => $table->string('fund_id')->nullable());

    $filters = [
        'date_basis' => 'trade_date', 'date_from' => '2026-01-01', 'date_to' => '2026-07-31',
        'currency_code' => '00', 'status_ids' => [6],
    ];
    $manager = $app->make(VieFundTransactionWorkingSetManager::class);
    $workingSet = $manager->ensure($filters);
    $generation = $workingSet->build_generation;
    $workingSet->forceFill([
        'active_generation' => $generation, 'build_generation' => null, 'state' => 'ready', 'rows_cached' => 2,
    ])->save();
    foreach ([1 => 'Client 0', 2 => 'Client 2'] as $id => $name) {
        DB::table('viefund_transaction_working_set_rows')->insert([
            'working_set_id' => $workingSet->id, 'generation' => $generation,
            'cash_transaction_id' => $id, 'transaction_id' => 'C-'.$id, 'customer_name' => $name,
            'basis_date' => '2026-01-02 00:00:00', 'trade_date' => '2026-01-02 00:00:00',
            'amount' => 1, 'payload' => '{}',
        ]);
    }
    $controller = $app->make(RemoteVieFundController::class);
    $service = $app->make(VieFundAllTransactionsCountService::class);
    $request = static fn(array $extra = []): Request => Request::create('/viefund-transactions/count', 'GET', [
        'filter_date_basis' => 'trade_date', 'filter_date_from' => '2026-01-01',
        'filter_date_to' => '2026-07-31', 'filter_currency_code' => '00', 'filter_status' => [6], ...$extra,
    ]);
    $statusKey = static fn(CountVieFundAllTransactions $job): string => 'viefund_transaction_count_v2_status:'.$job->signature;
    $run = static function (CountVieFundAllTransactions $job) use ($service): ?Throwable {
        try {
            $job->handle($service);
            return null;
        } catch (Throwable $exception) {
            $job->failed($exception);
            return $exception;
        }
    };
    $assertNoRowScans = static function () use ($check): void {
        foreach (DB::getQueryLog() as $query) {
            $check(!preg_match('/\b(?:from|join)\s+["`]?viefund_transaction_(?:working_set|mirror)_rows\b/i', $query['query']),
                'Count HTTP requests must never query transaction rows, even during refresh or with filters.');
        }
        DB::flushQueryLog();
    };
    Bus::fake();
    DB::enableQueryLog();
    $metadata = $controller->allTransactionsCountStatus($request())->getData(true);
    $assertNoRowScans();
    $check($metadata['state'] === 'complete' && $metadata['total'] === 2, 'Unfiltered totals must use exact saved metadata.');
    $check(Bus::dispatched(CountVieFundAllTransactions::class)->isEmpty(), 'Metadata-only totals do not require a job.');

    for ($i = 0; $i < 20; ++$i) {
        $response = $controller->allTransactionsCountStatus($request(['search' => 'Client', 'per_page' => 50, 'page' => $i + 1]));
        $check($response->getStatusCode() === 202, 'Filtered counts must return immediately as processing.');
    }
    $assertNoRowScans();
    $jobs = Bus::dispatched(CountVieFundAllTransactions::class);
    $check($jobs->count() === 1, 'Repeated polls and pagination changes must enqueue one count per source/filter.');
    $job = $jobs->first();
    $check($job->connection === 'database_viefund_cache' && $job->timeout === 45 && $job->failOnTimeout,
        'Counts must use the existing asynchronous cache queue with a hard worker deadline.');
    $check($run($job) === null, 'Background counting must succeed for unchanged generations.');
    DB::flushQueryLog();
    $complete = $controller->allTransactionsCountStatus($request(['search' => 'Client', 'per_page' => 50, 'page' => 2]))->getData(true);
    $assertNoRowScans();
    $check($complete['state'] === 'complete' && $complete['total'] === 2 && $complete['total_pages'] === 1
        && $complete['page'] === 2, 'Cached totals must preserve exact totals and pagination response fields.');

    $controller->allTransactionsCountStatus($request(['search' => '0']));
    $zeroSearch = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($run($zeroSearch) === null, 'A literal zero search must not become an unfiltered count.');
    $check($controller->allTransactionsCountStatus($request(['search' => '0']))->getData(true)['total'] === 1,
        'Numeric-looking searches must retain their exact filter semantics.');
    $controller->allTransactionsCountStatus($request(['search' => 'absent']));
    $check($run(Bus::dispatched(CountVieFundAllTransactions::class)->last()) === null, 'Empty filtered results must succeed.');
    $check($controller->allTransactionsCountStatus($request(['search' => 'absent']))->getData(true)['total'] === 0,
        'A real zero count must not be treated as a missing result.');
    echo "PASS: count-free HTTP paths, exact metadata, 20-request deduplication, pagination, literal-zero search and zero results\n";

    Cache::flush();
    Bus::fake();
    $workingSet->forceFill(['build_generation' => 'new-build', 'state' => 'refreshing', 'rows_cached' => 99])->save();
    DB::flushQueryLog();
    $response = $controller->allTransactionsCountStatus($request());
    $assertNoRowScans();
    $check($response->getStatusCode() === 202, 'Refresh totals must not use a different generation\'s rows_cached.');
    $refreshJob = Bus::dispatched(CountVieFundAllTransactions::class)->first();
    $check($run($refreshJob) === null, 'Refresh totals must count the pinned active generation in the background.');
    $check($controller->allTransactionsCountStatus($request())->getData(true)['total'] === 2,
        'The active total must remain 2, not the new build progress of 99.');

    $workingSet->forceFill(['active_generation' => null, 'build_generation' => $generation, 'state' => 'warming', 'rows_cached' => 2])->save();
    $partial = $controller->allTransactionsCountStatus($request(['search' => 'Client']));
    $partialJob = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($partial->getStatusCode() === 202 && $run($partialJob) === null, 'Filtered partial totals must also run asynchronously.');
    $check($controller->allTransactionsCountStatus($request(['search' => 'Client']))->getData(true)['state'] === 'partial',
        'An incomplete generation must not claim a complete total.');
    $workingSet->forceFill(['state' => 'paused'])->save();
    $paused = $controller->allTransactionsCountStatus($request(['search' => 'Client']))->getData(true);
    $check($paused['state'] === 'partial' && $paused['cache_state'] === 'paused', 'Stopped caches retain usable partial totals.');
    $workingSet->forceFill(['active_generation' => $generation, 'build_generation' => null, 'state' => 'ready'])->save();
    $check($controller->allTransactionsCountStatus($request(['search' => 'Client']))->getStatusCode() === 202,
        'Promotion must not reuse a partial total as a complete one.');
    $promotionJob = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($promotionJob->signature !== $partialJob->signature && $run($promotionJob) === null,
        'Completion must change the cache identity even when the generation UUID stays the same.');

    $controller->allTransactionsCountStatus($request(['search' => 'stale-generation']));
    $stale = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $workingSet->forceFill(['active_generation' => 'replacement'])->save();
    $check($run($stale) instanceof RuntimeException, 'A queued count must reject an obsolete generation.');
    $check($controller->allTransactionsCountStatus($request(['search' => 'stale-generation']))->getStatusCode() === 202,
        'An obsolete failure must not poison the replacement generation.');
    $replacement = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($replacement->signature !== $stale->signature, 'Generation changes require different result keys and locks.');
    $workingSet->forceFill(['active_generation' => $generation])->save();

    $onCount = null;
    DB::listen(static function ($event) use (&$onCount): void {
        if ($onCount && str_contains(strtolower($event->sql), 'count(*)')) {
            $callback = $onCount;
            $onCount = null;
            $callback();
        }
    });
    $controller->allTransactionsCountStatus($request(['search' => 'Client 2']));
    $during = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $onCount = static fn() => $workingSet->forceFill(['active_generation' => 'changed-during-count'])->save();
    $check($run($during) instanceof RuntimeException, 'A count must also reject a generation change during execution.');
    $workingSet->forceFill(['active_generation' => $generation])->save();
    echo "PASS: refresh generation isolation, partial/paused totals, promotion invalidation and stale-source rejection before/after SQL\n";

    Cache::flush();
    Bus::fake();
    $controller->allTransactionsCountStatus($request(['search' => 'missing-worker']));
    $missing = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $status = Cache::get($statusKey($missing));
    Cache::put($statusKey($missing), [...$status, 'queued_at' => time() - 601], 720);
    $unavailable = $controller->allTransactionsCountStatus($request(['search' => 'missing-worker']))->getData(true);
    $check($unavailable['state'] === 'failed' && !array_key_exists('total', $unavailable),
        'A missing worker must become an explicit failure, never an infinite processing status or fake zero.');
    $check($run($missing) === null, 'An already-failed queued job must not run a count later.');
    $jobCount = Bus::dispatched(CountVieFundAllTransactions::class)->count();
    for ($i = 0; $i < 5; ++$i) {
        $check($controller->allTransactionsCountStatus($request(['search' => 'missing-worker']))->getData(true)['state'] === 'failed',
            'Repeated failed-status reads must remain terminal.');
    }
    $check(Bus::dispatched(CountVieFundAllTransactions::class)->count() === $jobCount,
        'Polling a failed count must not continuously dispatch replacements.');
    Cache::forget($statusKey($missing));
    $controller->allTransactionsCountStatus($request(['search' => 'missing-worker']));
    $new = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $missing->failed(new RuntimeException('Old delivery failed late.'));
    $check(Cache::get($statusKey($new))['token'] === $new->token, 'Old failures cannot overwrite replacement dispatch ownership.');

    $middleware = $new->middleware()[0];
    $lock = Cache::lock($middleware->getLockKey($new), 60);
    $check($lock->get(), 'The execution mutex must be available before the overlap test.');
    $executed = false;
    $middleware->handle($new, static function () use (&$executed): void { $executed = true; });
    $check(!$executed, 'Counts for different filters must never run concurrently across workers.');
    $lock->release();
    $middleware->handle($new, static function () use (&$executed): void { $executed = true; });
    $check($executed && $middleware->expiresAfter > $new->timeout, 'Execution locks must outlive the hard job deadline.');
    $new->failed(new RuntimeException('Simulated worker timeout.'));
    $check(Cache::get($statusKey($new))['state'] === 'failed', 'Worker timeouts must publish terminal status.');
    $controller->allTransactionsCountStatus($request(['search' => 'stuck-running']));
    $stuck = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $status = Cache::get($statusKey($stuck));
    Cache::put($statusKey($stuck), [...$status, 'started_at' => time() - 76], 720);
    $check($controller->allTransactionsCountStatus($request(['search' => 'stuck-running']))->getData(true)['state'] === 'failed',
        'A running worker that stops updating must become unavailable after its running deadline.');

    Bus::swap(new class($app) extends Dispatcher {
        public function dispatch($command)
        {
            throw new RuntimeException('Simulated database queue failure.');
        }
    });
    $dispatchFailure = $service->forRemote('dispatch-failure', $filters);
    $check($dispatchFailure['state'] === 'failed', 'Dispatch exceptions must be visible immediately.');
    Bus::fake();
    config(['queue.connections.database_viefund_cache.driver' => 'sync']);
    $check($service->forRemote('sync-misconfiguration', $filters)['state'] === 'failed',
        'Misconfiguration must not fall back to counting inside HTTP.');
    config(['queue.connections.database_viefund_cache.driver' => 'database']);
    echo "PASS: missing/stalled workers, bounded failure status, token ownership, global count serialization and fail-closed dispatch\n";

    Cache::flush();
    Bus::fake();
    config(['viefund.mirror.data_source' => 'local']);
    $sync = VieFundTransactionMirrorSync::singleton();
    $sync->forceFill([
        'state' => 'ready', 'sync_generation' => 'mirror-first',
        'ready_at' => now(), 'verified_at' => now(), 'synced_through_at' => now(),
    ])->save();
    DB::table('viefund_transaction_mirror_rows')->insert([
        'cash_transaction_id' => 1, 'transaction_id' => 'C-1', 'customer_name' => 'Client',
        'trade_date' => '2026-01-02 00:00:00', 'currency_code' => '00', 'cash_status_id' => 6,
        'amount' => 1, 'payload' => '{}', 'sync_generation' => 'mirror-first',
        'source_fingerprint' => str_repeat('a', 64), 'source_synced_at' => now(),
    ]);
    DB::flushQueryLog();
    $mirrorPending = $controller->allTransactionsCountStatus($request(['search' => 'Client']));
    $assertNoRowScans();
    $mirrorJob = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($mirrorPending->getStatusCode() === 202 && $mirrorJob->payload['source']['source'] === 'mirror',
        'Mirror counts must use the same background path, not synchronous SQL.');
    $check($run($mirrorJob) === null, 'A stable verified mirror count must succeed.');
    $mirrorComplete = $controller->allTransactionsCountStatus($request(['search' => 'Client']))->getData(true);
    $check($mirrorComplete['source'] === 'mirror' && $mirrorComplete['total'] === 1,
        'Mirror totals cannot reuse the period cache\'s same-filter result of 2.');
    DB::flushQueryLog();
    $page = $app->make(VieFundTransactionMirrorQuery::class)->paginate(50, 1, null, $filters);
    $check($page->count() === 1 && count(DB::getQueryLog()) === 1
        && !str_contains(strtolower(DB::getQueryLog()[0]['query']), 'count(*)'),
        'Interactive mirror pagination must not hide another synchronous COUNT.');
    $sync->forceFill(['state' => 'syncing'])->save();
    $check($service->forMirror($sync, 'Client', $filters)['state'] === 'processing',
        'A mutating mirror must not serve a stale successful count.');
    $sync->forceFill(['state' => 'ready', 'sync_generation' => 'mirror-next'])->save();
    $check($controller->allTransactionsCountStatus($request(['search' => 'Client']))->getStatusCode() === 202,
        'Mirror generation changes must invalidate cached totals.');
    config(['viefund.mirror.data_source' => 'current']);
    DB::flushQueryLog();
    $remote = $controller->allTransactionsCountStatus($request(['search' => 'Client', 'filter_date_from' => '2025-01-01']));
    $assertNoRowScans();
    $remoteJob = Bus::dispatched(CountVieFundAllTransactions::class)->last();
    $check($remote->getStatusCode() === 202 && $remoteJob->payload['source']['source'] === 'remote',
        'Remote counts must also queue asynchronously with a distinct source identity.');

    Schema::create('settlement_instructions', function (Blueprint $table): void {
        $table->id();
        $table->string('source_type');
        $table->string('source_id')->nullable();
        $table->date('settlement_date');
    });
    DB::table('settlement_instructions')->insert([
        ['source_type' => 'fundserv_agra', 'source_id' => 'AGRA', 'settlement_date' => '2026-02-01'],
        ['source_type' => 'fundserv_agra', 'source_id' => 'OLD', 'settlement_date' => '2025-02-01'],
        ['source_type' => 'ltm', 'source_id' => '7960', 'settlement_date' => '2026-03-01'],
    ]);
    $remoteSource = new class extends VieFundRemoteService {
        public array $last = [];
        public function __construct() {}
        public function countAllTransactionsWithDeadline(?string $search = null, array $filters = []): int
        {
            $this->last = ['search' => $search, 'filters' => $filters];
            return 123;
        }
    };
    $remoteCounts = new VieFundAllTransactionsCountService(
        $app->make(VieFundTransactionWorkingSetQuery::class),
        $app->make(VieFundTransactionMirrorQuery::class),
        $remoteSource
    );
    $fspFilters = [...$filters, 'date_basis' => 'settlement_date', 'has_agra_fsp_match' => true, 'has_7960_fsp_match' => true];
    $check($remoteCounts->calculate(['source' => 'remote'], '0', $fspFilters) === 123,
        'Remote totals must retain their exact calculator result.');
    $check($remoteSource->last['search'] === '0'
        && json_decode($remoteSource->last['filters']['agra_fsp_source_ids_json'], true) === ['AGRA']
        && json_decode($remoteSource->last['filters']['fsp_7960_source_ids_json'], true) === ['7960']
        && !isset($remoteSource->last['filters']['has_agra_fsp_match']),
        'Moving counts must preserve numeric search and both settlement-scoped FSP match filters.');
    $remoteCounts->calculate(['source' => 'remote'], null, [...$fspFilters, 'date_basis' => 'trade_date']);
    $check(json_decode($remoteSource->last['filters']['agra_fsp_source_ids_json'], true) === ['AGRA', 'OLD'],
        'Non-settlement date bases must retain the existing unbounded FSP source-ID selection.');
    $log = file_get_contents($directory.'/logs/viefund-transaction-counts-'.date('Y-m-d').'.log');
    $check(str_contains($log, 'count_signature') && str_contains($log, 'duration_ms')
        && str_contains($log, 'Transaction count failed.'), 'Queue/completion/failure logs must retain correlation and timings.');
    echo "PASS: mirror/remote HTTP isolation, count-free mirror pagination, exact FSP filters, source-aware caching and diagnostic logs\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exit = 1;
} finally {
    DB::disconnect();
    (new Filesystem())->deleteDirectory($directory);
}
exit($exit);
