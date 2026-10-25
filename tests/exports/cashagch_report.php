<?php

use App\Http\Controllers\VieFundCashAgchReportController;
use App\Http\Controllers\VieFundReportsController;
use App\Jobs\GenerateVieFundCashAgchReport;
use App\Models\User;
use App\Services\RuntimeSettings;
use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundCashAgchReportRun;
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
use PhpOffice\PhpSpreadsheet\IOFactory;

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
$readWorkbook = static function (string $path): array {
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

    return $sheets;
};
$verifyMonthly = static function (array $sheets) use ($check): array {
    $detailNames = array_values(array_filter(array_keys($sheets), static fn(string $name): bool => str_starts_with($name, 'Transactions ')));
    $check(($detailNames[0] ?? null) === 'Transactions 1'
        && array_keys($sheets) === [...$detailNames, 'Summary', 'Monthly Summary'], 'All transaction sheets must come first, followed by Summary and Monthly Summary.');
    $columns = [
        'Total Purchases', 'Standard Redemptions', 'Fee Redemptions',
        'Total Redemptions (Including Fees)', 'Other / Review Required Signed Amount',
        'Net Signed Transaction Amount', 'Total Cash Transactions', 'Purchase Transaction Count',
        'Standard Redemption Transaction Count', 'Fee Redemption Transaction Count',
        'All Redemption Transaction Count', 'Other / Review Required Transaction Count',
    ];
    $check($sheets['Monthly Summary'][0] === [
        'Month', 'Purchases (In)', 'Standard Redemptions (Out)', 'Fee Redemptions (Out)',
        'Total Redemptions (Out, Including Fees)', 'Other / Review Required Signed Amount',
        'Net Signed Transaction Amount', 'Total Cash Transactions', 'Purchase Transaction Count',
        'Standard Redemption Transaction Count', 'Fee Redemption Transaction Count',
        'All Redemption Transaction Count', 'Other / Review Required Transaction Count',
    ], 'Monthly headers must distinguish purchases, both redemption types, review amounts, net signed cash and counts.');
    $months = [];
    $grandTotal = null;
    foreach (array_slice($sheets['Monthly Summary'], 1) as $row) {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $row[0])) {
            $check(!isset($months[$row[0]]) && count($row) === 13, 'Each month must have exactly one complete summary row.');
            $check(BigDecimal::of((string) $row[4])->minus((string) $row[1])->plus((string) $row[5])->isEqualTo((string) $row[6]), 'Every month must preserve the signed cash accounting identity.');
            $check($row[7] === $row[8] + $row[9] + $row[10] + $row[12]
                && $row[11] === $row[9] + $row[10], 'Monthly counts must include each transaction once and combine fee redemptions.');
            $months[$row[0]] = $row;
        } elseif ($row[0] === 'Grand Total') {
            $check($grandTotal === null && count($row) === 13, 'Monthly Summary needs exactly one complete Grand Total row.');
            $grandTotal = $row;
        }
    }
    $check($grandTotal !== null, 'Monthly Summary must include a Grand Total row.');
    $summary = array_column($sheets['Summary'], 1, 0);
    foreach ($columns as $index => $label) {
        $sum = BigDecimal::zero();
        foreach ($months as $row) {
            $check(is_numeric($row[$index + 1]), 'Monthly amounts and counts must be numeric cells.');
            $sum = $sum->plus((string) $row[$index + 1]);
        }
        $check($sum->isEqualTo((string) $grandTotal[$index + 1])
            && $sum->isEqualTo((string) $summary[$label]), "$label must reconcile across all months, the Grand Total and the overall Summary.");
    }

    return $months;
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
    $sheets = $readWorkbook($path);
    $months = $verifyMonthly($sheets);
    $check(count($months) === 87 && array_key_first($months) === '2019-08'
        && array_key_last($months) === '2026-10', 'Inception reporting must include every month through the selected end month.');
    foreach ($months as $month => $values) {
        if ($month !== '2019-08') {
            $check(array_slice($values, 1) === array_fill(0, 12, 0), 'Months without matching transactions must contain numeric zeros.');
        }
    }
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
    foreach ($sheets as $name => $rows) {
        if (!str_starts_with($name, 'Transactions ')) continue;
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
    $summaryXml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet2.xml'));
    $zip->close();
    $labelColumn = $summaryXml->xpath('//*[local-name()="col"][@min="1"]')[0];
    $valueColumn = $summaryXml->xpath('//*[local-name()="col"][@min="2"]')[0];
    $check((float) $labelColumn['width'] >= 46 && (float) $valueColumn['width'] >= 30, 'Summary labels and monetary totals must be readable without resizing columns.');
    echo "PASS: inception, exact fund/currency/status/end date, separate purchase/redemption/fee totals, reversals, review types and text precision\n";

    $accountingFormat = '$#,##0.00;[Red]($#,##0.00);$0.00';
    $formattedTotals = [
        'Total Purchases' => '$1,225.10',
        'Standard Redemptions' => '$750.30',
        'Fee Redemptions' => '$15.12',
        'Total Redemptions (Including Fees)' => '$765.42',
        'Other / Review Required Signed Amount' => '($1.33)',
        'Net Signed Transaction Amount' => '($461.01)',
    ];
    $formattedWorkbook = IOFactory::load($path);
    try {
        $summarySheet = $formattedWorkbook->getSheetByName('Summary');
        $detailSheet = $formattedWorkbook->getSheetByName('Transactions 1');
        $monthlySheet = $formattedWorkbook->getSheetByName('Monthly Summary');
        $check($summarySheet !== null && $detailSheet !== null && $monthlySheet !== null, 'Formatting checks require all three workbook sheets.');
        $check($formattedWorkbook->getActiveSheetIndex() === 0 && $formattedWorkbook->getActiveSheet()->getTitle() === 'Transactions 1', 'The workbook must open on its first transaction sheet.');
        $check($monthlySheet->getRowDimension(1)->getRowHeight() >= 30
            && $monthlySheet->getStyle('E1')->getAlignment()->getWrapText(), 'Monthly headers must wrap with sufficient height to remain readable.');
        foreach ($sheets['Summary'] as $index => $row) {
            $cell = $summarySheet->getCell('B'.($index + 1));
            if (isset($formattedTotals[$row[0]])) {
                $check($cell->getStyle()->getNumberFormat()->getFormatCode() === $accountingFormat, $row[0].' must use dollar accounting formatting.');
                $check($cell->getFormattedValue() === $formattedTotals[$row[0]], $row[0].' must display dollars, grouped thousands, two decimals and parenthesized negatives.');
                $check(is_numeric($cell->getValue()), 'Summary amounts must remain numeric, not formatted text.');
            } elseif ($row[0] === 'Total Cash Transactions' || str_contains($row[0], 'Transaction Count')) {
                $check($cell->getStyle()->getNumberFormat()->getFormatCode() === 'General', 'Transaction counts must not receive monetary formatting.');
            }
        }
        foreach ($details as $index => $row) {
            $cell = $detailSheet->getCell('N'.($index + 2));
            $check($cell->getStyle()->getNumberFormat()->getFormatCode() === $accountingFormat, 'Every signed transaction amount must use dollar accounting formatting.');
            $check($cell->getValue() === $row[13], 'Accounting display must not round or otherwise change the original signed transaction value.');
        }
        $check($detailSheet->getCell('N2')->getFormattedValue() === '($1,000.12)', 'Negative detail amounts must display dollars, grouped thousands and parentheses.');
        $check($detailSheet->getCell('N5')->getFormattedValue() === '$25.12', 'Positive detail amounts must display dollars and two decimals.');
        $check($detailSheet->getCell('N14')->getFormattedValue() === '$0.00', 'Zero detail amounts must display as monetary values.');
        foreach ($sheets['Monthly Summary'] as $index => $row) {
            if (!isset($months[$row[0]]) && $row[0] !== 'Grand Total') continue;
            foreach (range('B', 'G') as $column) {
                $cell = $monthlySheet->getCell($column.($index + 1));
                $check($cell->getStyle()->getNumberFormat()->getFormatCode() === $accountingFormat, 'Every monthly amount, including zero and Grand Total values, must use dollar accounting formatting.');
                $check(is_numeric($cell->getValue()), 'Monthly monetary values must remain numeric.');
            }
            foreach (range('H', 'M') as $column) {
                $check($monthlySheet->getCell($column.($index + 1))->getStyle()->getNumberFormat()->getFormatCode() === 'General', 'Monthly counts must not receive monetary formatting.');
            }
        }
        $check($monthlySheet->getCell('B2')->getFormattedValue() === '$1,225.10'
            && $monthlySheet->getCell('G2')->getFormattedValue() === '($461.01)'
            && $monthlySheet->getCell('B3')->getFormattedValue() === '$0.00', 'Monthly positive, negative and zero monetary displays must use accounting formatting.');
    } finally {
        $formattedWorkbook->disconnectWorksheets();
    }
    echo "PASS: transaction-first sheet order, monthly reconciliation, dollar accounting formats throughout, numeric precision, negative/positive/zero displays and non-monetary counts\n";

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
                    } elseif (str_starts_with($sheet->getName(), 'Transactions ')) {
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
        $rangeMonths = $verifyMonthly($readWorkbook($testDirectory.'/range.xlsx'));
        $check(array_keys($rangeMonths) === ['2019-08'], 'Partial-month reports must not add months outside the selected range.');
    }
    echo "PASS: optional custom start date, inclusive range details, Summary metadata/totals and matching ETA counts for all four date bases\n";

    $monthlySource = clone $source;
    $monthlySource->rows = [];
    $monthlySource->pageSize = 2;
    foreach ([
        ['Purchase', '-100.1234', ['2025-12-15 00:00:00', '2026-01-01', '2026-03-01', '2026-04-15 23:59:59.999999']],
        ['Redemption', '40.2500', ['2025-12-31 23:59:59.999999', '2026-01-31', '2026-03-31', '2026-04-15']],
        ['Fee Redemption', '3.3333', ['2026-01-01', '2026-03-01', '2026-04-01', '2026-04-15']],
        ['Purchase', '20.0001', ['2026-03-31', '2026-04-01', '2026-04-15', '2026-04-15']],
        ['Redemption', '-5.0001', ['2026-04-15 23:59:59.999999', '2025-12-15', '2025-12-31', '2026-04-15']],
        ['Fee Redemption', '-0.3333', ['2026-04-15', '2025-12-16', '2025-12-31', '2026-04-15']],
        ['Automatic/Systematic', '-2.1111', ['2026-01-15', '2026-03-15', '2026-03-31', '2026-04-15']],
        ['Unknown type', '0.0001', ['2026-03-01', '2026-04-15', '2026-04-15', '2026-04-15']],
        ['Purchase', '-9999', array_fill(0, 4, '2025-12-14 23:59:59.999999')],
        ['Redemption', '9999', array_fill(0, 4, '2026-04-16 00:00:00')],
    ] as $index => [$type, $amount, $dates]) {
        $row = clone $source->rows[0];
        $row->cash_transaction_id = $row->sort_id = 1000 + $index;
        $row->transaction_id = 'M-'.$index;
        $row->transaction_type = $type;
        $row->amount = $amount;
        [$row->created_date, $row->trade_date, $row->processing_date, $row->settlement_date] = $dates;
        $monthlySource->rows[] = $row;
    }
    $monthlyReport = new VieFundCashAgchReport($monthlySource, $app->make(RuntimeSettings::class));
    $monthlyCriteria = [...$criteria, 'date_from' => '2025-12-15', 'date_to' => '2026-04-15'];
    foreach ([
        'create_date' => ['2025-12' => '100.1234', '2026-03' => '-20.0001'],
        'trade_date' => ['2026-01' => '100.1234', '2026-04' => '-20.0001'],
        'processing_date' => ['2026-03' => '100.1234', '2026-04' => '-20.0001'],
        'settlement_date' => ['2026-04' => '80.1233'],
    ] as $basis => $expectedPurchases) {
        $monthlySource->requests = $monthlySource->countRequests = [];
        $monthlyResult = $monthlyReport->generate([...$monthlyCriteria, 'date_basis' => $basis], $testDirectory.'/monthly.xlsx', static fn(int $count) => null);
        $monthlySheets = $readWorkbook($testDirectory.'/monthly.xlsx');
        $monthlyRows = $verifyMonthly($monthlySheets);
        $monthlyMetadata = array_column($monthlySheets['Monthly Summary'], 1, 0);
        $check($monthlyMetadata['Period'] === '2025-12-15 through 2026-04-15'
            && $monthlyMetadata['Date Basis'] === VieFundCashAgchReport::DATE_BASES[$basis]
            && $monthlyMetadata['Currency'] === 'CAD' && $monthlyMetadata['Cash Status IDs'] === '6'
            && $monthlyMetadata['Fund / Unit ID'] === 'CASHagch', 'Monthly metadata must identify the exact report scope.');
        $check($monthlyResult === ['processed_transactions' => 8, 'review_transactions' => 2], 'Monthly grouping must retain the exact date scope, including fractional end-of-day amounts.');
        $check(count($monthlySource->countRequests) === 1 && count($monthlySource->requests) === 5, 'Monthly summaries must reuse the source stream, not run additional count or transaction queries.');
        $check(array_keys($monthlyRows) === ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04'], 'Monthly rows must cross the year boundary in order, retain empty months, and include both partial boundary months.');
        foreach ($monthlyRows as $month => $row) {
            $check(BigDecimal::of((string) $row[1])->isEqualTo($expectedPurchases[$month] ?? '0'), 'Purchases and later reversals must use their selected date-basis month, not cash direction or another date.');
        }
        $check(array_slice($monthlyRows['2026-02'], 1) === array_fill(0, 12, 0), 'The missing February must contain zero amounts and counts.');
        $monthlySummary = array_column($monthlySheets['Summary'], 1, 0);
        foreach ([
            'Total Purchases' => '80.1233', 'Standard Redemptions' => '35.2499',
            'Fee Redemptions' => '3', 'Total Redemptions (Including Fees)' => '38.2499',
            'Other / Review Required Signed Amount' => '-2.1110', 'Net Signed Transaction Amount' => '-43.9844',
        ] as $label => $value) {
            $check(BigDecimal::of((string) $monthlySummary[$label])->isEqualTo($value), 'Multi-month totals must retain exact signed amounts and reversals.');
        }
        if ($basis === 'create_date') {
            $check(BigDecimal::of((string) $monthlyRows['2026-01'][3])->isEqualTo('3.3333')
                && BigDecimal::of((string) $monthlyRows['2026-04'][2])->isEqualTo('-5.0001')
                && BigDecimal::of((string) $monthlyRows['2026-04'][3])->isEqualTo('-0.3333')
                && BigDecimal::of((string) $monthlyRows['2026-03'][5])->isEqualTo('0.0001'), 'Fee, standard-redemption and review amounts must be assigned to their own months, including reversals.');
        }
    }
    $monthlySource->rows = [clone $monthlySource->rows[0]];
    foreach (['created_date', 'trade_date', 'processing_date', 'settlement_date'] as $field) {
        $monthlySource->rows[0]->{$field} = '2024-02-29 23:59:59.999999';
    }
    $monthlySource->rows[0]->amount = '-0.0001';
    $monthlyReport->generate([...$criteria, 'date_from' => '2024-01-31', 'date_to' => '2024-03-01'], $testDirectory.'/leap.xlsx', static fn(int $count) => null);
    $leapMonths = $verifyMonthly($readWorkbook($testDirectory.'/leap.xlsx'));
    $check(array_keys($leapMonths) === ['2024-01', '2024-02', '2024-03']
        && BigDecimal::of((string) $leapMonths['2024-02'][1])->isEqualTo('0.0001'), 'Month iteration must retain leap day and not skip months after a 31st-day start.');
    $monthlyReport->generate([...$criteria, 'date_from' => '', 'date_to' => '2024-03-01'], $testDirectory.'/blank-start.xlsx', static fn(int $count) => null);
    $check(array_keys($verifyMonthly($readWorkbook($testDirectory.'/blank-start.xlsx'))) === ['2024-02', '2024-03'], 'A blank start date must use the first matching transaction month, just like inception.');
    $monthlySource->rows[0]->currency_code = '01';
    $monthlySource->rows[0]->cash_status_id = 3;
    $monthlyReport->generate([...$criteria, 'date_from' => '2024-01-31', 'date_to' => '2024-03-01', 'currency_code' => '01', 'status_ids' => [3]], $testDirectory.'/usd-monthly.xlsx', static fn(int $count) => null);
    $usdSheets = $readWorkbook($testDirectory.'/usd-monthly.xlsx');
    $usdMonths = $verifyMonthly($usdSheets);
    $usdMetadata = array_column($usdSheets['Monthly Summary'], 1, 0);
    $check($usdMetadata['Currency'] === 'USD' && $usdMetadata['Cash Status IDs'] === '3'
        && BigDecimal::of((string) $usdMonths['2024-02'][1])->isEqualTo('0.0001'), 'Monthly summaries must respect and identify selected non-default currency and statuses.');
    echo "PASS: multi-year monthly ins/outs, cross-month reversals, separate fees/review, all date bases, partial-month bounds, zero months, leap day, exact reconciliation and no additional source queries\n";

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
            } elseif (str_starts_with($sheet->getName(), 'Transactions ')) {
                $detailCounts[] = count($rows) - 1;
            }
        }
    } finally {
        $reader->close();
    }
    $check($detailCounts === [5000, 1], 'The transaction after the configured worksheet limit must move to the next sheet.');
    $check(BigDecimal::of((string) $splitSummary['Total Purchases'])->isEqualTo('1225.1002'), 'Summary totals must include amounts on later detail sheets.');
    $splitMonths = $verifyMonthly($readWorkbook($testDirectory.'/split.xlsx'));
    $check(BigDecimal::of((string) $splitMonths['2019-08'][1])->isEqualTo('1225.1002')
        && $splitMonths['2019-08'][7] === 5001, 'Monthly totals and counts must include transactions on every detail sheet.');
    echo "PASS: exact 5,000-row worksheet boundary, next-sheet overflow, transaction-first order and complete-result Summary/monthly totals\n";

    $source->rows = [];
    $empty = $report->generate($criteria, $testDirectory.'/empty.xlsx', static fn(int $count) => null);
    $check($empty['processed_transactions'] === 0, 'An empty report must complete with zero transactions.');
    $emptySheets = $readWorkbook($testDirectory.'/empty.xlsx');
    $check($verifyMonthly($emptySheets) === [], 'An empty inception report must not invent a historical starting month.');
    $check(str_contains(array_column($emptySheets['Monthly Summary'], 1, 0)['Activity'], 'No matching transactions'), 'An empty monthly sheet must explicitly explain the lack of activity.');
    $report->generate($monthlyCriteria, $testDirectory.'/empty-range.xlsx', static fn(int $count) => null);
    $emptyRangeMonths = $verifyMonthly($readWorkbook($testDirectory.'/empty-range.xlsx'));
    $check(array_keys($emptyRangeMonths) === ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04'], 'An empty custom range must still include every selected month as numeric zeros.');
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
    $metadataSql = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)->fetchFundIdsForCashTransactions([1, 2]))[0]['query'];
    $check(str_contains($metadataSql, 'COUNT(DISTINCT ISNULL(ISNULL(tt.NameEN, CAST(fl.iType AS NVARCHAR(100))),')
        && str_contains($metadataSql, 'AS transaction_type_count') && str_contains($metadataSql, 'MIN(tr.mAmountUsed)')
        && str_contains($metadataSql, 'MIN(tr.mAmountLeft)') && str_contains($metadataSql, 'MIN(ISNULL(tt.NameEN'),
        'Mirror metadata must retain strict type ambiguity and trade availability without changing existing representative types.');
    $queries = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)
        ->fetchAllTransactionExportRowsAfter(null, $queryFilters, null, 100));
    $sql = $queries[0]['query'];
    $check(str_contains($sql, "[page_fl].[FundID] = 'CASHagch'") && in_array('CASHagch', $queries[0]['bindings'], true), 'The candidate SQL must use exact FundID matching.');
    $check(!str_contains($sql, 'group by') && !str_contains($sql, 'UB_EFTItem'), 'Candidate selection must avoid full-history fund aggregation and EFT/bank matching.');
    $check(str_contains($sql, "[ct].[dtSettlement] < '2026-10-25 00:00:00'"), 'The full selected end date must include fractional seconds before the next midnight.');
    $ledger = new ReflectionMethod(SqlServerVieFundRemoteRepository::class, 'buildAllTransactionCashLedgerQuery');
    $hydration = $ledger->invoke($app->make(SqlServerVieFundRemoteRepository::class), null, [
        ...$queryFilters, 'cash_transaction_ids_json' => '[1,2]', 'cash_transaction_ids_prevalidated' => true,
    ])->toSql();
    $check(str_contains($hydration, 'COUNT(DISTINCT') && str_contains($hydration, 'Multiple fund transaction types')
        && substr_count($hydration, 'OPENJSON(?)') === 2
        && str_contains($hydration, 'ct.mAmount AS amount'), 'Hydration must preserve ambiguous types and cash amounts, aggregating only the selected batch IDs.');
    $countQueries = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)->countAllTransactions(null, $queryFilters));
    $check(str_contains($countQueries[0]['query'], 'count(*) as aggregate')
        && str_contains($countQueries[0]['query'], "[page_fl].[FundID] = 'CASHagch'")
        && !str_contains($countQueries[0]['query'], 'group by')
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
    $monthlyCountFilters = VieFundCashAgchReport::filters([
        ...$criteria, 'date_basis' => 'trade_date', 'date_from' => '2019-08-09',
        'date_to' => '2019-08-31', 'availability_date_to' => $criteria['date_to'],
    ]);
    $monthlyCount = $connection->pretend(fn() => $app->make(SqlServerVieFundRemoteRepository::class)->countAllTransactions(null, $monthlyCountFilters))[0]['query'];
    $check(str_contains($monthlyCount, "[ct].[dtTrade] < '2019-09-01 00:00:00'")
        && str_contains($monthlyCount, "[ct].[dtSettlement] < '2026-10-25 00:00:00'"),
        'Monthly trade-date counts must use the full report end for settlement availability, not narrow availability to the counted month.');
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
    $check($job instanceof GenerateVieFundCashAgchReport && $job->userId === 17
        && $job->connection === 'database_reports' && $job->queue === 'cashagch-reports', 'Report must use the isolated resumable report queue with the requesting owner.');
    $availability = $app->make(VieFundAvailabilityWindow::class);
    $runService = new VieFundCashAgchReportRun($source, $app->make(RuntimeSettings::class), $report);
    $finishJob = static function (GenerateVieFundCashAgchReport $job) use ($runService, $availability, $check): void {
        for ($stage = 0; $stage < 2000; ++$stage) {
            $job->handle($runService, $availability);
            if ((Cache::get(VieFundCashAgchReport::statusKey($job->userId, $job->runId))['success'] ?? null) !== null) return;
        }
        $check(false, 'Report did not finish within the expected number of bounded stages.');
    };
    $finishJob($job);
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
    $check(Cache::get(VieFundCashAgchReport::statusKey(17, $runId))['success'] === true, 'A stale failure callback must not invalidate an already-published workbook.');
    $rangeRequest = Request::create('/reports/cashagch/run', 'POST', [...$criteria, 'date_from' => '2019-08-09', 'date_to' => '2019-08-09']);
    $rangeRequest->headers->set('Accept', 'application/json');
    $rangeRequest->setUserResolver(static fn() => $user);
    $rangeRunId = $controller->run($rangeRequest, $availability)->getData(true)['run_id'];
    $rangeJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($rangeJob->criteria['date_from'] === '2019-08-09', 'Controller must persist the requested start date in the queued criteria.');
    $finishJob($rangeJob);
    $rangeDownload = $controller->download($rangeRequest, $rangeRunId);
    $check(str_contains($rangeDownload->headers->get('Content-Disposition'), 'CASHagch-2019-08-09-to-2019-08-09.xlsx'), 'Custom-range download names must not claim since inception.');
    $timedRunId = $controller->run($request, $availability)->getData(true)['run_id'];
    $timedJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $timedKey = VieFundCashAgchReport::statusKey(17, $timedRunId);
    $timedState = [
        ...$runService->load(VieFundCashAgchReportRun::directory(17, $timedRunId), $criteria),
        'phase' => 'counting', 'count_from' => '2019-08-09',
    ];
    $check($runService->status($timedState)['eta_seconds'] === null, 'Counting must not publish a guessed ETA.');
    $timedState = [...$timedState, 'phase' => 'exporting', 'processed_transactions' => 100,
        'expected_transactions' => 1000, 'read_batches' => 1, 'read_seconds' => 10];
    $check($runService->status($timedState)['eta_seconds'] === null, 'A single batch must remain in ETA warm-up.');
    $timedState = [...$timedState, 'processed_transactions' => 200, 'read_batches' => 2, 'read_seconds' => 20];
    $timedStatus = $runService->status($timedState);
    $check($timedStatus['rows_per_second'] === 10.0 && $timedStatus['eta_seconds'] === 80, '200 rows in 20 seconds must estimate exactly 80 seconds for 800 remaining, excluding count/queue time.');
    Cache::put($timedKey, [...Cache::get($timedKey), ...$timedStatus]);
    $completion = $controller->status($request, $timedRunId)->getData(true)['estimated_completion_at'];
    $check(CarbonImmutable::parse($completion)->equalTo(CarbonImmutable::now()->addSeconds(80)), 'Completion estimate must use measured remaining time.');
    Carbon::setTestNow('2026-10-24 12:00:15 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:15 UTC');
    $check($controller->status($request, $timedRunId)->getData(true)['estimated_completion_at'] === $completion, 'Polling alone must not move the completion estimate.');
    $timedState = [...$timedState, 'processed_transactions' => 300, 'read_seconds' => 40];
    $check($runService->status($timedState)['eta_seconds'] === 94, 'Slower throughput must update ETA using ceil(700 / 7.5).');
    $timedState['processed_transactions'] = 1200;
    $check($runService->status($timedState)['eta_seconds'] === null, 'A changing source count must not produce a negative ETA.');
    $timedState['phase'] = 'finalizing';
    $finalizing = $runService->status($timedState);
    $check($finalizing['eta_seconds'] === null && $finalizing['estimated_completion_at'] === null, 'Workbook finalization must clear the read ETA.');
    $check($controller->status($request, $timedRunId)->getData(true)['download_url'] === null, 'Unfinished stages must never expose a download.');
    $timedJob->failed(new RuntimeException('Simulated failure after ETA'));
    $check(Cache::get($timedKey)['eta_seconds'] === null && Cache::get($timedKey)['estimated_completion_at'] === null, 'Failures must clear every ETA field.');
    $zeroTime = $runService->status([...$timedState, 'phase' => 'exporting', 'read_seconds' => 0, 'expected_transactions' => 0]);
    $check($zeroTime['eta_seconds'] === null && $zeroTime['rows_per_second'] === null, 'Zero time/count must not divide by zero or invent an ETA.');
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
    $check($maintenanceJob->delay === null, 'The worker must select the source before deciding whether remote maintenance requires a delay.');
    $maintenanceJob->handle($runService, $availability);
    $check(count($source->requests) === $sourceCalls, 'A delayed worker that starts during maintenance must not query VieFund.');
    $requeued = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $check($requeued !== $maintenanceJob && $requeued->runId === $maintenanceRunId, 'Maintenance deferral must retain the same user-facing run.');
    Carbon::setTestNow('2026-10-24 23:26:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 23:26:00 UTC');
    $finishJob($requeued);
    $check($controller->status($request, $maintenanceRunId)->getData(true)['success'] === true, 'A maintenance-delayed report must complete after the window.');
    Carbon::setTestNow('2026-10-24 22:54:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 22:54:00 UTC');
    $interruptedRunId = $controller->run($request, $availability)->getData(true)['run_id'];
    $interruptedJob = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
    $source->onFetch = static function (): void {
        Carbon::setTestNow('2026-10-24 22:55:00 UTC');
        CarbonImmutable::setTestNow('2026-10-24 22:55:00 UTC');
    };
    $interruptedJob->handle($runService, $availability);
    $saved = $runService->load(VieFundCashAgchReportRun::directory(17, $interruptedRunId), $interruptedJob->criteria);
    $interruptedJob->handle($runService, $availability);
    $check($saved['processed_transactions'] === 1 && $saved['cursor'] !== null, 'A batch finishing as maintenance begins must be retained.');
    $check($controller->status($request, $interruptedRunId)->getData(true)['state'] === 'queued', 'Maintenance must pause the same run instead of failing it.');
    $check($controller->status($request, $interruptedRunId)->getData(true)['download_url'] === null, 'A paused report must not publish a partial workbook.');
    $source->onFetch = null;
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    foreach (['run', 'status', 'download'] as $action) {
        $route = $app['router']->getRoutes()->getByName('reports.cashagch.'.$action);
        $check($route !== null && in_array('auth.check', $route->gatherMiddleware(), true), 'Every new report route must require authentication.');
    }
    $app['auth']->guard()->setUser($user);
    $inceptionDates = ['settlement_date' => '2019-06-10', 'trade_date' => '2019-06-07'];
    $html = view('reports.cashagch', [
        'dateBasisOptions' => VieFundCashAgchReport::DATE_BASES,
        'fundStatusOptions' => VieFundReportsController::FUND_STATUS_OPTIONS,
        'inceptionDates' => $inceptionDates,
    ])->render();
    $check(str_contains($html, 'Run Report') && str_contains($html, 'Use Inception Date')
        && str_contains($html, 'name="date_from"'), 'Reports page must expose optional start-date and inception controls.');
    $document = new DOMDocument();
    $check($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET), 'The report form must render as usable HTML.');
    $xpath = new DOMXPath($document);
    $formInceptionDates = $xpath->query('//form[@id="cashagch-report-form"]')->item(0)->getAttribute('data-inception-dates');
    $check(json_decode($formInceptionDates, true, 512, JSON_THROW_ON_ERROR) === $inceptionDates
        && str_contains($html, 'Inception Date: 2019-06-10'), 'CASHagch must receive and display the same basis-specific inception dates as the other report controls.');
    $check($xpath->query('//form[@id="cashagch-report-form"]//div[@class="report-run-action"]/button[@type="submit"]')->length === 1,
        'The CASHagch Run Report action must be in the right-aligned action column.');
    $previewPath = getenv('REPORT_UI_PREVIEW');
    if ($previewPath !== false && $previewPath !== '') {
        preg_match('/<style>([\s\S]*?)<\/style>/', file_get_contents(base_path('resources/views/reports/index.blade.php')), $styles);
        $check(file_put_contents($previewPath, '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
            . '*{box-sizing:border-box}body{font:14px Arial,sans-serif;margin:0;padding:24px;background:#f3f6f7}.card{padding:24px;border-radius:6px;background:white}'
            . $styles[1].'</style></head><body><div class="reports-page">'.$html.'</div></body></html>') !== false, 'Unable to write the requested browser preview.');
    }
    echo "PASS: queue lifecycle, progress/download, owner isolation, explicit worker failure, authenticated routes and rendered report form\n";
    $finishJob($interruptedJob);
    $check($controller->status($request, $interruptedRunId)->getData(true)['success'] === true, 'A maintenance-interrupted report must resume and complete without starting over.');
    echo "PASS: criteria validation, maintenance deferral/resume, saved progress and partial-report rejection\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exitCode = 1;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    $app->make('files')->deleteDirectory($testDirectory);
}
exit($exitCode);
