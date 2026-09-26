<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundDailyBalanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class GenerateVieFundAllTransactionsExportCommand extends Command
{
    private const TRANSACTION_HEADERS = [
        'Cash Txn ID', 'Fund Txn ID', 'Trust Txn ID', 'Relationship', 'Source ID',
        'Customer Name', 'Plan Account ID', 'Txn Type', 'Cash Status', 'Trust Status',
        'Notes', 'Created Date', 'Trade Date', 'Processing Date', 'Settlement Date', 'Currency', 'Amount',
    ];

    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    protected $signature = 'report:viefund-all-transactions
        {--run-id=}
        {--search=}
        {--customer-name=}
        {--plan-account-id=}
        {--transaction-id=}
        {--source-id=}
        {--date-from=}
        {--date-to=}
        {--date-basis=settlement_date}
        {--output-order=desc}
        {--currency-code=00}
        {--status=*}
        {--transaction-type=*}
        {--split-sheets : Distribute transactions into date-based sheets near the configured target size}
        {--output-base= : Storage-relative output path without an extension}
        {--status-file= : Absolute progress status path}
        {--lock-file= : Absolute lock path}';

    protected $description = 'Stream the VieFund All Transactions result set to a multi-sheet Excel workbook';

    private string $runId = '';

    public function handle(
        VieFundRemoteService $remoteService,
        VieFundDailyBalanceService $dailyBalanceService
    ): int
    {
        $startedAt = microtime(true);
        $startedAtIso = now()->toIso8601String();
        $statusFile = $this->stringOption('status-file');
        $lockFile = $this->stringOption('lock-file');
        $outputBase = $this->stringOption('output-base');
        $this->runId = $this->stringOption('run-id');
        $writer = null;
        $writerIsOpen = false;
        $outputAbsolutePath = '';

        try {
            if ($outputBase === '') {
                throw new \InvalidArgumentException('The --output-base option is required.');
            }

            $search = $this->stringOption('search');
            $dateBasis = $this->normalizeDateBasis($this->stringOption('date-basis'));
            $outputOrder = $this->stringOption('output-order') === 'asc' ? 'asc' : 'desc';
            $currencyCode = in_array($this->stringOption('currency-code'), ['00', '01'], true)
                ? $this->stringOption('currency-code')
                : '00';
            $statusIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $this->option('status')),
                fn($status) => $status >= 0 && $status <= 6
            )));
            $statusIds = $statusIds ?: [6];
            $filters = array_filter([
                'customer_name' => $this->stringOption('customer-name'),
                'plan_account_id' => $this->stringOption('plan-account-id'),
                'trx_id' => $this->stringOption('transaction-id'),
                'source_id' => $this->stringOption('source-id'),
                'date_from' => $this->stringOption('date-from'),
                'date_to' => $this->stringOption('date-to'),
                'date_basis' => $dateBasis,
                'output_order' => $outputOrder,
                'currency_code' => $currencyCode,
                'status_ids' => $statusIds,
                'trx_type' => array_values(array_filter((array) $this->option('transaction-type'))),
            ]);
            $maximumRowsPerSheet = (int) config('viefund.all_transactions_export_rows_per_sheet', 1000000);
            $splitTargetRows = (int) config('viefund.all_transactions_export_split_target_rows', 65000);
            $databaseBatchSize = (int) config('viefund.all_transactions_export_batch_size', 20000);
            $distributeSheets = (bool) $this->option('split-sheets');

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => 'Reviewing the matching transaction dates...',
                'progress_pct' => 2,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);

            $dailyStats = $remoteService->fetchAllTransactionExportDailyStats($search ?: null, $filters);
            $totalTransactions = (int) $dailyStats->sum(fn($row) => (int) $row->transaction_count);
            $overallSelectedNet = (float) $dailyStats->sum(fn($row) => (float) $row->net_amount);
            $firstDate = $dailyStats->isNotEmpty()
                ? Carbon::parse($dailyStats->first()->transaction_date)->toDateString()
                : null;
            $lastDate = $dailyStats->isNotEmpty()
                ? Carbon::parse($dailyStats->last()->transaction_date)->toDateString()
                : null;
            $balanceFromDate = $filters['date_from'] ?? $firstDate;
            $balanceToDate = $filters['date_to'] ?? $lastDate;
            $balanceReport = ($balanceFromDate && $balanceToDate)
                ? $dailyBalanceService->build(
                    Carbon::parse($balanceFromDate)->startOfDay(),
                    Carbon::parse($balanceToDate)->startOfDay(),
                    $dateBasis,
                    $currencyCode,
                    $statusIds,
                    null,
                    'asc'
                )
                : [
                    'rows' => [],
                    'opening_balance' => 0.0,
                    'final_balance' => 0.0,
                    'balance_source' => 'Direct Cash Ledger (VieFund database)',
                ];
            $overallOpeningBalance = (float) $balanceReport['opening_balance'];
            $cashLedgerPeriodNet = array_sum(array_column($balanceReport['rows'], 'daily_net_transactions'));
            $sheetPlans = $this->buildSheetPlans(
                $dailyStats,
                $distributeSheets,
                $splitTargetRows,
                $maximumRowsPerSheet,
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null
            );
            $sheetPlans = $this->addCashLedgerNetsToSheetPlans($sheetPlans, $balanceReport['rows']);
            $sheetPlans = $this->addRunningBalancesToSheetPlans($sheetPlans, $overallOpeningBalance);
            if ($outputOrder === 'desc') {
                $sheetPlans = array_reverse($sheetPlans);
            }
            $estimatedTransactionSheets = count($sheetPlans);

            $outputRelativePath = $outputBase . '.xlsx';
            $outputAbsolutePath = storage_path('app/' . ltrim($outputRelativePath, '/'));
            $outputDirectory = dirname($outputAbsolutePath);
            if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
                throw new \RuntimeException('Unable to create the Excel export directory.');
            }

            $options = new Options();
            $options->DEFAULT_ROW_STYLE = (new Style())->setFontName('Calibri')->setFontSize(11);
            $writer = new Writer($options);
            $writer->openToFile($outputAbsolutePath);
            $writerIsOpen = true;

            $headerStyle = (new Style())->setFontName('Calibri')->setFontSize(11)->setFontBold();
            $currencyStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFormat(self::ACCOUNTING_CURRENCY_FORMAT);

            $sheetNumber = 1;
            $sheetPlanIndex = 0;
            $currentSheetPlan = $sheetPlans[$sheetPlanIndex];
            $sheet = $writer->getCurrentSheet();
            $this->prepareTransactionSheet($writer, $sheet, $currentSheetPlan['name'], $headerStyle);
            $sheetRowCount = 0;
            $sheetOpeningBalance = $currentSheetPlan['opening_balance'];
            $sheetNet = 0.0;
            $sheetSummaries = [];
            $processedTransactions = 0;
            $cursor = null;

            do {
                if ($lockFile !== '') {
                    @touch($lockFile);
                }
                $this->writeStatus($statusFile, [
                    'inProgress' => true,
                    'success' => null,
                    'message' => $processedTransactions === 0
                        ? 'Preparing the first transaction batch...'
                        : sprintf(
                            'Loading the next batch after %s of %s transactions...',
                            number_format($processedTransactions),
                            number_format($totalTransactions)
                        ),
                    'progress_pct' => $totalTransactions > 0
                        ? min(98, max(3, (int) floor(($processedTransactions / $totalTransactions) * 98)))
                        : 3,
                    'processed_transactions' => $processedTransactions,
                    'total_transactions' => $totalTransactions,
                    'processed_sheets' => $sheetNumber - 1,
                    'total_sheets' => $estimatedTransactionSheets,
                    'started_at' => $startedAtIso,
                    'updated_at' => now()->toIso8601String(),
                ]);
                $rows = $remoteService->fetchAllTransactionExportRowsAfter(
                    $search ?: null,
                    $filters,
                    $cursor,
                    $databaseBatchSize
                );

                foreach ($rows as $row) {
                    if (
                        $sheetRowCount >= $currentSheetPlan['expected_rows']
                        && $sheetPlanIndex < count($sheetPlans) - 1
                    ) {
                        $this->finalizeTransactionSheet($sheet, $sheetRowCount);
                        $sheetSummary = $this->sheetSummary(
                            $sheetNumber, $currentSheetPlan['name'], $sheetRowCount,
                            $currentSheetPlan['from_date'], $currentSheetPlan['to_date'],
                            $sheetOpeningBalance, $sheetNet, $currentSheetPlan['cash_ledger_net']
                        );
                        $sheetSummaries[] = $sheetSummary;
                        ++$sheetNumber;
                        ++$sheetPlanIndex;
                        $currentSheetPlan = $sheetPlans[$sheetPlanIndex];
                        $sheet = $writer->addNewSheetAndMakeItCurrent();
                        $this->prepareTransactionSheet($writer, $sheet, $currentSheetPlan['name'], $headerStyle);
                        $sheetRowCount = 0;
                        $sheetOpeningBalance = $currentSheetPlan['opening_balance'];
                        $sheetNet = 0.0;
                    }

                    $amount = (float) ($row->amount ?? 0);
                    $createdDate = $this->dateTime($row->created_date ?? null);
                    $writer->addRow(new Row([
                        new StringCell((string) ($row->transaction_id ?? ''), null),
                        new StringCell(!empty($row->fund_transaction_id) ? 'F-' . $row->fund_transaction_id : '', null),
                        new StringCell(!empty($row->trust_transaction_id) ? 'T-' . $row->trust_transaction_id : '', null),
                        new StringCell((string) ($row->ledger_relationship ?? ''), null),
                        // Source IDs can exceed Excel's 15-digit numeric precision.
                        new StringCell((string) ($row->source_id ?? ''), null),
                        new StringCell(trim((string) ($row->customer_name ?? '')), null),
                        new StringCell((string) ($row->plan_account_id ?? ''), null),
                        new StringCell((string) ($row->transaction_type ?? ''), null),
                        new StringCell((string) ($row->status ?? ''), null),
                        new StringCell((string) ($row->trust_status ?? ''), null),
                        new StringCell((string) ($row->notes ?? ''), null),
                        new StringCell($createdDate, null),
                        new StringCell($this->dateTime($row->trade_date ?? null), null),
                        new StringCell($this->dateTime($row->processing_date ?? null), null),
                        new StringCell($this->dateTime($row->settlement_date ?? null), null),
                        new StringCell($this->currencyLabel((string) ($row->currency_code ?? '')), null),
                        new NumericCell($amount, $currencyStyle),
                    ]));

                    ++$sheetRowCount;
                    ++$processedTransactions;
                    $sheetNet += $amount;
                }

                if ($rows->isNotEmpty()) {
                    $lastRow = $rows->last();
                    $cursor = [
                        'basis_date' => (string) $lastRow->basis_date,
                        'sort_id' => (int) $lastRow->sort_id,
                        'transaction_id' => (string) $lastRow->transaction_id,
                        'plan_account_id' => (string) ($lastRow->plan_account_id ?? ''),
                    ];
                }

                $progress = $totalTransactions > 0
                    ? min(98, max(3, (int) floor(($processedTransactions / $totalTransactions) * 98)))
                    : 98;
                $this->writeStatus($statusFile, [
                    'inProgress' => true,
                    'success' => null,
                    'message' => sprintf(
                        'Wrote %s of %s transactions to sheet %d of %d...',
                        number_format($processedTransactions),
                        number_format($totalTransactions),
                        $sheetNumber,
                        $estimatedTransactionSheets
                    ),
                    'progress_pct' => $progress,
                    'processed_transactions' => $processedTransactions,
                    'total_transactions' => $totalTransactions,
                    'processed_sheets' => $sheetNumber - 1,
                    'total_sheets' => $estimatedTransactionSheets,
                    'started_at' => $startedAtIso,
                    'updated_at' => now()->toIso8601String(),
                ]);
            } while ($rows->count() === $databaseBatchSize);

            $this->finalizeTransactionSheet($sheet, $sheetRowCount);
            $sheetSummaries[] = $this->sheetSummary(
                $sheetNumber, $currentSheetPlan['name'], $sheetRowCount,
                $currentSheetPlan['from_date'], $currentSheetPlan['to_date'],
                $sheetOpeningBalance, $sheetNet, $currentSheetPlan['cash_ledger_net']
            );

            $runningBalanceRows = $this->buildResultRunningBalanceRows(
                $dailyStats,
                $balanceFromDate,
                $balanceToDate,
                $overallOpeningBalance,
                $outputOrder
            );
            $resultBalancesSheet = $writer->addNewSheetAndMakeItCurrent();
            $this->writeResultBalancesSheet(
                $writer,
                $resultBalancesSheet,
                $headerStyle,
                $currencyStyle,
                $runningBalanceRows,
                $overallOpeningBalance,
                $dateBasis,
                $currencyCode
            );

            $summarySheet = $writer->addNewSheetAndMakeItCurrent();
            $this->writeSummarySheet(
                $writer, $summarySheet, $headerStyle, $currencyStyle, $sheetSummaries,
                $filters, $search, $totalTransactions, $firstDate, $lastDate,
                $overallOpeningBalance, $overallSelectedNet, $cashLedgerPeriodNet,
                (float) $balanceReport['final_balance'], (string) $balanceReport['balance_source']
            );

            $writer->close();
            $writerIsOpen = false;
            if ($lockFile !== '') {
                @touch($lockFile);
            }

            $duration = round(microtime(true) - $startedAt, 2);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'message' => sprintf(
                    'Export completed in %ss. %s transactions written across %d transaction %s.',
                    $duration,
                    number_format($processedTransactions),
                    count($sheetSummaries),
                    count($sheetSummaries) === 1 ? 'sheet' : 'sheets'
                ),
                'progress_pct' => 100,
                'processed_transactions' => $processedTransactions,
                'total_transactions' => $totalTransactions,
                'processed_sheets' => count($sheetSummaries),
                'total_sheets' => count($sheetSummaries),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            $this->info('Export generated: ' . $outputRelativePath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($writerIsOpen && $writer instanceof Writer) {
                try {
                    $writer->close();
                } catch (Throwable) {
                    // Preserve the original failure.
                }
            }
            if ($outputAbsolutePath !== '') {
                @unlink($outputAbsolutePath);
            }
            report($exception);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'The All Transactions export failed: ' . $exception->getMessage(),
                'progress_pct' => null,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($lockFile !== '') {
                @unlink($lockFile);
            }
        }
    }

    private function prepareTransactionSheet(Writer $writer, Sheet $sheet, string $sheetName, Style $headerStyle): void
    {
        $sheet->setName($sheetName);
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $sheet->setColumnWidth(20, 1, 2, 3);
        $sheet->setColumnWidth(20, 4);
        $sheet->setColumnWidth(24, 5);
        $sheet->setColumnWidth(28, 6);
        $sheet->setColumnWidth(18, 7);
        $sheet->setColumnWidth(36, 8);
        $sheet->setColumnWidth(18, 9, 10);
        $sheet->setColumnWidth(40, 11);
        $sheet->setColumnWidth(20, 12, 13, 14, 15);
        $sheet->setColumnWidth(12, 16);
        $sheet->setColumnWidth(16, 17);
        $writer->addRow(Row::fromValues(self::TRANSACTION_HEADERS, $headerStyle));
    }

    private function finalizeTransactionSheet(Sheet $sheet, int $dataRowCount): void
    {
        $sheet->setAutoFilter(new AutoFilter(0, 1, count(self::TRANSACTION_HEADERS) - 1, max(2, $dataRowCount + 1)));
    }

    /**
     * Build the day-by-day series from the exact filtered All Transactions
     * result query. The opening balance is retained from the running-balance
     * report, while every movement after that point comes from exported rows.
     *
     * @return array<int, array{report_date: string, transaction_count: int, daily_net_amount: float, current_daily_balance: float}>
     */
    private function buildResultRunningBalanceRows(
        $dailyStats,
        ?string $fromDate,
        ?string $toDate,
        float $openingBalance,
        string $outputOrder
    ): array {
        if (!$fromDate || !$toDate) {
            return [];
        }

        $byDate = [];
        foreach ($dailyStats as $day) {
            $date = Carbon::parse($day->transaction_date)->toDateString();
            $byDate[$date] = [
                'transaction_count' => (int) $day->transaction_count,
                'daily_net_amount' => (float) $day->net_amount,
            ];
        }

        $rows = [];
        $runningBalance = $openingBalance;
        $date = Carbon::parse($fromDate)->startOfDay();
        $lastDate = Carbon::parse($toDate)->startOfDay();
        while ($date->lte($lastDate)) {
            $dateKey = $date->toDateString();
            $day = $byDate[$dateKey] ?? ['transaction_count' => 0, 'daily_net_amount' => 0.0];
            $runningBalance = round(($runningBalance + $day['daily_net_amount']) * 10000) / 10000;
            $rows[] = [
                'report_date' => $dateKey,
                'transaction_count' => $day['transaction_count'],
                'daily_net_amount' => $day['daily_net_amount'],
                'current_daily_balance' => $runningBalance,
            ];
            $date->addDay();
        }

        return $outputOrder === 'desc' ? array_reverse($rows) : $rows;
    }

    private function writeResultBalancesSheet(
        Writer $writer,
        Sheet $sheet,
        Style $headerStyle,
        Style $currencyStyle,
        array $rows,
        float $openingBalance,
        string $dateBasis,
        string $currencyCode
    ): void {
        $sheet->setName('Export Result Balances');
        $sheet->setSheetView((new SheetView())->setFreezeRow(5));
        $sheet->setColumnWidth(20, 1);
        $sheet->setColumnWidth(20, 2);
        $sheet->setColumnWidth(24, 3);
        $sheet->setColumnWidth(26, 4);

        $writer->addRow(new Row([
            new StringCell('Opening Balance', $headerStyle),
            new StringCell('', null),
            new StringCell('', null),
            new NumericCell($openingBalance, $currencyStyle),
        ]));
        $writer->addRow(Row::fromValues([
            'Daily Movement Source',
            'Distinct VieFund cash-ledger transactions',
            'Date Basis',
            $this->dateBasisLabel($dateBasis) . ' / ' . $this->currencyLabel($currencyCode),
        ]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'Report Date', 'Transactions', 'Daily Net Amount', 'Current Daily Balance',
        ], $headerStyle));

        foreach ($rows as $row) {
            $writer->addRow(new Row([
                new StringCell($row['report_date'], null),
                new NumericCell($row['transaction_count'], null),
                new NumericCell($row['daily_net_amount'], $currencyStyle),
                new NumericCell($row['current_daily_balance'], $currencyStyle),
            ]));
        }

        $sheet->setAutoFilter(new AutoFilter(0, 4, 3, max(5, count($rows) + 4)));
    }

    /**
     * @return array{sheet: int, name: string, rows: int, first_date: ?string, last_date: ?string, opening: float, exported_net: float, cash_ledger_net: float, closing: float}
     */
    private function sheetSummary(
        int $sheetNumber,
        string $sheetName,
        int $rows,
        ?string $firstDate,
        ?string $lastDate,
        float $openingBalance,
        float $exportedNet,
        float $cashLedgerNet
    ): array {
        return [
            'sheet' => $sheetNumber,
            'name' => $sheetName,
            'rows' => $rows,
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'opening' => $openingBalance,
            'exported_net' => $exportedNet,
            'cash_ledger_net' => $cashLedgerNet,
            'closing' => round(($openingBalance + $cashLedgerNet) * 10000) / 10000,
        ];
    }

    private function writeSummarySheet(
        Writer $writer,
        Sheet $sheet,
        Style $headerStyle,
        Style $currencyStyle,
        array $sheetSummaries,
        array $filters,
        string $search,
        int $totalTransactions,
        ?string $firstDate,
        ?string $lastDate,
        float $overallOpeningBalance,
        float $overallSelectedNet,
        float $cashLedgerPeriodNet,
        float $cashLedgerClosingBalance,
        string $balanceSource
    ): void {
        $sheet->setName('Summary');
        $sheet->setColumnWidth(34, 1);
        $sheet->setColumnWidth(42, 2);
        $sheet->setColumnWidth(18, 3, 4, 5, 6, 7);

        $summaryRows = [
            ['Summary Item', 'Value'],
            ['Report', 'VieFund All Transactions'],
            [$this->dateBasisLabel((string) ($filters['date_basis'] ?? 'settlement_date')) . ' Range', $this->rangeLabel($firstDate, $lastDate)],
            ['Cash Ledger Transactions in Complete Result Set', $totalTransactions],
            ['Transaction Sheets', count($sheetSummaries)],
            ['Balance Basis', sprintf(
                'VieFund Daily Net + Running Balance — %s, %s, %s',
                $this->dateBasisLabel((string) ($filters['date_basis'] ?? 'settlement_date')),
                $this->currencyLabel((string) ($filters['currency_code'] ?? '00')),
                $this->statusLabels((array) ($filters['status_ids'] ?? [6]))
            )],
            ['Balance Source', $balanceSource],
            ['Generated At (Eastern)', now(config('app.display_timezone', 'America/Toronto'))->format('Y-m-d H:i:s T')],
            ['Opening Balance', $overallOpeningBalance],
            ['Exported Cash Ledger Amount Total', $overallSelectedNet],
            ['Cash Ledger Period Net', $cashLedgerPeriodNet],
            ['Closing Balance', $cashLedgerClosingBalance],
            ['Customer Filter', $filters['customer_name'] ?? 'All customers'],
            ['Plan Account Filter', $filters['plan_account_id'] ?? 'All plan accounts'],
            ['Transaction ID Filter', $filters['trx_id'] ?? 'All transaction IDs'],
            ['Source ID Filter', $filters['source_id'] ?? 'All source IDs'],
            ['Date From', $filters['date_from'] ?? 'Inception'],
            ['Date To', $filters['date_to'] ?? 'Latest available'],
            ['Date Basis', $this->dateBasisLabel((string) ($filters['date_basis'] ?? 'settlement_date'))],
            ['Output Order', ($filters['output_order'] ?? 'desc') === 'asc' ? 'Earliest first' : 'Latest first'],
            ['Currency', $this->currencyLabel((string) ($filters['currency_code'] ?? '00'))],
            ['Cash Transaction Statuses', $this->statusLabels((array) ($filters['status_ids'] ?? [6]))],
            ['Transaction Types', !empty($filters['trx_type']) ? implode(', ', $filters['trx_type']) : 'All types'],
            ['Search', $search !== '' ? $search : 'None'],
        ];

        foreach ($summaryRows as $index => $summaryRow) {
            $style = $index === 0 ? $headerStyle : null;
            if (in_array($index, [8, 9, 10, 11], true)) {
                $writer->addRow(new Row([
                    new StringCell((string) $summaryRow[0], $style),
                    new NumericCell((float) $summaryRow[1], $currencyStyle),
                ]));
            } else {
                $writer->addRow(Row::fromValues($summaryRow, $style));
            }
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'Sheet', 'Date Range', 'Transactions', 'Opening Balance',
            'Exported Cash Ledger Total', 'Cash Ledger Net', 'Closing Balance',
        ], $headerStyle));

        foreach ($sheetSummaries as $summary) {
            $writer->addRow(new Row([
                new StringCell($summary['name'], null),
                new StringCell($this->rangeLabel($summary['first_date'], $summary['last_date']), null),
                new NumericCell($summary['rows'], null),
                new NumericCell($summary['opening'], $currencyStyle),
                new NumericCell($summary['exported_net'], $currencyStyle),
                new NumericCell($summary['cash_ledger_net'], $currencyStyle),
                new NumericCell($summary['closing'], $currencyStyle),
            ]));
        }
    }

    /**
     * Attach the Daily Net report's selected-date cash movement to each
     * date-based sheet. A date is assigned once even in the exceptional case
     * where more than one sheet is required for a single day.
     */
    private function addCashLedgerNetsToSheetPlans(array $plans, array $dailyBalanceRows): array
    {
        foreach ($plans as $index => $plan) {
            $plans[$index]['cash_ledger_net'] = 0.0;
        }

        $assignedDates = [];
        foreach ($dailyBalanceRows as $row) {
            $date = (string) ($row['report_date'] ?? '');
            if ($date === '' || isset($assignedDates[$date])) {
                continue;
            }

            foreach ($plans as $index => $plan) {
                $fromDate = $plan['from_date'] ?? null;
                $toDate = $plan['to_date'] ?? null;
                if ($fromDate && $toDate && $date >= $fromDate && $date <= $toDate) {
                    $plans[$index]['cash_ledger_net'] += (float) ($row['daily_net_transactions'] ?? 0);
                    $assignedDates[$date] = true;
                    break;
                }
            }
        }

        return $plans;
    }

    private function addRunningBalancesToSheetPlans(array $plans, float $openingBalance): array
    {
        $runningBalance = $openingBalance;
        foreach ($plans as $index => $plan) {
            $plans[$index]['opening_balance'] = $runningBalance;
            $runningBalance = round(($runningBalance + (float) $plan['cash_ledger_net']) * 10000) / 10000;
            $plans[$index]['closing_balance'] = $runningBalance;
        }

        return $plans;
    }

    /**
     * Build sheet boundaries from daily totals so normal boundaries never split
     * a selected report date. The 65,000-row target is flexible; the Excel-safe maximum
     * remains a hard boundary for unusually large result sets.
     *
     * @return array<int, array{name: string, from_date: ?string, to_date: ?string, expected_rows: int}>
     */
    private function buildSheetPlans(
        $dailyStats,
        bool $distributeSheets,
        int $splitTargetRows,
        int $maximumRowsPerSheet,
        ?string $preferredFromDate,
        ?string $preferredToDate
    ): array {
        if ($dailyStats->isEmpty()) {
            return [[
                'name' => $this->sheetDateRangeName($preferredFromDate, $preferredToDate),
                'from_date' => $preferredFromDate,
                'to_date' => $preferredToDate,
                'expected_rows' => 0,
            ]];
        }

        $totalRows = (int) $dailyStats->sum(fn($day) => (int) $day->transaction_count);
        if (!$distributeSheets && $totalRows <= $maximumRowsPerSheet) {
            $fromDate = $preferredFromDate
                ?: Carbon::parse($dailyStats->first()->transaction_date)->toDateString();
            $toDate = $preferredToDate
                ?: Carbon::parse($dailyStats->last()->transaction_date)->toDateString();

            return [[
                'name' => $this->sheetDateRangeName($fromDate, $toDate),
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'expected_rows' => $totalRows,
            ]];
        }

        $targetRows = $distributeSheets ? $splitTargetRows : $maximumRowsPerSheet;
        $plans = [];
        $current = null;
        $flush = static function () use (&$plans, &$current): void {
            if ($current !== null) {
                $plans[] = $current;
                $current = null;
            }
        };

        foreach ($dailyStats as $day) {
            $date = Carbon::parse($day->transaction_date)->toDateString();
            $dayRows = (int) $day->transaction_count;

            if ($dayRows > $maximumRowsPerSheet) {
                $flush();
                for ($remaining = $dayRows; $remaining > 0; $remaining -= $maximumRowsPerSheet) {
                    $plans[] = [
                        'from_date' => $date,
                        'to_date' => $date,
                        'expected_rows' => min($maximumRowsPerSheet, $remaining),
                    ];
                }
                continue;
            }

            if ($current === null) {
                $current = [
                    'from_date' => $date,
                    'to_date' => $date,
                    'expected_rows' => $dayRows,
                ];
            } else {
                $combinedRows = $current['expected_rows'] + $dayRows;
                if ($combinedRows <= $targetRows) {
                    $current['to_date'] = $date;
                    $current['expected_rows'] = $combinedRows;
                } else {
                    $underTargetDistance = $targetRows - $current['expected_rows'];
                    $overTargetDistance = $combinedRows - $targetRows;
                    $includeWholeDay = $distributeSheets
                        && $combinedRows <= $maximumRowsPerSheet
                        && $overTargetDistance <= $underTargetDistance;

                    if ($includeWholeDay) {
                        $current['to_date'] = $date;
                        $current['expected_rows'] = $combinedRows;
                        $flush();
                    } else {
                        $flush();
                        $current = [
                            'from_date' => $date,
                            'to_date' => $date,
                            'expected_rows' => $dayRows,
                        ];
                    }
                }
            }

            if ($current !== null && $current['expected_rows'] >= $targetRows) {
                $flush();
            }
        }
        $flush();

        $usedNames = [];
        foreach ($plans as $index => $plan) {
            $baseName = $this->sheetDateRangeName($plan['from_date'], $plan['to_date']);
            $occurrence = ($usedNames[$baseName] ?? 0) + 1;
            $usedNames[$baseName] = $occurrence;
            $suffix = $occurrence > 1 ? ' (' . $occurrence . ')' : '';
            $plans[$index]['name'] = mb_substr($baseName, 0, 31 - mb_strlen($suffix)) . $suffix;
        }

        return $plans;
    }

    private function sheetDateRangeName(?string $fromDate, ?string $toDate): string
    {
        if (!$fromDate && !$toDate) {
            return 'Transactions';
        }

        $from = Carbon::parse($fromDate ?: $toDate);
        $to = Carbon::parse($toDate ?: $fromDate);
        if ($from->isSameDay($to)) {
            return $from->format('M d');
        }
        if ($from->year !== $to->year) {
            return $from->format('M d Y') . ' - ' . $to->format('M d Y');
        }
        if ($from->month === $to->month) {
            return $from->format('M d') . ' - ' . $to->format('d');
        }

        return $from->format('M d') . ' - ' . $to->format('M d');
    }

    private function writeStatus(string $statusFile, array $payload): void
    {
        if ($statusFile === '') {
            return;
        }

        $directory = dirname($statusFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $existing = is_file($statusFile)
            ? json_decode((string) @file_get_contents($statusFile), true)
            : null;
        $status = array_merge(is_array($existing) ? $existing : [], [
            'run_id' => $this->runId,
            'worker_pid' => getmypid(),
        ], $payload);
        $encoded = json_encode($status, JSON_PRETTY_PRINT);
        $temporaryFile = $encoded !== false ? tempnam($directory, basename($statusFile) . '.tmp.') : false;
        if ($temporaryFile === false) {
            return;
        }

        if (@file_put_contents($temporaryFile, $encoded, LOCK_EX) === false || !@rename($temporaryFile, $statusFile)) {
            @unlink($temporaryFile);
        }
    }

    private function stringOption(string $name): string
    {
        return trim((string) ($this->option($name) ?? ''));
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : '';
    }

    private function normalizeDateBasis(string $basis): string
    {
        return in_array($basis, ['create_date', 'trade_date', 'processing_date', 'settlement_date'], true)
            ? $basis
            : 'settlement_date';
    }

    private function dateBasisLabel(string $basis): string
    {
        return match ($basis) {
            'create_date' => 'Created date',
            'trade_date' => 'Trade date',
            'processing_date' => 'Processing date',
            default => 'Settlement date',
        };
    }

    private function currencyLabel(string $currencyCode): string
    {
        return match ($currencyCode) {
            '00' => 'CAD',
            '01' => 'USD',
            default => $currencyCode !== '' ? $currencyCode : '—',
        };
    }

    private function statusLabels(array $statusIds): string
    {
        $labels = [
            0 => 'Deleted',
            1 => 'Rejected',
            2 => 'Cancelled',
            3 => 'Pending',
            4 => 'Accepted',
            5 => 'Contracted',
            6 => 'Confirmed',
        ];

        return implode(', ', array_map(
            fn($status) => $labels[(int) $status] ?? (string) $status,
            $statusIds
        ));
    }

    private function rangeLabel(?string $fromDate, ?string $toDate): string
    {
        if (!$fromDate) {
            return 'No matching transactions';
        }

        return $fromDate === $toDate || !$toDate
            ? $fromDate
            : $fromDate . ' through ' . $toDate;
    }

}
