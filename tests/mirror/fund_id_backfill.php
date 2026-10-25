<?php

use App\Jobs\SyncVieFundTransactionMirror;
use App\Console\Commands\SyncVieFundTransactionMirrorCommand;
use App\Models\User;
use App\Models\VieFundTransactionMirrorSync;
use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use App\Services\VieFund\VieFundTransactionMirrorQuery;
use App\Support\ApplicationAdmin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$testDirectory = sys_get_temp_dir().'/agora-mirror-test-'.bin2hex(random_bytes(8));
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
$testExitCode = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]],
        'cache.default' => 'array',
        'view.compiled' => $testDirectory,
        'viefund.mirror.batch_size' => 100,
        'viefund.mirror.data_source' => 'local',
        'viefund.mirror.currencies' => ['00'],
        'viefund.mirror.status_ids' => [6],
        'app.admin_access_emails' => ['admin@example.test'],
    ]);
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_193500_create_viefund_transaction_mirror_tables.php';
    $migration->up();
    Schema::table('viefund_transaction_mirror_rows', function (Blueprint $table): void {
        $table->string('fund_id', 100)->nullable();
        $table->timestamp('fund_id_checked_at')->nullable();
    });
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_24_000001_add_fund_id_verification_to_viefund_mirror.php';
    $migration->up();
    Schema::table('viefund_transaction_mirror_rows', fn(Blueprint $table) => $table->dropIndex('viefund_mirror_fund_checked_idx'));
    Schema::table('viefund_transaction_mirror_syncs', fn(Blueprint $table) => $table->dropColumn('fund_ids_verified_at'));
    $migration->up();
    $check(Schema::hasIndex('viefund_transaction_mirror_rows', 'viefund_mirror_fund_checked_idx'), 'Verification migration must be resumable.');
    $reportMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_25_000001_add_cashagch_mirror_verification.php';
    $reportMigration->up();
    Schema::table('viefund_transaction_mirror_rows', fn(Blueprint $table) => $table->dropIndex('viefund_mirror_trade_cursor_idx'));
    $reportMigration->up();
    $check(Schema::hasIndex('viefund_transaction_mirror_rows', 'viefund_mirror_trade_cursor_idx'), 'Report migration must restore an index after interrupted DDL.');
    $route = $app['router']->getRoutes()->getByName('admin.settings.mirror.backfill-fund-ids');
    $check($route !== null && in_array('application.admin', $route->gatherMiddleware(), true), 'Backfill control must remain admin-protected.');
    $compiled = $app['blade.compiler']->compileString(file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/settings/index.blade.php'));
    token_get_all($compiled, TOKEN_PARSE);

    $payloadFor = static fn(int $id): array => [
        'cash_transaction_id' => $id,
        'transaction_id' => 'C-'.$id,
        'source_id' => 'OLD-SOURCE',
        'fund_id' => null,
        'fund_transaction_id' => null,
        'trust_transaction_id' => null,
        'sort_id' => $id,
        'basis_date' => '2026-01-01 12:00:00',
        'transaction_type' => 'Old type',
        'customer_name' => 'Test Customer',
        'plan_account_id' => 'TEST-ACCOUNT',
        'amount' => $id === 3 ? -300.25 : 10,
        'currency_code' => '00',
        'cash_status_id' => 6,
        'created_date' => '2026-01-01 12:00:00',
        'settlement_date' => '2026-01-01 12:00:00',
    ];
    $insertRow = static function (int $id) use ($payloadFor): void {
        $payload = $payloadFor($id);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('viefund_transaction_mirror_rows')->insert([
            'cash_transaction_id' => $id,
            'transaction_id' => $payload['transaction_id'],
            'source_id' => $payload['source_id'],
            'transaction_type' => $payload['transaction_type'],
            'created_date' => $payload['created_date'],
            'settlement_date' => $payload['settlement_date'],
            'currency_code' => '00',
            'cash_status_id' => 6,
            'amount' => $payload['amount'],
            'payload' => $encoded,
            'enrichment' => '{"asm_items":[]}',
            'sync_generation' => 'original-generation',
            'source_fingerprint' => hash('sha256', $encoded),
            'source_synced_at' => now(),
        ]);
    };
    foreach (range(1, 205) as $id) {
        $insertRow($id);
    }
    VieFundTransactionMirrorSync::query()->create([
        'id' => 1, 'state' => 'ready', 'mode' => 'initial', 'ready_at' => now(), 'verified_at' => now(),
    ]);
    $availability = $app->make(VieFundAvailabilityWindow::class);
    $manager = new class($app->make(RuntimeSettings::class), new ApplicationAdmin(), $availability) extends VieFundTransactionMirrorManager
    {
        public array $dispatches = [];
        public function dispatch(?DateTimeInterface $after = null): void
        {
            $this->dispatches[] = $after;
        }
    };
    $source = new class extends VieFundRemoteService
    {
        public array $requests = [];
        public ?int $missingId = null;
        public ?Closure $onLookup = null;
        public array $newRows = [];
        public function __construct() {}
        public function fetchFundIdsForCashTransactions(array $cashTransactionIds): Collection
        {
            $this->requests[] = $cashTransactionIds;
            if ($this->onLookup) {
                ($this->onLookup)();
            }
            return collect($cashTransactionIds)->reject(fn(int $id): bool => $id === $this->missingId)
                ->map(function (int $id): object {
                    $ids = match ($id) {
                        2 => [],
                        3 => ['CASHa', 'VIEFund'],
                        207 => ['VIEFund'],
                        default => ['CASHagch'],
                    };
                    return (object) [
                        'cash_transaction_id' => $id,
                        'fund_id' => $ids[0] ?? null,
                        'trust_amount_used' => null, 'trust_amount_left' => null,
                        'funds' => collect($ids)->map(fn(string $fund, int $index): array => [
                            'cash_transaction_id' => $id,
                            'fund_id' => $fund,
                            'fund_transaction_id' => $id * 10 + $index,
                            'source_id' => 'SOURCE-'.$id.'-'.$index,
                            'transaction_type' => 'Type-'.$fund,
                            'transaction_type_count' => 1,
                        ])->all(),
                    ];
                })->values();
        }
        public function countAllTransactions(?string $search = null, array $filters = []): int
        {
            return 1;
        }
        public function firstAllTransactionDate(?string $search = null, array $filters = []): ?string
        {
            return null;
        }
        public function fetchAllTransactionExportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
        {
            return collect();
        }
        public function fetchAllTransactionMirrorRowsAfter(array $filters, ?array $cursor, int $limit = 1000): Collection
        {
            $rows = collect($this->newRows);
            $this->newRows = [];
            return $rows;
        }
    };
    $resolver = new class extends AllTransactionMatchStatusResolver
    {
        public function __construct() {}
        public function resolve(Collection $rows): Collection { return collect(); }
    };
    $job = new SyncVieFundTransactionMirror();
    $runBatch = static fn() => $job->handle($availability, $source, $resolver, $manager);
    $processed = static fn(): int => DB::table('viefund_transaction_mirror_rows')->whereNotNull('fund_id_checked_at')->count();
    $check($manager->shouldUseMirror(null) && !$manager->shouldUseMirror(null, ['fund_id' => 'CASHagch']), 'Only fund-filtered reads must fall back before verification.');
    $app->instance(VieFundTransactionMirrorManager::class, $manager);
    $command = $app->make(SyncVieFundTransactionMirrorCommand::class);
    $command->setLaravel($app);
    $tester = new CommandTester($command);
    $check($tester->execute(['--backfill-fund-ids' => true]) === 0, 'CLI backfill must queue successfully: '.$tester->getDisplay());
    $generation = $manager->sync()->sync_generation;
    $check($manager->startIncremental()->sync_generation === $generation, 'Scheduled sync must not replace an active backfill.');
    $runBatch();
    $check($processed() === 100 && !$manager->fundIdsReady(), 'A partial backfill must checkpoint without enabling fund reads.');
    $row = DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 1)->first();
    $check($row->fund_id === 'CASHagch' && json_decode($row->payload)->fund_id === 'CASHagch', 'Fund column and payload must agree.');
    $check(hash('sha256', $row->payload) === $row->source_fingerprint, 'Backfill must update the payload fingerprint.');
    $check($row->enrichment === '{"asm_items":[]}', 'Backfill must preserve match enrichment.');
    $check(DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 2)->value('fund_id') === null, 'A verified cash-only transaction must retain its legitimate null fund.');
    echo "PASS: admin route, Blade compilation, CLI, bounded historical backfill, nulls, fingerprints and safe fallback\n";

    $manager->pause();
    $requestCount = count($source->requests);
    $runBatch();
    $check(count($source->requests) === $requestCount, 'Paused jobs must not query the source.');
    $manager->resume();
    $source->onLookup = static fn() => $manager->pause();
    $runBatch();
    $check($processed() === 100, 'Pausing during a source lookup must not commit an in-flight batch.');
    $source->onLookup = null;
    $manager->resume();
    $source->missingId = 101;
    try {
        $runBatch();
        throw new RuntimeException('Missing source rows must fail verification.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'could not verify source cash transaction 101'), $exception->getMessage());
        $job->failed($exception);
    }
    $check($processed() === 100 && !$manager->fundIdsReady() && $manager->sync()->state === 'failed', 'Failed verification must preserve the last committed checkpoint.');
    $source->missingId = null;
    $manager->resume();
    DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 150)->update(['payload' => '123']);
    try {
        $runBatch();
        throw new RuntimeException('Invalid payload must fail the whole batch.');
    } catch (Throwable $exception) {
        $check($processed() === 100, 'A write error must roll back every row in the batch.');
        $check($exception->getMessage() !== 'Invalid payload must fail the whole batch.', $exception->getMessage());
        $job->failed($exception);
    }
    DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 150)->update(['payload' => json_encode($payloadFor(150), JSON_THROW_ON_ERROR)]);
    $manager->resume();
    $source->onLookup = static function (): void {
        VieFundTransactionMirrorSync::singleton()->forceFill(['sync_generation' => 'replacement-generation', 'state' => 'queued'])->save();
    };
    $runBatch();
    $check($processed() === 100, 'A changed generation must discard stale in-flight work.');
    $source->onLookup = null;
    Carbon::setTestNow('2026-10-24 23:05:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 23:05:00 UTC');
    $requestCount = count($source->requests);
    $runBatch();
    $check($manager->sync()->state === 'maintenance_wait' && count($source->requests) === $requestCount, 'Maintenance must defer backfill without a remote query.');
    $check(end($manager->dispatches) instanceof DateTimeInterface, 'Maintenance retry must be delayed until availability.');
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    $runBatch();
    $check($processed() === 200, 'Resumed backfill must continue from committed markers.');
    $insertRow(206);
    $runBatch();
    $check($processed() === 206 && !$manager->fundIdsReady(), 'Completion must include rows inserted during the historical scan.');
    $runBatch();
    $runBatch();
    for ($i = 0; $i < 40 && !$manager->sync()->isReady(); ++$i) {
        $runBatch();
    }
    $check($manager->fundIdsReady() && $manager->sync()->isReady(), 'Only a completed scan may mark fund IDs verified.');
    $check(max(array_map('count', $source->requests)) <= 100, 'Remote lookups must respect the batch limit.');
    echo "PASS: pause/resume, failed-source retry, atomic rollback, stale generation, maintenance, concurrent insertion\n";

    $query = new VieFundTransactionMirrorQuery();
    $filters = ['fund_id' => 'VIEFund', 'date_from' => '2026-01-01', 'date_to' => '2026-01-01'];
    $check($query->count(null, $filters) === 1, 'A secondary linked fund must match without duplicating cash transactions.');
    $page = $query->paginate(10, 1, null, $filters);
    $check($page->first()->fund_id === 'VIEFund' && $page->first()->source_id === 'SOURCE-3-1', 'Filtered display must use the matching fund metadata, not the unfiltered minimum.');
    $check($query->count(null, $filters + ['source_id' => 'SOURCE-3-1', 'trx_type' => ['Type-VIEFund'], 'trx_id' => 'F-31']) === 1, 'Combined source/type/transaction filters must use matching fund metadata.');
    $check($query->count(null, $filters + ['source_id' => 'SOURCE-3-0']) === 0, 'Another linked fund must not satisfy an additional source filter.');
    $check((float) $query->dailyStats(null, $filters)->first()->net_amount === -300.25, 'Filtered daily totals must agree with the selected cash transaction.');
    $check($query->periodSummary($filters)['transaction_count'] === 1, 'Filtered summary count must agree with the table.');
    $check($query->matchStatusSummary(null, $filters)['Unknown']['viefund_total'] === -300.25, 'Match-status summary must use the same fund filter.');
    $check($query->exportRowsAfter(null, $filters, null, 100)->first()->fund_id === 'VIEFund', 'Filtered exports must use the same matching fund metadata.');
    DB::table('viefund_transaction_mirror_funds')->where('cash_transaction_id', 3)->where('fund_id', 'VIEFund')->update([
        'source_id' => null, 'transaction_type' => null, 'cash_source_id' => 'CASH-SOURCE', 'cash_transaction_type' => 'Cash type',
    ]);
    $check($query->count(null, $filters + ['source_id' => 'CASH-SOURCE', 'trx_type' => ['Cash type']]) === 1, 'Fund filtering must preserve cash/trust metadata fallbacks.');
    $check($manager->shouldUseMirror(null, $filters), 'Verified funds should use the enabled mirror.');
    config(['viefund.mirror.data_source' => 'current']);
    $check(!$manager->shouldUseMirror(null, $filters), 'Verification must not override the current-source setting.');
    config(['viefund.mirror.data_source' => 'admin_preview']);
    $check(!$manager->shouldUseMirror(new User(['email' => 'user@example.test']), $filters), 'Preview must remain admin-only.');
    $check($manager->shouldUseMirror(new User(['email' => 'admin@example.test']), $filters), 'Administrators should be able to preview verified fund reads.');
    echo "PASS: multiple linked funds, counts, summaries, combined filters, exports, source modes and preview permissions\n";

    $manager->startIncremental();
    $payload = $payloadFor(207);
    $payload['created_date'] = $payload['settlement_date'] = '2026-10-24 12:00:00';
    $source->newRows = [(object) $payload];
    $runBatch();
    $runBatch();
    $check($processed() === 207, 'New incremental rows must carry verified metadata markers.');
    $check(DB::table('viefund_transaction_mirror_funds')->where('cash_transaction_id', 207)->value('fund_id') === 'VIEFund', 'Incremental sync must maintain every linked fund.');
    $runBatch();
    $runBatch();
    for ($i = 0; $i < 10 && !$manager->sync()->isReady(); ++$i) {
        $runBatch();
    }
    $check($manager->sync()->isReady() && $manager->fundIdsReady(), 'Regular incremental completion must preserve fund readiness.');
    DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 207)->delete();
    $check(!DB::table('viefund_transaction_mirror_funds')->where('cash_transaction_id', 207)->exists(), 'Deleting stale mirror rows must cascade to linked fund metadata.');
    echo "PASS: incremental metadata maintenance, verification and cascading stale-row cleanup\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $testExitCode = 1;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    (new Filesystem())->deleteDirectory($testDirectory);
}
exit($testExitCode);
