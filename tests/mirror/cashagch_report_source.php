<?php

use App\Http\Controllers\VieFundCashAgchReportController;
use App\Http\Controllers\VieFundReportCancellationController;
use App\Jobs\GenerateVieFundCashAgchReport;
use App\Jobs\SyncVieFundTransactionMirror;
use App\Models\User;
use App\Models\VieFundTransactionMirrorSync;
use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundCashAgchReportRun;
use App\Services\VieFund\VieFundCashAgchReportSource;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use App\Support\ApplicationAdmin;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-cashagch-mirror-'.bin2hex(random_bytes(8));
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
$clock = static function (string $time): void {
    Carbon::setTestNow($time);
    CarbonImmutable::setTestNow($time);
};
$workbook = static function (string $path): array {
    $reader = new Reader();
    $reader->open($path);
    $sheets = [];
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();
                if ($sheet->getName() === 'Summary' && in_array($values[0] ?? '', ['Data Source', 'Mirror Last Data Sync', 'VieFund Fallback Reason'], true)) {
                    continue;
                }
                $sheets[$sheet->getName()][] = $values;
            }
        }
    } finally {
        $reader->close();
    }
    return $sheets;
};
$exit = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]],
        'cache.default' => 'array', 'session.driver' => 'array', 'view.compiled' => $directory,
        'viefund.mirror.currencies' => ['00', '01'], 'viefund.mirror.status_ids' => range(0, 6),
        'viefund.mirror.data_source' => 'current', 'viefund.mirror.batch_size' => 100,
        'viefund.balance_report_cash_account_scope' => ['currency_code' => null, 'opened_before' => null, 'excluded_plan_accounts' => []],
    ]);
    $clock('2026-10-24 12:00:00 UTC');
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    foreach (['2026_10_15_193500_create_viefund_transaction_mirror_tables',
        '2026_10_24_000001_add_fund_id_verification_to_viefund_mirror',
        '2026_10_25_000001_add_cashagch_mirror_verification'] as $name) {
        (require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php')->up();
    }
    Schema::table('viefund_transaction_mirror_rows', fn(Blueprint $table) => $table->string('fund_id', 100)->nullable());
    Schema::table('viefund_transaction_mirror_funds', fn(Blueprint $table) => $table->string('fund_id', 100)->collation('nocase')->change());
    $source = new class extends VieFundRemoteService
    {
        public array $cash = [];
        public array $funds = [];
        public array $calls = [];
        public function __construct() {}
        private function matching(array $filters): Collection
        {
            $basis = $filters['date_basis'] === 'create_date' ? 'created_date' : $filters['date_basis'];
            $from = ($filters['date_from'] ?? '1900-01-01').' 00:00:00';
            $to = CarbonImmutable::parse($filters['date_to'])->addDay()->startOfDay()->format('Y-m-d H:i:s.u');
            $availability = CarbonImmutable::parse($filters['availability_date_to'] ?? $filters['date_to'])
                ->addDay()->startOfDay()->format('Y-m-d H:i:s.u');
            return collect($this->cash)->filter(function (object $row) use ($filters, $basis, $from, $to, $availability): bool {
                $links = array_filter($this->funds[$row->cash_transaction_id], fn(array $link): bool => strcasecmp($link['fund_id'], 'CASHagch') === 0);
                if ($links === [] || $row->amount === null || $row->{$basis} === null || $row->{$basis} < $from || $row->{$basis} >= $to
                    || $row->currency_code !== $filters['currency_code'] || !in_array($row->cash_status_id, $filters['status_ids'], true)) {
                    return false;
                }
                if ($basis !== 'trade_date') return true;
                return $row->settlement_date === null || $row->settlement_date < $availability
                    || BigDecimal::of($row->amount)->isLessThanOrEqualTo(0)
                    || $row->trust_transaction_id === null || $row->trust_status === null || $row->trust_status !== 'Unsettled'
                    || BigDecimal::of($row->trust_amount_used ?? '0')->isGreaterThan(0)
                    || BigDecimal::of($row->trust_amount_left ?? '0')->isLessThanOrEqualTo(0);
            })->map(function (object $original) use ($basis): object {
                $row = clone $original;
                $links = array_values(array_filter($this->funds[$row->cash_transaction_id], fn(array $link): bool => strcasecmp($link['fund_id'], 'CASHagch') === 0));
                $types = array_unique(array_map(fn(array $link): string => $link['transaction_type'] ?? 'Unknown', $links));
                $row->fund_id = $links[0]['fund_id'];
                $row->fund_transaction_id = min(array_column($links, 'fund_transaction_id'));
                $sources = array_filter(array_column($links, 'source_id'), fn($value): bool => $value !== null);
                $row->source_id = $sources !== [] ? min($sources) : $original->source_id;
                $row->transaction_type = count($types) > 1 ? 'Multiple fund transaction types' : reset($types);
                $row->basis_date = $row->{$basis};
                return $row;
            })->sort(fn(object $a, object $b): int => [$a->basis_date, $a->sort_id] <=> [$b->basis_date, $b->sort_id])->values();
        }
        public function countAllTransactions(?string $search = null, array $filters = []): int
        {
            $this->calls[] = ['count', $filters];
            return $this->matching($filters)->count();
        }
        public function firstAllTransactionDate(?string $search = null, array $filters = []): ?string
        {
            $this->calls[] = ['first_date', $filters];
            return $this->matching($filters)->min('basis_date');
        }
        public function fetchAllTransactionExportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
        {
            $this->calls[] = ['read', $filters, $limit];
            return $this->matching($filters)->filter(fn(object $row): bool => $cursor === null
                || [$row->basis_date, $row->sort_id] > [$cursor['basis_date'], $cursor['sort_id']])->take($limit)->values();
        }
        public function fetchFundIdsForCashTransactions(array $cashTransactionIds): Collection
        {
            $this->calls[] = ['metadata', $cashTransactionIds];
            return collect($cashTransactionIds)->map(function (int $id): object {
                $funds = collect($this->funds[$id])->groupBy('fund_id')->map(function (Collection $links, string $fund) use ($id): array {
                    $types = $links->pluck('transaction_type')->filter(fn($value): bool => $value !== null)->all();
                    $sources = $links->pluck('source_id')->filter(fn($value): bool => $value !== null)->all();
                    return [
                        'cash_transaction_id' => $id, 'fund_id' => $fund,
                        'fund_transaction_id' => $links->min('fund_transaction_id'),
                        'source_id' => $sources !== [] ? min($sources) : null,
                        'cash_source_id' => $this->cash[$id]->source_id, 'cash_transaction_type' => 'Cash fallback',
                        'transaction_type' => $types !== [] ? min($types) : null,
                        'transaction_type_count' => $links->map(fn(array $link): string => $link['transaction_type'] ?? 'Unknown')->unique()->count(),
                    ];
                })->sortKeys()->values();
                return (object) [
                    'cash_transaction_id' => $id, 'fund_id' => $funds->first()['fund_id'] ?? null,
                    'funds' => $funds->all(),
                    'trust_amount_used' => $this->cash[$id]->trust_amount_used,
                    'trust_amount_left' => $this->cash[$id]->trust_amount_left,
                ];
            });
        }
    };
    foreach ([
        ['Purchase', '-100.1234'], ['Purchase PAC', '-200.0001'], ['Rebalancing purchase', '-10.0000'],
        ['Redemption', '30.1234'], ['Rebalancing redemption', '12.3456'], ['Fee Redemption', '1.0001'],
        ['Fee', '-0.1234'], ['Automatic', '0.0000'], ['Systematic', '2.0000'], [null, '0.0001'],
        ['Purchase', '1.1234'], ['Redemption', '-2.1234'], ['Fee Redemption', '-0.0001'],
    ] as $index => [$type, $amount]) {
        $id = $index + 1;
        $date = $index < 4 ? '2026-08-01 12:00:00.000000' : '2026-09-30 23:59:59.999999';
        $source->cash[$id] = (object) [
            'cash_transaction_id' => $id, 'sort_id' => $id, 'transaction_id' => 'C-'.$id,
            'fund_transaction_id' => 1000 + $id, 'source_id' => 'CASH-'.$id, 'fund_id' => 'CASHa',
            'transaction_type' => 'Old representative', 'customer_name' => '=Text not formula', 'plan_account_id' => '000123',
            'amount' => $amount, 'currency_code' => '00', 'cash_status_id' => 6, 'status' => 'Confirmed',
            'created_date' => $date, 'trade_date' => $date, 'processing_date' => $date, 'settlement_date' => $date,
            'basis_date' => $date, 'trust_transaction_id' => 2000 + $id,
            'trust_status' => 'Unsettled', 'trust_amount_used' => '0.0000', 'trust_amount_left' => '100.0000',
        ];
        $source->funds[$id] = [
            ['fund_id' => 'CASHa', 'fund_transaction_id' => $id, 'source_id' => 'WRONG', 'transaction_type' => 'Redemption'],
            ['fund_id' => 'CASHagch', 'fund_transaction_id' => 1000 + $id, 'source_id' => 'FUND-'.$id, 'transaction_type' => $type],
        ];
    }
    foreach (range(14, 28) as $id) {
        $source->cash[$id] = clone $source->cash[4];
        $row = $source->cash[$id];
        $row->cash_transaction_id = $row->sort_id = $id;
        $row->transaction_id = 'C-'.$id;
        $row->created_date = $row->trade_date = $row->processing_date = $row->settlement_date = '2026-10-24 23:59:59.999999';
        $source->funds[$id] = [['fund_id' => 'CASHagch', 'fund_transaction_id' => 1000 + $id, 'source_id' => null, 'transaction_type' => 'Redemption']];
    }
    foreach (range(14, 21) as $id) {
        $source->cash[$id]->settlement_date = '2026-11-01 00:00:00.000000';
    }
    $source->cash[15]->amount = '-0.0001';
    $source->cash[16]->trust_amount_used = '0.0001';
    $source->cash[17]->trust_amount_left = '0.0000';
    $source->cash[18]->trust_transaction_id = null;
    $source->cash[19]->trust_status = null;
    $source->cash[20]->trust_status = 'Settled';
    $source->cash[21]->trust_amount_left = null;
    $source->cash[22]->settlement_date = null;
    $source->cash[23]->created_date = null;
    $source->cash[24]->currency_code = '01';
    $source->cash[25]->cash_status_id = 3;
    $source->cash[26]->created_date = $source->cash[26]->trade_date = $source->cash[26]->processing_date
        = $source->cash[26]->settlement_date = '2026-10-25 00:00:00.000000';
    $source->funds[27][] = ['fund_id' => 'CASHagch', 'fund_transaction_id' => 9999, 'source_id' => 'ZZ', 'transaction_type' => 'Purchase'];
    $source->funds[28] = [['fund_id' => 'CASHagch-extra', 'fund_transaction_id' => 1028, 'source_id' => 'NO', 'transaction_type' => 'Purchase']];
    $source->funds[10][] = ['fund_id' => 'CASHagch', 'fund_transaction_id' => 9998, 'source_id' => 'ZZ', 'transaction_type' => 'Purchase'];
    $source->funds[3][1]['fund_id'] = 'CASHAGCH';
    foreach ([29, 30, 31] as $id) {
        $source->cash[$id] = clone $source->cash[4];
        $source->cash[$id]->cash_transaction_id = $source->cash[$id]->sort_id = $id;
        $source->cash[$id]->transaction_id = 'C-'.$id;
        $source->funds[$id] = [['fund_id' => 'CASHAGCH', 'fund_transaction_id' => 1000 + $id, 'source_id' => null,
            'transaction_type' => $id === 31 ? null : 'Redemption']];
    }
    $source->cash[29]->settlement_date = '2026-09-15 00:00:00.000000';
    $source->funds[30][] = $source->funds[30][0];
    $insert = static function (object $row) use ($source): void {
        $payload = json_encode($row, JSON_THROW_ON_ERROR);
        DB::table('viefund_transaction_mirror_rows')->insert([
            'cash_transaction_id' => $row->cash_transaction_id, 'transaction_id' => $row->transaction_id,
            'fund_transaction_id' => $row->fund_transaction_id, 'trust_transaction_id' => $row->trust_transaction_id,
            'fund_id' => $row->fund_id, 'fund_id_checked_at' => now(), 'source_id' => $row->source_id,
            'transaction_type' => $row->transaction_type, 'trust_status' => $row->trust_status,
            'amount' => $row->amount, 'currency_code' => $row->currency_code, 'cash_status_id' => $row->cash_status_id,
            'created_date' => $row->created_date, 'trade_date' => $row->trade_date,
            'processing_date' => $row->processing_date, 'settlement_date' => $row->settlement_date,
            'payload' => $payload, 'source_fingerprint' => hash('sha256', $payload),
            'sync_generation' => 'baseline', 'source_synced_at' => now(),
        ]);
        foreach (collect($source->funds[$row->cash_transaction_id])->unique('fund_id') as $link) {
            DB::table('viefund_transaction_mirror_funds')->insert(['cash_transaction_id' => $row->cash_transaction_id, ...$link]);
        }
    };
    foreach ($source->cash as $row) $insert($row);
    $sync = VieFundTransactionMirrorSync::query()->create([
        'id' => 1, 'state' => 'ready', 'mode' => 'incremental', 'phase' => 'ready',
        'ready_at' => now(), 'verified_at' => now(), 'fund_ids_verified_at' => now(),
        'synced_through_at' => now(), 'sync_generation' => 'baseline',
        'currencies' => ['00', '01'], 'status_ids' => range(0, 6),
    ]);
    $app->instance(VieFundRemoteService::class, $source);
    $selector = new VieFundCashAgchReportSource();
    $criteria = ['date_from' => null, 'date_to' => '2026-10-24', 'date_basis' => 'settlement_date', 'currency_code' => '00', 'status_ids' => [6]];
    $check($selector->select($criteria)['data_source'] === 'viefund', 'A fund-ID backfill alone must not enable unverified report classification.');
    $manager = new class(new RuntimeSettings(), new ApplicationAdmin(), new VieFundAvailabilityWindow()) extends VieFundTransactionMirrorManager {
        public int $dispatches = 0;
        public function dispatch(?DateTimeInterface $delay = null): void { ++$this->dispatches; }
    };
    $resolver = new class extends AllTransactionMatchStatusResolver {
        public function __construct() {}
        public function resolve(Collection $rows): Collection { return collect(); }
    };
    $availability = new VieFundAvailabilityWindow();
    $job = new SyncVieFundTransactionMirror();
    $upgrade = static function () use ($manager, $job, $availability, $source, $resolver, $check): void {
        $manager->startFundIdBackfill();
        for ($stage = 0; $stage < 100 && !$manager->sync()->isReady(); ++$stage) {
            $job->handle($availability, $source, $resolver, $manager);
        }
        $check($manager->sync()->isReady(), 'Metadata upgrade did not finish in bounded stages.');
    };
    $upgrade();
    $sync->refresh();
    $check($sync->cashagch_verified_at !== null, 'Complete metadata and all-basis coverage must be verified: '.$sync->cashagch_verification_error);
    $check($selector->select($criteria)['data_source'] === 'mirror', 'Verified mirror must be preferred independently of the All Transactions source mode.');
    foreach ($source->calls as $call) {
        if ($call[0] === 'metadata') $check(count($call[1]) <= 100, 'Metadata upgrade exceeded its bounded source batch.');
        if ($call[0] === 'count') $check(substr($call[1]['date_from'], 0, 7) === substr($call[1]['date_to'], 0, 7), 'Coverage verification attempted an unbounded count.');
    }
    $check(collect($source->calls)->where(0, 'read')->isEmpty(), 'Coverage discovery must not hydrate ordered report rows.');
    $sync->forceFill([
        'state' => 'running', 'phase' => 'cashagch_verification',
        'cashagch_verified_at' => null, 'rows_processed' => 100, 'expected_rows' => 100,
        'cashagch_verification' => ['basis' => 0, 'currency' => 1, 'month' => null],
        'batch_metrics' => array_fill(0, 3, ['rows' => 100, 'seconds' => 1]),
    ])->save();
    $status = $manager->status($sync);
    $check($status['fund_ids_ready'] && !$status['cashagch_ready']
        && $status['eta_seconds'] === null && $status['estimated_completion_at'] === null
        && $status['rows_per_second'] === null
        && $status['verification']['completed_scopes'] === 1
        && $status['verification']['total_scopes'] === 8
        && str_contains($status['activity_message'], 'USD')
        && str_contains($status['cashagch_status_label'], 'in progress'),
        'Completed metadata rows must not imply completed verification or a rolling zero-second ETA.');
    $check(str_contains($selector->select($criteria)['source_fallback_reason'], 'Fund/unit IDs are verified'),
        'Report fallback must distinguish verified fund IDs from incomplete historical coverage.');
    $sync->forceFill([
        'state' => 'ready', 'phase' => 'ready', 'cashagch_verified_at' => now(),
    ])->save();
    $check($manager->status($sync)['eta_label'] === 'Complete'
        && $manager->status($sync)['estimated_completion_at'] === null,
        'Completed runs must not continually move their estimated finish time.');
    echo "PASS: legacy metadata upgrade, exact CASHagch links, strict ambiguity, fractional dates and bounded all-basis historical coverage verification\n";

    $report = new VieFundCashAgchReport($source, new RuntimeSettings());
    $run = new VieFundCashAgchReportRun($source, new RuntimeSettings(), $report);
    foreach (array_keys(VieFundCashAgchReport::DATE_BASES) as $basis) {
        foreach ([['00', [6], null], ['01', [6], null], ['00', [3, 6], null], ['00', [6], '2026-09-30']] as [$currency, $statuses, $from]) {
            $selected = [...$criteria, 'date_basis' => $basis, 'currency_code' => $currency, 'status_ids' => $statuses, 'date_from' => $from];
            $path = $directory.'/'.str_replace('_', '-', $basis).'-'.$currency.'-'.count($statuses).'-'.($from ?? 'inception');
            $report->generate($selected, $path.'-remote.xlsx', static function (): void {});
            $calls = count($source->calls);
            $state = $run->load($path, $selected);
            for ($i = 0; $i < 15 && $state['phase'] !== 'ready'; ++$i) {
                $state = $run->advance($path, $path.'-mirror.xlsx', $selected, $state, static function (): void {});
            }
            $check($state['phase'] === 'ready' && $state['source']['data_source'] === 'mirror', 'Mirror report did not complete.');
            $check(count($source->calls) === $calls, 'Mirror generation must make zero VieFund calls.');
            $check($workbook($path.'-remote.xlsx') === $workbook($path.'-mirror.xlsx'), "Remote/mirror workbook mismatch: $basis, $currency, ".implode(',', $statuses));
            $summaryReader = new Reader();
            $summaryReader->open($path.'-mirror.xlsx');
            $metadata = [];
            foreach ($summaryReader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Summary') continue;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if (isset($values[1])) $metadata[$values[0]] = $values[1];
                }
            }
            $summaryReader->close();
            $check($metadata['Data Source'] === 'Verified local VieFund mirror' && $metadata['Mirror Last Data Sync'] === $state['source']['mirror_synced_at'], 'Workbook must identify its actual pinned source and last data sync.');
        }
    }
    $trade = [...$criteria, 'date_basis' => 'trade_date'];
    $tradeIds = $selector->rows($trade, null, 100)->pluck('sort_id')->all();
    $check(!in_array(14, $tradeIds, true) && count(array_intersect(range(15, 22), $tradeIds)) === 8, 'Trade-date cash availability must preserve all positive/negative/trust exceptions.');
    $month = [...$trade, 'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'availability_date_to' => '2026-10-24'];
    $check($selector->count($month) === $source->countAllTransactions(null, VieFundCashAgchReport::filters($month)), 'Monthly trade counts must keep the overall availability cutoff.');
    $check($selector->count($month) === $selector->count([...$month, 'availability_date_to' => '2026-08-31']) + 1,
        'The historical trade fixture must exercise a cash availability decision that differs from the counted month end.');
    $check($selector->rows($criteria, null, 100)->firstWhere('sort_id', 3)->fund_id === 'CASHAGCH',
        'Case-insensitive exact matching must preserve the original VieFund fund-ID spelling in the workbook.');
    echo "PASS: exact remote/mirror workbook parity for four date bases, CAD/USD, status subsets, reversals, fees, unknown/conflicting types, inclusive microsecond bounds and zero remote calls\n";

    $baseline = $sync->getAttributes();
    foreach ([
        ['state' => 'running'], ['fund_ids_verified_at' => null], ['cashagch_verified_at' => null],
        ['cashagch_scope_hash' => 'invalid'], ['synced_through_at' => now()->subHours(24)->subSecond()],
        ['synced_through_at' => now()->addSecond()], ['currencies' => ['01']], ['status_ids' => [3]],
    ] as $changes) {
        $sync->forceFill($changes)->save();
        $selection = $selector->select($criteria);
        $check($selection['data_source'] === 'viefund' && $selection['source_fallback_reason'] !== '', 'Unsuitable mirror must fall back with an explicit reason.');
        $sync->setRawAttributes($baseline)->save();
    }
    $sync->forceFill(['synced_through_at' => now()->subHours(24)])->save();
    $check($selector->select($criteria)['data_source'] === 'mirror', 'Exactly 24-hour-old data must satisfy the agreed freshness boundary.');
    $sync->setRawAttributes($baseline)->save();
    config(['viefund.balance_report_cash_account_scope.excluded_plan_accounts' => ['CHANGED']]);
    $check($selector->select($criteria)['data_source'] === 'viefund', 'Accounting-scope changes must invalidate verification.');
    config(['viefund.balance_report_cash_account_scope.excluded_plan_accounts' => []]);
    config(['viefund.balance_report_cash_account_scope.opened_before' => '2026-01-01']);
    $check($selector->select($criteria)['data_source'] === 'viefund', 'A mirror with a simulated cash-account cutoff cannot guarantee later full-history coverage.');
    config(['viefund.balance_report_cash_account_scope.opened_before' => null]);
    echo "PASS: explicit incomplete, unverified, active, changed-scope, currency/status and stale/future fallback; exact 24-hour freshness boundary\n";

    $pinned = $directory.'/pinned';
    $state = $run->advance($pinned, $pinned.'.xlsx', $criteria, $run->load($pinned, $criteria), static function (): void {});
    $check($state['processed_transactions'] > 0, 'Snapshot-change test must first save mirror transactions.');
    $calls = count($source->calls);
    $sync->forceFill(['sync_generation' => 'replacement'])->save();
    $state = $run->advance($pinned, $pinned.'.xlsx', $criteria, $state, static function (): void {});
    $check($state['source']['data_source'] === 'viefund' && $state['processed_transactions'] === 0 && $state['parts'] === []
        && count(File::glob($pinned.'/batch-*.ndjson')) === 0 && count($source->calls) === $calls,
        'Changed mirror must discard old rows and pin an explicit full remote restart before any remote reads.');
    for ($i = 0; $i < 15 && $state['phase'] !== 'ready'; ++$i) {
        $state = $run->advance($pinned, $pinned.'.xlsx', $criteria, $state, static function (): void {});
    }
    $check($state['phase'] === 'ready' && str_contains($state['source']['source_fallback_reason'], 'avoid mixing snapshots'), 'Remote restart must finish with its fallback explanation.');

    $sync->forceFill(['cashagch_verified_at' => null])->save();
    DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 23)->delete();
    $upgrade();
    $sync->refresh();
    $check($sync->cashagch_verified_at === null && str_contains($sync->cashagch_verification_error, 'coverage verification failed')
        && $selector->select($trade)['data_source'] === 'viefund', 'Missing history, including null-created rows on other bases, must fail verification explicitly.');
    $insert($source->cash[23]);
    $upgrade();
    $sync->refresh();
    $check($sync->cashagch_verified_at !== null, 'Repair and re-verification must enable mirror reporting.');
    echo "PASS: pinned-source refresh detection, clean remote restart, no mixed snapshots, incomplete-history rejection and successful re-verification\n";

    Bus::fake();
    $clock('2026-10-24 23:05:00 UTC');
    $check($availability->isBlocked(), 'Maintenance fixture is not blocked.');
    $user = new User();
    $user->id = 17;
    $request = Request::create('/reports/cashagch/run', 'POST', $criteria + ['format' => 'xlsx']);
    $request->setUserResolver(static fn() => $user);
    $controller = new VieFundCashAgchReportController();
    $runId = $controller->run($request, $availability)->getData(true)['run_id'];
    $queued = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($queued->delay === null, 'A potential mirror report must not wait for remote maintenance.');
    $calls = count($source->calls);
    for ($i = 0; $i < 15 && (Cache::get(VieFundCashAgchReport::statusKey(17, $runId), [])['success'] ?? null) !== true; ++$i) {
        $queued->handle($run, $availability);
        $queued = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    }
    $status = $controller->status($request, $runId)->getData(true);
    $check($status['success'] === true && $status['data_source'] === 'mirror' && $status['download_url'] !== null
        && count($source->calls) === $calls, 'Verified mirror generation and publication must complete during remote maintenance.');
    $cancelRun = $controller->run($request, $availability)->getData(true)['run_id'];
    $cancelJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $cancelJob->handle($run, $availability);
    $cancelRequest = Request::create('/reports/cashagch/cancel', 'POST', ['run_id' => $cancelRun]);
    $cancelRequest->setUserResolver(static fn() => $user);
    (new VieFundReportCancellationController())($cancelRequest, 'cashagch');
    Bus::dispatched(GenerateVieFundCashAgchReport::class)->last()->handle($run, $availability);
    $cancelled = $controller->status($request, $cancelRun)->getData(true);
    $check($cancelled['cancelled'] === true && $cancelled['download_url'] === null
        && !is_file(VieFundCashAgchReport::outputPath(17, $cancelRun))
        && count(File::glob(VieFundCashAgchReportRun::directory(17, $cancelRun).'/batch-*.ndjson')) === 0
        && count($source->calls) === $calls, 'Cancelling a staged mirror report must discard its rows and prevent publication without remote reads.');
    echo "PASS: controller/queue/status/source metadata, cancellation-compatible stages and complete mirror generation during VieFund maintenance\n";

    DB::table('viefund_transaction_mirror_rows')->delete();
    $template = $source->cash[1];
    for ($first = 1; $first <= 100001; $first += 250) {
        $rows = $funds = [];
        for ($id = $first; $id <= min(100001, $first + 249); ++$id) {
            $row = clone $template;
            $row->cash_transaction_id = $row->sort_id = $id;
            $row->transaction_id = 'C-'.$id;
            $row->created_date = $row->trade_date = $row->processing_date = $row->settlement_date = '2026-10-24 23:59:59.999999';
            $payload = json_encode($row, JSON_THROW_ON_ERROR);
            $rows[] = [
                'cash_transaction_id' => $id, 'transaction_id' => $row->transaction_id, 'amount' => $row->amount,
                'created_date' => $row->created_date, 'trade_date' => $row->trade_date,
                'processing_date' => $row->processing_date, 'settlement_date' => $row->settlement_date,
                'currency_code' => '00', 'cash_status_id' => 6, 'fund_id_checked_at' => now(),
                'payload' => $payload, 'source_fingerprint' => hash('sha256', $payload),
                'sync_generation' => 'benchmark', 'source_synced_at' => now(),
            ];
            $funds[] = ['cash_transaction_id' => $id, 'fund_id' => 'CASHagch', 'transaction_type' => 'Purchase', 'transaction_type_count' => 1];
        }
        DB::table('viefund_transaction_mirror_rows')->insert($rows);
        DB::table('viefund_transaction_mirror_funds')->insert($funds);
    }
    $calls = count($source->calls);
    $started = hrtime(true);
    $check($selector->count($criteria) === 100001, 'Large local count must include the exact expected number of transactions.');
    $cursor = null;
    $seen = 0;
    do {
        $rows = $selector->rows($criteria, $cursor, 1000);
        $check($rows->count() <= 1000, 'Local reads must respect the resumable batch bound.');
        foreach ($rows as $row) {
            ++$seen;
            $check($row->sort_id === $seen && $row->transaction_id === 'C-'.$seen && $row->amount === '-100.1234',
                'Same-date keyset reads must preserve exact order, IDs and decimal amounts without omissions or duplicates.');
        }
        if ($rows->isNotEmpty()) $cursor = VieFundCashAgchReport::cursor($rows->last());
    } while ($rows->isNotEmpty());
    $seconds = (hrtime(true) - $started) / 1_000_000_000;
    $check($seen === 100001 && count($source->calls) === $calls && $seconds < 30, '100,001 local transactions must stream in under 30 seconds with zero remote calls.');
    echo 'PASS: 100,001-row isolated SQLite mirror count/read benchmark, identical timestamps, bounded batches, no duplicates or remote calls: '.number_format($seconds, 2)."s\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exit = 1;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
}
exit($exit);
