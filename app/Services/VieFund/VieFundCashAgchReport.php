<?php

namespace App\Services\VieFund;

use App\Services\RuntimeSettings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

class VieFundCashAgchReport
{
    public const FUND_ID = 'CASHagch';

    public const DATE_BASES = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];

    private const CATEGORIES = [
        'purchase' => 'Purchases',
        'purchase pac' => 'Purchases',
        'rebalancing purchase' => 'Purchases',
        'redemption' => 'Standard redemptions',
        'rebalancing redemption' => 'Standard redemptions',
        'fee redemption' => 'Fee redemptions',
    ];

    private const DETAIL_FIELDS = [
        'transaction_id' => 'Cash Transaction ID',
        'fund_transaction_id' => 'Fund Transaction ID',
        'source_id' => 'Source ID',
        'fund_id' => 'Fund / Unit ID',
        'customer_name' => 'Customer Name',
        'plan_account_id' => 'Plan Account ID',
        'transaction_type' => 'Transaction Type',
        'status' => 'Cash Status',
        'created_date' => 'Created Date',
        'trade_date' => 'Trade Date',
        'processing_date' => 'Processing Date',
        'settlement_date' => 'Settlement Date',
        'currency_code' => 'Currency',
        'amount' => 'Signed Transaction Amount',
    ];

    public function __construct(
        private readonly VieFundRemoteService $remote,
        private readonly RuntimeSettings $settings
    ) {}

    public static function statusKey(int $userId, string $runId): string
    {
        return "reports:cashagch:{$userId}:{$runId}";
    }

    public static function outputPath(int $userId, string $runId): string
    {
        return storage_path("app/reports/viefund-cashagch-{$userId}-{$runId}.xlsx");
    }

    /** @param callable(int, int, string): void $progress */
    public function generate(array $criteria, string $path, callable $progress): array
    {
        $filters = [
            'fund_id' => self::FUND_ID,
            'fund_id_exact' => true,
            'require_unambiguous_fund_type' => true,
            'mirror_fields' => true,
            'date_to' => $criteria['date_to'],
            'date_basis' => $criteria['date_basis'],
            'currency_code' => $criteria['currency_code'],
            'status_ids' => $criteria['status_ids'],
            'output_order' => 'asc',
        ];
        if (!empty($criteria['date_from'])) {
            $filters['date_from'] = $criteria['date_from'];
        }
        $batchSize = max(100, min(20000, (int) $this->settings->get('viefund.export.batch_size')));
        $rowsPerSheet = max(1, min(1000000, (int) $this->settings->get('viefund.export.rows_per_sheet')));
        $totals = [];
        $counts = [];
        foreach (['Purchases', 'Standard redemptions', 'Fee redemptions', 'Other / review required'] as $category) {
            $totals[$category] = BigDecimal::zero();
            $counts[$category] = 0;
        }
        $net = BigDecimal::zero();
        $count = 0;
        $firstDate = null;
        $lastDate = null;
        $cursor = null;
        $sheetNumber = 1;
        $sheetRows = 0;
        $headerStyle = (new Style())->setFontBold();
        $moneyStyle = (new Style())->setFormat('#,##0.0000;[Red](#,##0.0000);0.0000');
        $options = new Options();
        $options->DEFAULT_COLUMN_WIDTH = 24;
        $writer = new Writer($options);
        $open = false;
        $completed = false;
        $previousOpenedBefore = config('viefund.balance_report_cash_account_scope.opened_before');
        config(['viefund.balance_report_cash_account_scope.opened_before' => null]);

        try {
            $expectedRows = $this->remote->countAllTransactions(null, $filters);
            $progress(0, $expectedRows, 'exporting');
            File::ensureDirectoryExists(dirname($path));
            $writer->openToFile($path);
            $open = true;
            $summary = $writer->getCurrentSheet();
            $summary->setName('Summary');
            $summary->setColumnWidth(46, 1);
            $summary->setColumnWidth(30, 2);
            $writer->addNewSheetAndMakeItCurrent()->setName('Transactions 1');
            $writer->addRow(Row::fromValues([...array_values(self::DETAIL_FIELDS), 'Summary Category'], $headerStyle));

            do {
                $rows = $this->remote->fetchAllTransactionExportRowsAfter(null, $filters, $cursor, $batchSize);
                foreach ($rows as $row) {
                    if (strcasecmp(trim((string) $row->fund_id), self::FUND_ID) !== 0) {
                        throw new RuntimeException('The CASHagch report received a transaction for a different fund.');
                    }
                    if ($row->amount === null || trim((string) $row->amount) === '') {
                        throw new RuntimeException("Cash transaction {$row->transaction_id} has no transaction amount.");
                    }
                    $amount = BigDecimal::of((string) $row->amount);
                    $category = self::CATEGORIES[mb_strtolower(trim((string) ($row->transaction_type ?? '')))]
                        ?? 'Other / review required';
                    $totals[$category] = $totals[$category]->plus($category === 'Purchases' ? $amount->negated() : $amount);
                    ++$counts[$category];
                    $net = $net->plus($amount);
                    ++$count;
                    $basisDate = CarbonImmutable::parse($row->basis_date)->toDateString();
                    $firstDate ??= $basisDate;
                    $lastDate = $basisDate;

                    if ($sheetRows >= $rowsPerSheet) {
                        $writer->addNewSheetAndMakeItCurrent()->setName('Transactions '.++$sheetNumber);
                        $writer->addRow(Row::fromValues([...array_values(self::DETAIL_FIELDS), 'Summary Category'], $headerStyle));
                        $sheetRows = 0;
                    }
                    $cells = [];
                    foreach (self::DETAIL_FIELDS as $field => $label) {
                        $cells[] = $field === 'amount'
                            ? new NumericCell($amount->toFloat(), $moneyStyle)
                            : new StringCell((string) ($row->{$field} ?? ''), null);
                    }
                    $cells[] = new StringCell($category, null);
                    $writer->addRow(new Row($cells));
                    ++$sheetRows;
                }
                if ($rows->isNotEmpty()) {
                    $last = $rows->last();
                    $next = [
                        'basis_date' => (string) $last->basis_date,
                        'sort_id' => (int) $last->sort_id,
                        'transaction_id' => (string) $last->transaction_id,
                        'plan_account_id' => (string) $last->plan_account_id,
                    ];
                    if ($next === $cursor) {
                        throw new RuntimeException('The CASHagch report source cursor did not advance.');
                    }
                    $cursor = $next;
                    $progress($count, $expectedRows, 'exporting');
                }
            } while ($rows->isNotEmpty());

            $progress($count, $expectedRows, 'finalizing');
            $writer->setCurrentSheet($summary);
            $writer->addRow(Row::fromValues(['CASHagch Purchases and Redemptions', ''], $headerStyle));
            foreach ([
                ['Fund / Unit ID', self::FUND_ID],
                ['Period', (!empty($criteria['date_from']) ? $criteria['date_from'] : 'Since inception').' through '.$criteria['date_to']],
                ['First Included Transaction Date', $firstDate ?? 'No transactions'],
                ['Last Included Transaction Date', $lastDate ?? 'No transactions'],
                ['Date Basis', self::DATE_BASES[$criteria['date_basis']]],
                ['Currency', $criteria['currency_code'] === '00' ? 'CAD' : 'USD'],
                ['Cash Status IDs', implode(', ', $criteria['status_ids'])],
                ['Amount Source', 'VieFund cash transaction Amount (UB_CashTrx.mAmount); no bank amounts or bank-match requirement'],
                ['Data Source', 'Direct VieFund cash ledger; independent of period caches and mirror backfill'],
                ['Generated At (Eastern)', now('America/Toronto')->format('Y-m-d H:i:s T')],
                ['Total Cash Transactions', $count],
                ['Purchase Transaction Count', $counts['Purchases']],
                ['Standard Redemption Transaction Count', $counts['Standard redemptions']],
                ['Fee Redemption Transaction Count', $counts['Fee redemptions']],
                ['All Redemption Transaction Count', $counts['Standard redemptions'] + $counts['Fee redemptions']],
                ['Other / Review Required Transaction Count', $counts['Other / review required']],
            ] as $metadata) {
                $writer->addRow(Row::fromValues($metadata));
            }
            foreach ([
                'Total Purchases' => $totals['Purchases'],
                'Standard Redemptions' => $totals['Standard redemptions'],
                'Fee Redemptions' => $totals['Fee redemptions'],
                'Total Redemptions (Including Fees)' => $totals['Standard redemptions']->plus($totals['Fee redemptions']),
                'Other / Review Required Signed Amount' => $totals['Other / review required'],
                'Net Signed Transaction Amount' => $net,
            ] as $label => $value) {
                $writer->addRow(new Row([new StringCell($label, null), new NumericCell($value->toFloat(), $moneyStyle)]));
            }
            $writer->addRow(Row::fromValues(['Calculation', 'Purchase totals negate signed purchase amounts. Redemption totals sum signed redemption amounts. Reversals reduce their category totals; individual rows are not converted to absolute values.']));
            $writer->addRow(Row::fromValues(['Classification', 'Purchase, Purchase PAC and Rebalancing purchase; Redemption and Rebalancing redemption; Fee Redemption separately. Other types are not inferred from cash direction.']));
            $writer->addRow(Row::fromValues(['Review', $counts['Other / review required'] > 0
                ? 'Other or ambiguous transaction types are present. Review the detail categories before treating purchase/redemption totals as complete.'
                : 'No unclassified transaction types.']));
            $open = false;
            $writer->close();
            $completed = true;

            return ['processed_transactions' => $count, 'review_transactions' => $counts['Other / review required']];
        } finally {
            try {
                if ($open) {
                    $writer->close();
                }
            } finally {
                config(['viefund.balance_report_cash_account_scope.opened_before' => $previousOpenedBefore]);
                if (!$completed && is_file($path)) {
                    File::delete($path);
                }
            }
        }
    }
}
