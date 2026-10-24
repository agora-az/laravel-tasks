<?php

use App\Http\Controllers\VieFundCashAgchReportController;
use App\Http\Controllers\VieFundReportsController;
use App\Jobs\GenerateVieFundCashAgchReport;
use App\Models\User;
use App\Services\RuntimeSettings;
use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundRemoteService;
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
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$testDirectory = sys_get_temp_dir().'/agora-cashagch-test-'.bin2hex(random_bytes(8));
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
        'database.connections' => [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'viefund_sqlsrv' => ['driver' => 'sqlsrv', 'database' => 'report-test', 'host' => 'invalid.example.test', 'prefix' => ''],
        ],
        'cache.default' => 'array',
        'session.driver' => 'array',
        'view.compiled' => $testDirectory,
        'viefund.all_transactions_export_rows_per_sheet' => 5000,
        'viefund.all_transactions_export_batch_size' => 100,
        'viefund.all_transactions_visible_columns' => ['amount'],
        'viefund.balance_report_cash_account_scope.opened_before' => '2018-01-01',
    ]);
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    $source = new class extends VieFundRemoteService
    {
        public array $rows = [];
        public array $requests = [];
        public array $countRequests = [];
        public bool $failCount = false;
        public ?int $failOnCall = null;
        public int $pageSize = 3;
        public ?Closure $onFetch = null;
        public function __construct() {}
        public function countAllTransactions(?string $search = null, array $filters = []): int
        {
            $this->countRequests[] = $filters;
            if ($this->failCount) {
                throw new RuntimeException('Simulated VieFund count failure');
            }
            return $this->matchingRows($filters)->count();
        }
        public function fetchAllTransactionExportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
        {
            $this->requests[] = [$filters, $cursor, $limit];
            ($this->onFetch ?? static fn() => null)();
            if (count($this->requests) === $this->failOnCall) {
                throw new RuntimeException('Simulated VieFund source failure');
            }
            return $this->matchingRows($filters)
                ->sort(fn(object $a, object $b): int => ($a->basis_date <=> $b->basis_date) ?: ($a->sort_id <=> $b->sort_id))
                ->filter(fn(object $row): bool => $cursor === null
                    || $row->basis_date > $cursor['basis_date']
                    || ($row->basis_date === $cursor['basis_date'] && $row->sort_id > $cursor['sort_id']))
                ->take(min($this->pageSize, $limit))->values();
        }
        private function matchingRows(array $filters): Collection
        {
            if (config('viefund.balance_report_cash_account_scope.opened_before') !== null) {
                throw new RuntimeException('A simulated historical account cutoff must not narrow the inception report.');
            }
            $dateColumn = match ($filters['date_basis']) {
                'create_date' => 'created_date',
                'trade_date' => 'trade_date',
                'processing_date' => 'processing_date',
                default => 'settlement_date',
            };
            return collect($this->rows)
                ->filter(fn(object $row): bool => $row->fund_id === $filters['fund_id']
                    && $row->currency_code === $filters['currency_code']
                    && in_array($row->cash_status_id, $filters['status_ids'], true)
                    && $row->{$dateColumn} !== null
                    && (empty($filters['date_from']) || substr($row->{$dateColumn}, 0, 10) >= $filters['date_from'])
                    && substr($row->{$dateColumn}, 0, 10) <= $filters['date_to'])
                ->map(function (object $row) use ($dateColumn): object {
                    $copy = clone $row;
                    $copy->basis_date = $row->{$dateColumn};
                    return $copy;
                })->values();
        }
    };
    foreach ([
        ['Purchase', '-1000.1234'],
        ['Purchase PAC', '-200.1000'],
        ['Rebalancing purchase', '-50.0001'],
        ['Purchase', '25.1234'],
        ['Redemption', '500.2500'],
        ['Rebalancing redemption', '300.1000'],
        ['Redemption', '-50.0500'],
        ['Fee Redemption', '20.1234'],
        ['Fee Redemption', '-5.0001'],
        ['Automatic/Systematic', '-3.3333'],
        ['Fee', '-5.0000'],
        ['Fund TrxType', '7.0001'],
        ['Purchase', '0.0000'],
    ] as $index => [$type, $amount]) {
        $id = $index + 1;
        $date = CarbonImmutable::parse('2019-08-09')->addDays($index)->format('Y-m-d 12:00:00');
        $source->rows[] = (object) [
            'cash_transaction_id' => $id, 'transaction_id' => 'C-'.$id, 'sort_id' => $id,
            'fund_transaction_id' => 100 + $id, 'source_id' => '12345678901234567890',
            'fund_id' => 'CASHagch', 'customer_name' => '=Literal customer text', 'plan_account_id' => '00000123',
            'transaction_type' => $type, 'cash_status_id' => 6, 'status' => 'Confirmed', 'currency_code' => '00',
            'created_date' => $date, 'trade_date' => $date, 'processing_date' => $date, 'settlement_date' => $date,
            'amount' => $amount,
        ];
    }
    foreach ([
        ['fund_id', 'CASHagch-other'],
        ['currency_code', '01'],
        ['cash_status_id', 3],
        ['settlement_date', '2026-10-25 12:00:00'],
    ] as $index => [$field, $value]) {
        $row = clone $source->rows[0];
        $row->cash_transaction_id = $row->sort_id = 100 + $index;
        $row->transaction_id = 'C-'.$row->sort_id;
        $row->{$field} = $value;
        $source->rows[] = $row;
    }
    $report = new VieFundCashAgchReport($source, $app->make(RuntimeSettings::class));
    $criteria = ['date_to' => '2026-10-24', 'date_basis' => 'settlement_date', 'currency_code' => '00', 'status_ids' => [6]];
    $path = $testDirectory.'/report.xlsx';
    $progress = [];
    $result = $report->generate($criteria, $path, static function (int $count, int $total, string $phase) use (&$progress): void {
        $progress[] = [$count, $total, $phase];
    });
    $check($result === ['processed_transactions' => 13, 'review_transactions' => 3], 'Incorrect included or unclassified transaction count.');
    $check(array_column($progress, 0) === [0, 3, 6, 9, 12, 13, 13], 'Progress must cover initial counting, completed source batches and finalization.');
    $check(array_unique(array_column($progress, 1)) === [13], 'Every progress event must use the same matching transaction count.');
    $check($progress[0][2] === 'exporting' && $progress[count($progress) - 1][2] === 'finalizing', 'Workbook finalization must be a separate phase.');
    $check($source->countRequests === [$source->requests[0][0]], 'ETA counting must use precisely the same source filters as the export.');
    $check(config('viefund.balance_report_cash_account_scope.opened_before') === '2018-01-01', 'Source scope changes must be restored.');
    foreach ($source->requests as [$filters, $cursor, $limit]) {
        $check(!isset($filters['date_from']) && $filters['date_to'] === '2026-10-24', 'The source must include inception, not a recent lookback.');
        $check($filters['fund_id_exact'] === true && $filters['mirror_fields'] === true, 'Fund selection must be exact and independent of hidden All Transactions columns.');
        $check($filters['require_unambiguous_fund_type'] === true, 'Conflicting linked transaction types must not be silently assigned to a category.');
        $check(!isset($filters['has_reconciliation_match']) && !isset($filters['match_statuses']), 'Bank matches must never restrict this report.');
    }
    $reader = new Reader();
    $reader->open($path);
    $sheets = [];
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
    } finally {
        $reader->close();
    }
    $check(array_key_first($sheets) === 'Summary' && count($sheets) === 2, 'Summary must come first, followed by the transaction details.');
    $summary = [];
    foreach ($sheets['Summary'] as $row) {
        if (isset($row[0], $row[1])) $summary[(string) $row[0]] = $row[1];
    }
    foreach ([
        'Total Purchases' => '1225.1001',
        'Standard Redemptions' => '750.3000',
        'Fee Redemptions' => '15.1233',
        'Total Redemptions (Including Fees)' => '765.4233',
        'Other / Review Required Signed Amount' => '-1.3332',
        'Net Signed Transaction Amount' => '-461.0100',
    ] as $label => $expected) {
        $check(BigDecimal::of((string) $summary[$label])->isEqualTo($expected), "$label must equal $expected, including reversals and four decimal places.");
    }
    $check((int) $summary['All Redemption Transaction Count'] === 5, 'Combined redemptions must include fee transactions once.');
    $check($summary['First Included Transaction Date'] === '2019-08-09', 'History since inception must not be truncated.');
    $check(str_contains($summary['Review'], 'need') || str_contains($summary['Review'], 'Review'), 'Ambiguous types must trigger an explicit review warning.');
    $details = [];
    foreach (array_slice($sheets, 1) as $rows) {
        $header = array_shift($rows);
        $check(count($rows) <= 5000, 'Worksheet split limit must apply to every detail sheet.');
        foreach ($rows as $row) {
            $check($row[2] === '12345678901234567890' && $row[5] === '00000123', 'Identifiers must retain text precision and leading zeros.');
            $check($row[4] === '=Literal customer text', 'Source text must not be written as a formula.');
            $check(!in_array('Bank Amount', $header, true), 'No bank amounts belong in the workbook.');
            $details[] = $row;
        }
    }
    $check(count($details) === 13 && $details[0][13] === -1000.1234, 'Details must retain original signed amounts.');
    $zip = new ZipArchive();
    $check($zip->open($path) === true, 'The workbook must be a valid XLSX archive.');
    $summaryXml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close();
    $labelColumn = $summaryXml->xpath('//*[local-name()="col"][@min="1"]')[0];
    $valueColumn = $summaryXml->xpath('//*[local-name()="col"][@min="2"]')[0];
    $check((float) $labelColumn['width'] >= 46 && (float) $valueColumn['width'] >= 30, 'Summary labels and monetary totals must be readable without resizing columns.');
    echo "PASS: inception, exact fund/currency/status/end date, separate purchase/redemption/fee totals, reversals, review types and text precision\n";

    foreach (array_keys(VieFundCashAgchReport::DATE_BASES) as $basis) {
        $rangeCriteria = [...$criteria, 'date_from' => '2019-08-13', 'date_to' => '2019-08-17', 'date_basis' => $basis];
        $rangeProgress = [];
        $rangeResult = $report->generate($rangeCriteria, $testDirectory.'/range.xlsx', static function (int $count, int $total, string $phase) use (&$rangeProgress): void {
            $rangeProgress[] = [$count, $total, $phase];
        });
        $check($rangeResult === ['processed_transactions' => 5, 'review_transactions' => 0], 'A custom date range must exclude earlier/later transactions for every date basis.');
        $check(array_unique(array_column($rangeProgress, 1)) === [5], 'ETA counts must use the custom start date, not the inception total.');
        $reader->open($testDirectory.'/range.xlsx');
        $rangeSummary = [];
        $rangeDetails = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if ($sheet->getName() === 'Summary') {
                        if (isset($values[0], $values[1])) $rangeSummary[(string) $values[0]] = $values[1];
                    } else {
                        $rangeDetails[] = $values;
                    }
                }
            }
        } finally {
            $reader->close();
        }
        $check($rangeSummary['Period'] === '2019-08-13 through 2019-08-17'
            && $rangeSummary['First Included Transaction Date'] === '2019-08-13'
            && $rangeSummary['Last Included Transaction Date'] === '2019-08-17', 'Summary metadata must show the actual inclusive selected range.');
        $check((float) $rangeSummary['Total Purchases'] === 0.0
            && BigDecimal::of((string) $rangeSummary['Total Redemptions (Including Fees)'])->isEqualTo('765.4233'), 'Custom-range totals must cover only included cash amounts.');
        $check(count($rangeDetails) === 6 && $rangeDetails[1][0] === 'C-5' && $rangeDetails[5][0] === 'C-9', 'Only the five selected transactions and one header belong in range details.');
    }
    echo "PASS: optional custom start date, inclusive range details, Summary metadata/totals and matching ETA counts for all four date bases\n";

    foreach (range(14, 5001) as $id) {
        $row = clone $source->rows[12];
        $row->cash_transaction_id = $row->sort_id = $id;
        $row->transaction_id = 'C-'.$id;
        $row->amount = $id === 5001 ? '-0.0001' : '0.0000';
        $source->rows[] = $row;
    }
    $source->pageSize = 100;
    $split = $report->generate($criteria, $testDirectory.'/split.xlsx', static fn(int $count) => null);
    $check($split['processed_transactions'] === 5001, 'The split report must export every transaction once.');
    $reader->open($testDirectory.'/split.xlsx');
    $detailCounts = [];
    $splitSummary = [];
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = iterator_to_array($sheet->getRowIterator());
            if ($sheet->getName() === 'Summary') {
                foreach ($rows as $row) {
                    $values = $row->toArray();
                    if (isset($values[0], $values[1])) $splitSummary[(string) $values[0]] = $values[1];
                }
            } else {
                $detailCounts[] = count($rows) - 1;
            }
        }
    } finally {
        $reader->close();
    }
    $check($detailCounts === [5000, 1], 'The transaction after the configured worksheet limit must move to the next sheet.');
    $check(BigDecimal::of((string) $splitSummary['Total Purchases'])->isEqualTo('1225.1002'), 'Summary totals must include amounts on later detail sheets.');
    echo "PASS: exact 5,000-row worksheet boundary, next-sheet overflow and complete-result Summary totals\n";

    $source->rows = [];
    $empty = $report->generate($criteria, $testDirectory.'/empty.xlsx', static fn(int $count) => null);
    $check($empty['processed_transactions'] === 0, 'An empty report must complete with zero transactions.');
    $reader->open($testDirectory.'/empty.xlsx');
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = iterator_to_array($sheet->getRowIterator());
            if ($sheet->getName() === 'Transactions 1') {
                $check(count($rows) === 1, 'An empty result still needs a usable detail header.');
            } else {
                foreach ($rows as $row) {
                    $values = $row->toArray();
                    if (($values[0] ?? null) === 'Total Purchases') {
                        $check((float) $values[1] === 0.0, 'An empty Summary must show zero purchases.');
                    }
                }
            }
        }
    } finally {
        $reader->close();
    }
    $source->rows = [(object) [
        'fund_id' => 'CASHagch', 'cash_status_id' => 6, 'currency_code' => '00',
        'settlement_date' => '2019-08-09 12:00:00', 'sort_id' => 1, 'transaction_id' => 'C-1',
        'plan_account_id' => '00000123', 'transaction_type' => 'Purchase', 'amount' => '-0.0001',
    ]];
    $source->requests = [];
    $source->failOnCall = 2;
    try {
        $report->generate($criteria, $testDirectory.'/failed.xlsx', static fn(int $count) => null);
        throw new RuntimeException('Source failures must not produce a successful report.');
    } catch (RuntimeException $exception) {
        $check($exception->getMessage() === 'Simulated VieFund source failure', $exception->getMessage());
        $check(!is_file($testDirectory.'/failed.xlsx'), 'A failed report must remove partial output.');
    }
    $source->failOnCall = null;
    $source->failCount = true;
    try {
        $report->generate($criteria, $testDirectory.'/failed-count.xlsx', static fn(int $count) => null);
        throw new RuntimeException('Count failures must be surfaced, not replaced with a guessed ETA.');
    } catch (RuntimeException $exception) {
        $check($exception->getMessage() === 'Simulated VieFund count failure', $exception->getMessage());
        $check(!is_file($testDirectory.'/failed-count.xlsx'), 'A failed initial count must not leave a workbook.');
        $check(config('viefund.balance_report_cash_account_scope.opened_before') === '2018-01-01', 'A failed count must restore the original account scope.');
    }
    $source->failCount = false;
    echo "PASS: empty reports, explicit source failures and partial-workbook cleanup\n";

    $queryFilters = ['fund_id' => 'CASHagch', 'fund_id_exact' => true, 'require_unambiguous_fund_type' => true, 'mirror_fields' => true, ...$criteria];
    config(['viefund.balance_report_cash_account_scope.opened_before' => null]);
    $connection = DB::connection('viefund_sqlsrv');
    $connection->setPdo(DB::connection('sqlite')->getPdo());
    $connection->setReadPdo(DB::connection('sqlite')->getPdo());
    $queries = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)
        ->fetchAllTransactionExportRowsAfter(null, $queryFilters, null, 100));
    $sql = $queries[0]['query'];
    $check(str_contains($sql, "[fm_l].[FundID] = 'CASHagch'") && in_array('CASHagch', $queries[0]['bindings'], true), 'The actual SQL source query must use exact FundID matching.');
    $check(str_contains($sql, 'ct.mAmount AS amount') && !str_contains($sql, 'UB_EFTItem'), 'The actual SQL amount source must be the cash ledger without an EFT/bank match.');
    $check(str_contains($sql, "[ct].[dtSettlement] < '2026-10-25 00:00:00'"), 'The full selected end date must include fractional seconds before the next midnight.');
    $check(str_contains($sql, 'COUNT(DISTINCT') && str_contains($sql, 'Multiple fund transaction types'), 'The source must flag conflicting linked types while counting each cash row once.');
    $countQueries = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)->countAllTransactions(null, $queryFilters));
    $check(str_contains($countQueries[0]['query'], 'count(*) as aggregate')
        && str_contains($countQueries[0]['query'], "[fm_l].[FundID] = 'CASHagch'")
        && !str_contains($countQueries[0]['query'], 'UB_EFTItem'), 'The real ETA count must count cash rows with the same exact-fund scope and no bank requirement.');
    foreach ([
        'create_date' => 'dtCreated', 'trade_date' => 'dtTrade',
        'processing_date' => 'dtProcessing', 'settlement_date' => 'dtSettlement',
    ] as $basis => $column) {
        $filters = [...$queryFilters, 'date_from' => '2019-08-13', 'date_to' => '2019-08-17', 'date_basis' => $basis];
        $rangeQueries = $connection->pretend(function () use ($app, $filters): void {
            $repository = $app->make(SqlServerVieFundRemoteRepository::class);
            $repository->countAllTransactions(null, $filters);
            $repository->fetchAllTransactionExportRowsAfter(null, $filters, null, 100);
        });
        foreach ($rangeQueries as $query) {
            $check(str_contains($query['query'], "[ct].[{$column}] >= '2019-08-13 00:00:00'")
                && str_contains($query['query'], "[ct].[{$column}] < '2019-08-18 00:00:00'"), 'Actual count and export SQL must share inclusive start/end day boundaries for every date basis.');
        }
    }
    $existingQueries = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)
        ->fetchAllTransactionExportRowsAfter(null, ['fund_id' => 'CASHagch', 'mirror_fields' => true, ...$criteria], null, 100));
    $check(str_contains($existingQueries[0]['query'], "[fm_l].[FundID] like '%CASHagch%'")
        && !str_contains($existingQueries[0]['query'], 'Multiple fund transaction types'), 'Existing All Transactions contains-filter and representative-type behavior must remain unchanged.');
    $candidate = new ReflectionMethod(SqlServerVieFundRemoteRepository::class, 'buildAllTransactionPageCandidateQuery');
    $candidateQuery = $candidate->invoke($app->make(SqlServerVieFundRemoteRepository::class), $criteria);
    $check(str_contains($candidateQuery->toSql(), '[ct].[dtSettlement] < ?')
        && in_array('2026-10-25 00:00:00', $candidateQuery->getBindings(), true), 'Fast and enriched cash queries must share the same full-day boundary.');
    echo "PASS: real repository SQL uses exact FundID and cash transaction amounts, with no bank dependency\n";

    Bus::fake([GenerateVieFundCashAgchReport::class]);
    $user = (new User())->forceFill(['id' => 17, 'email' => 'report@example.test']);
    $request = Request::create('/reports/cashagch/run', 'POST', ['date_to' => '2026-10-24', 'date_basis' => 'settlement_date', 'currency_code' => '00', 'status_ids' => [6]]);
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(static fn() => $user);
    $controller = new VieFundCashAgchReportController();
    $response = $controller->run($request, $app->make(VieFundAvailabilityWindow::class));
    $check($response->getStatusCode() === 202, 'HTTP run endpoint must queue rather than block the request.');
    $runId = $response->getData(true)['run_id'];
    $job = Bus::dispatched(GenerateVieFundCashAgchReport::class)->first();
    $check($job instanceof GenerateVieFundCashAgchReport && $job->userId === 17 && $job->connection === 'database', 'Report must use the existing general database queue with the requesting owner.');
    $availability = $app->make(VieFundAvailabilityWindow::class);
    $job->handle($report, $availability);
    $status = $controller->status($request, $runId)->getData(true);
    $check($status['success'] === true && $status['download_url'] !== null, 'Completed queued job must expose its download.');
    $check($status['criteria']['date_from'] === null, 'A missing start date must preserve the inception default for old clients.');
    $download = $controller->download($request, $runId);
    $check($download->getFile()->getPathname() === VieFundCashAgchReport::outputPath(17, $runId), 'Download must refer to this user and run only.');
    $otherRequest = Request::create('/reports/cashagch/'.$runId.'/status');
    $otherRequest->setUserResolver(static fn() => (new User())->forceFill(['id' => 18]));
    foreach (['status', 'download'] as $action) {
        try {
            $controller->{$action}($otherRequest, $runId);
            throw new RuntimeException('Another user must not access this report.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $check($exception->getStatusCode() === 404, 'Report access must be scoped to the requesting user.');
        }
    }
    $job->failed(new RuntimeException('Simulated worker failure'));
    $check(Cache::get(VieFundCashAgchReport::statusKey(17, $runId))['success'] === false, 'Worker failures must be visible to the user.');
    $rangeRequest = Request::create('/reports/cashagch/run', 'POST', [...$criteria, 'date_from' => '2019-08-09', 'date_to' => '2019-08-09']);
    $rangeRequest->headers->set('Accept', 'application/json');
    $rangeRequest->setUserResolver(static fn() => $user);
    $rangeRunId = $controller->run($rangeRequest, $availability)->getData(true)['run_id'];
    $rangeJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($rangeJob->criteria['date_from'] === '2019-08-09', 'Controller must persist the requested start date in the queued criteria.');
    $rangeJob->handle($report, $availability);
    $rangeDownload = $controller->download($rangeRequest, $rangeRunId);
    $check(str_contains($rangeDownload->headers->get('Content-Disposition'), 'CASHagch-2019-08-09-to-2019-08-09.xlsx'), 'Custom-range download names must not claim since inception.');
    $timedRunId = $controller->run($request, $availability)->getData(true)['run_id'];
    $timedJob = new class(17, $timedRunId, $criteria) extends GenerateVieFundCashAgchReport
    {
        public float $clock = 1000;
        protected function monotonicSeconds(): float { return $this->clock; }
    };
    $timedReport = new class extends VieFundCashAgchReport
    {
        public Closure $simulate;
        public function __construct() {}
        public function generate(array $criteria, string $path, callable $progress): array { return ($this->simulate)($progress); }
    };
    $timedKey = VieFundCashAgchReport::statusKey(17, $timedRunId);
    $timedReport->simulate = static function (callable $progress) use ($timedJob, $timedKey, $check, $controller, $request, $timedRunId): array {
        $check(Cache::get($timedKey)['phase'] === 'counting' && Cache::get($timedKey)['eta_seconds'] === null, 'Counting must not publish a guessed ETA.');
        $timedJob->clock = 1600;
        $progress(0, 1000, 'exporting');
        $timedJob->clock = 1610;
        $progress(100, 1000, 'exporting');
        $check(Cache::get($timedKey)['eta_seconds'] === null, 'A single completed batch must remain in ETA warm-up.');
        $timedJob->clock = 1620;
        $progress(200, 1000, 'exporting');
        $status = Cache::get($timedKey);
        $check($status['rows_per_second'] === 10.0 && $status['eta_seconds'] === 80, '200 rows in 20 seconds with 800 left must estimate exactly 80 seconds; exclude counting/queue time.');
        $completion = $controller->status($request, $timedRunId)->getData(true)['estimated_completion_at'];
        $check(CarbonImmutable::parse($completion)->equalTo(CarbonImmutable::now()->addSeconds(80)), 'Estimated completion must be based on the measured remaining time.');
        Carbon::setTestNow('2026-10-24 12:00:15 UTC');
        CarbonImmutable::setTestNow('2026-10-24 12:00:15 UTC');
        $check($controller->status($request, $timedRunId)->getData(true)['estimated_completion_at'] === $completion, 'Polling alone must not move the estimated completion timestamp.');
        $timedJob->clock = 1640;
        $progress(300, 1000, 'exporting');
        $check(Cache::get($timedKey)['eta_seconds'] === 94, 'Slower measured throughput must update ETA using ceil(700 / 7.5).');
        $progress(1200, 1000, 'exporting');
        $check(Cache::get($timedKey)['eta_seconds'] === null, 'A changing source count must not cause negative or zero-second ETAs.');
        $progress(1200, 1000, 'finalizing');
        $status = $controller->status($request, $timedRunId)->getData(true);
        $check($status['phase'] === 'finalizing' && $status['eta_seconds'] === null && $status['estimated_completion_at'] === null, 'Finalization must clear the read ETA.');
        $check($status['success'] === null && $status['download_url'] === null, 'Finalizing must not expose a premature download.');
        return ['processed_transactions' => 1200, 'review_transactions' => 0];
    };
    $timedJob->handle($timedReport, $availability);
    $timedStatus = $controller->status($request, $timedRunId)->getData(true);
    $check($timedStatus['phase'] === 'completed' && $timedStatus['expected_transactions'] === 1200
        && $timedStatus['eta_seconds'] === null && $timedStatus['estimated_completion_at'] === null, 'Completion must settle on actual row counts without a moving ETA.');
    Cache::put($timedKey, [...$timedStatus, 'eta_seconds' => 80, 'estimated_completion_at' => now()->addSeconds(80)->toIso8601String()]);
    $timedJob->failed(new RuntimeException('Simulated failure after ETA'));
    $check(Cache::get($timedKey)['eta_seconds'] === null && Cache::get($timedKey)['estimated_completion_at'] === null, 'Failures must clear every ETA field.');
    $timedReport->simulate = static function (callable $progress) use ($timedKey, $check): array {
        $progress(0, 0, 'exporting');
        $progress(1, 0, 'exporting');
        $progress(2, 0, 'exporting');
        $check(Cache::get($timedKey)['eta_seconds'] === null && Cache::get($timedKey)['rows_per_second'] === null, 'Zero elapsed time and a zero initial count must not divide by zero or invent an ETA.');
        $progress(2, 0, 'finalizing');
        return ['processed_transactions' => 2, 'review_transactions' => 0];
    };
    $timedJob->handle($timedReport, $availability);
    $check(Cache::get($timedKey)['expected_transactions'] === 2, 'Rows added after an initial zero count must still be exported and reflected in final counts.');
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    echo "PASS: count/export parity, exact measured ETA, warm-up, queue/count exclusion, stable polling timestamps, changing totals, zero time and terminal-state clearing\n";
    foreach ([
        ['date_from', '2026-10-25'],
        ['date_from', 'not-a-date'],
        ['date_to', '2026-10-25'],
        ['date_basis', 'unknown'],
        ['currency_code', '02'],
        ['status_ids', []],
        ['status_ids', [7]],
    ] as [$field, $invalidValue]) {
        $invalid = Request::create('/reports/cashagch/run', 'POST', [...$criteria, $field => $invalidValue]);
        $invalid->headers->set('Accept', 'application/json');
        $invalid->setUserResolver(static fn() => $user);
        try {
            $controller->run($invalid, $availability);
            throw new RuntimeException('Invalid report criteria must not dispatch a job.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $check($exception->errors() !== [], 'Invalid criteria must include explicit validation errors.');
        }
    }
    $sourceCalls = count($source->requests);
    Carbon::setTestNow('2026-10-24 23:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 23:00:00 UTC');
    $maintenanceRunId = $controller->run($request, $availability)->getData(true)['run_id'];
    $maintenanceJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($maintenanceJob->delay->equalTo($availability->nextAvailableAt()), 'Submissions during maintenance must be delayed until the source is available.');
    $maintenanceJob->handle($report, $availability);
    $check(count($source->requests) === $sourceCalls, 'A delayed worker that starts during maintenance must not query VieFund.');
    $requeued = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($requeued !== $maintenanceJob && $requeued->runId === $maintenanceRunId, 'Maintenance deferral must retain the same user-facing run.');
    Carbon::setTestNow('2026-10-24 23:26:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 23:26:00 UTC');
    $requeued->handle($report, $availability);
    $check($controller->status($request, $maintenanceRunId)->getData(true)['success'] === true, 'A maintenance-delayed report must complete after the window.');
    Carbon::setTestNow('2026-10-24 22:54:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 22:54:00 UTC');
    $interruptedRunId = $controller->run($request, $availability)->getData(true)['run_id'];
    $interruptedJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $source->onFetch = static function (): void {
        Carbon::setTestNow('2026-10-24 22:55:00 UTC');
        CarbonImmutable::setTestNow('2026-10-24 22:55:00 UTC');
    };
    try {
        $interruptedJob->handle($report, $availability);
        throw new RuntimeException('A report interrupted by maintenance must not publish partial results.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'maintenance began'), 'Maintenance interruption must give a meaningful error.');
        $interruptedJob->failed($exception);
        $check(!is_file(VieFundCashAgchReport::outputPath(17, $interruptedRunId)), 'Maintenance interruption must remove partial output.');
        $check($controller->status($request, $interruptedRunId)->getData(true)['download_url'] === null, 'Partial reports must not be downloadable.');
    }
    $source->onFetch = null;
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    foreach (['run', 'status', 'download'] as $action) {
        $route = $app['router']->getRoutes()->getByName('reports.cashagch.'.$action);
        $check($route !== null && in_array('auth.check', $route->gatherMiddleware(), true), 'Every new report route must require authentication.');
    }
    $app['auth']->guard()->setUser($user);
    $html = view('reports.cashagch', ['dateBasisOptions' => VieFundCashAgchReport::DATE_BASES, 'fundStatusOptions' => VieFundReportsController::FUND_STATUS_OPTIONS])->render();
    $check(str_contains($html, 'Generate Excel Report') && str_contains($html, 'Use Inception Date')
        && str_contains($html, 'name="date_from"'), 'Reports page must expose optional start-date and inception controls.');
    echo "PASS: queue lifecycle, progress/download, owner isolation, explicit worker failure, authenticated routes and rendered report form\n";
    echo "PASS: criteria validation, maintenance submission/start deferral, post-maintenance completion and partial-report rejection\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exitCode = 1;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    $app->make('files')->deleteDirectory($testDirectory);
}
exit($exitCode);
