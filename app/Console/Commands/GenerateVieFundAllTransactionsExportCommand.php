<?php

namespace App\Console\Commands;

use App\Models\SettlementInstruction;
use App\Services\Reconciliation\AgraFspBankMatcher;
use App\Services\Reconciliation\EftBankMatchStatusService;
use App\Services\Reconciliation\FspMatchedRecordCollapser;
use App\Services\Reconciliation\TransactionBankMatchStatusService;
use App\Services\VieFund\VieFundExportLinkCache;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundDailyBalanceService;
use App\Services\VieFund\VieFundTransactionWorkingSetManager;
use App\Services\VieFund\VieFundTransactionWorkingSetQuery;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use App\Support\AllTransactionColumns;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class GenerateVieFundAllTransactionsExportCommand extends Command
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';
    private const BANK_PARSER_VERSION = 'v2';

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
        {--match-status=*}
        {--transaction-type=*}
        {--has-eft-match : Include only transactions linked to an EFT item}
        {--has-agra-fsp-match : Include only transactions whose fund SourceID is present in an AGRA FSP item}
        {--has-7960-fsp-match : Include only transactions whose fund SourceID is present in a 7960 FSP item}
        {--include-eft-records : Add unique linked EFT records and transaction-sheet links}
        {--include-bank-records : Add unique linked bank records and transaction-sheet links}
        {--include-fsp-records : Include matched AGRA FSP details in transaction columns or a separate sheet}
        {--include-7960-fsp-records : Include matched 7960 FSP details in transaction columns or a separate sheet}
        {--linked-record-layout=split : Write linked records into the transaction sheet or separate EFT, Bank, and FSP sheets}
        {--split-sheets : Distribute transactions into date-based sheets near the configured target size}
        {--output-base= : Storage-relative output path without an extension}
        {--status-file= : Absolute progress status path}
        {--lock-file= : Absolute lock path}';

    protected $description = 'Stream the VieFund All Transactions result set to a multi-sheet Excel workbook';

    private string $runId = '';

    public function handle(
        VieFundRemoteService $remoteService,
        VieFundDailyBalanceService $dailyBalanceService,
        SqlServerEftRemoteRepository $eftRepository,
        AgraFspBankMatcher $agraFspBankMatcher,
        EftBankMatchStatusService $eftBankMatchStatusService,
        TransactionBankMatchStatusService $transactionBankMatchStatusService,
        FspMatchedRecordCollapser $fspRecordCollapser,
        VieFundExportLinkCache $linkCache,
        VieFundTransactionWorkingSetManager $workingSetManager,
        VieFundTransactionWorkingSetQuery $workingSetQuery
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
        $stageTimings = [
            'planning' => 0.0,
            'transaction_queries' => 0.0,
            'eft_bank_matching' => 0.0,
            'fsp_bank_matching' => 0.0,
            'worksheet_rows' => 0.0,
            'finalizing' => 0.0,
        ];

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
            $matchStatuses = array_values(array_intersect(
                ['Complete', 'Verify', 'Possible', 'Unknown'],
                (array) $this->option('match-status')
            ));
            $hasAgraFspMatch = (bool) $this->option('has-agra-fsp-match');
            $has7960FspMatch = (bool) $this->option('has-7960-fsp-match');
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
                'has_reconciliation_match' => (bool) $this->option('has-eft-match'),
                'has_agra_fsp_match' => $hasAgraFspMatch,
                'has_7960_fsp_match' => $has7960FspMatch,
                'match_statuses' => $matchStatuses,
            ]);
            $workingSet = $workingSetManager->findReady($filters);
            if (!$workingSet && $hasAgraFspMatch) {
                $filters['agra_fsp_source_ids_json'] = $this->fspSourceIdsJson(
                    'fundserv_agra',
                    $dateBasis,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }
            if (!$workingSet && $has7960FspMatch) {
                $filters['fsp_7960_source_ids_json'] = $this->fspSourceIdsJson(
                    'ltm',
                    $dateBasis,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }
            $maximumRowsPerSheet = (int) config('viefund.all_transactions_export_rows_per_sheet', 1000000);
            $splitTargetRows = (int) config('viefund.all_transactions_export_split_target_rows', 65000);
            $databaseBatchSize = (int) config('viefund.all_transactions_export_batch_size', 5000);
            $distributeSheets = (bool) $this->option('split-sheets');
            $includeEftRecords = (bool) $this->option('include-eft-records');
            $includeBankRecords = (bool) $this->option('include-bank-records');
            $linkedRecordLayout = $this->stringOption('linked-record-layout') === 'single' ? 'single' : 'split';
            $separateLinkedRecordSheets = $linkedRecordLayout === 'split';
            $includeFspRecords = (bool) $this->option('include-fsp-records');
            $include7960FspRecords = (bool) $this->option('include-7960-fsp-records');
            $includeAnyFspRecords = $includeFspRecords || $include7960FspRecords;
            $includedFspSourceTypes = array_values(array_filter([
                $includeFspRecords ? 'fundserv_agra' : null,
                $include7960FspRecords ? 'ltm' : null,
            ]));
            if ($includeBankRecords && $includedFspSourceTypes === []) {
                $includedFspSourceTypes = ['fundserv_agra', 'ltm'];
            }
            $statusFspSourceTypes = ['fundserv_agra', 'ltm'];

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => 'Reviewing the matching transaction dates...',
                'progress_pct' => 2,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);

            $stageStartedAt = microtime(true);
            $dailyStats = $workingSet
                ? $workingSetQuery->dailyStats($workingSet, $search ?: null, $filters)
                : $remoteService->fetchAllTransactionExportDailyStats($search ?: null, $filters);
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
            if ($separateLinkedRecordSheets && count($sheetPlans) === 1) {
                $sheetPlans[0]['name'] = 'Transactions';
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
            $linkStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontColor('0563C1')
                ->setFontUnderline();
            $eftHeaderStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontBold()
                ->setBackgroundColor('D9F0FB');
            $bankHeaderStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontBold()
                ->setBackgroundColor('DFF3E8');
            $fspHeaderStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontBold()
                ->setBackgroundColor('EADCF4');
            $eftDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setBackgroundColor('F0F9FD')
                ->setShouldWrapText();
            $bankDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setBackgroundColor('F1FAF5')
                ->setShouldWrapText();
            $bankAmountDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setBackgroundColor('F1FAF5')
                ->setShouldWrapText()
                ->setCellAlignment(CellAlignment::RIGHT);
            $fspDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setBackgroundColor('FAF6FD')
                ->setShouldWrapText();
            $fspAmountDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setBackgroundColor('FAF6FD')
                ->setShouldWrapText()
                ->setCellAlignment(CellAlignment::RIGHT);
            $wireFeeBankDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontColor('975A16')
                ->setBackgroundColor('FFFAF0')
                ->setShouldWrapText();
            $wireFeeBankAmountDetailStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontColor('975A16')
                ->setBackgroundColor('FFFAF0')
                ->setShouldWrapText()
                ->setCellAlignment(CellAlignment::RIGHT);
            $wireFeeCurrencyStyle = (new Style())
                ->setFontName('Calibri')
                ->setFontSize(11)
                ->setFontColor('975A16')
                ->setBackgroundColor('FFFAF0')
                ->setFormat(self::ACCOUNTING_CURRENCY_FORMAT);
            $matchRowStyles = $this->matchStatusStyles(
                (new Style())->setFontName('Calibri')->setFontSize(11),
                true
            );
            $eftMatchStyles = $this->matchStatusStyles($eftDetailStyle);
            $bankMatchStyles = $this->matchStatusStyles($bankDetailStyle);
            $bankAmountMatchStyles = $this->matchStatusStyles($bankAmountDetailStyle);
            $fspMatchStyles = $this->matchStatusStyles($fspDetailStyle);
            $fspAmountMatchStyles = $this->matchStatusStyles($fspAmountDetailStyle);
            $wireFeeMatchStyles = $this->matchStatusStyles($wireFeeBankDetailStyle);
            $wireFeeAmountMatchStyles = $this->matchStatusStyles($wireFeeBankAmountDetailStyle);
            $wireFeeCurrencyMatchStyles = $this->matchStatusStyles($wireFeeCurrencyStyle);
            $transactionHeaders = $this->transactionHeaders(
                $includeEftRecords,
                $includeBankRecords,
                $includeAnyFspRecords,
                $separateLinkedRecordSheets
            );
            $visibleTransactionColumnKeys = AllTransactionColumns::visibleKeys();
            $linkedColumnInsertionIndex = AllTransactionColumns::linkedColumnInsertionIndex();
            $bankDescriptionColumn = array_search('Bank Description', $transactionHeaders, true);
            $bankDescriptionColumn = $bankDescriptionColumn === false ? null : $bankDescriptionColumn + 1;

            $transactionSheets = [];
            foreach ($sheetPlans as $index => $plan) {
                $transactionSheet = $index === 0
                    ? $writer->getCurrentSheet()
                    : $writer->addNewSheetAndMakeItCurrent();
                $this->prepareTransactionSheet(
                    $writer,
                    $transactionSheet,
                    $plan['name'],
                    $headerStyle,
                    $eftHeaderStyle,
                    $bankHeaderStyle,
                    $fspHeaderStyle,
                    $transactionHeaders
                );
                $transactionSheets[] = $transactionSheet;
            }

            $eftRecordsSheet = null;
            if ($includeEftRecords && $separateLinkedRecordSheets) {
                $eftRecordsSheet = $writer->addNewSheetAndMakeItCurrent();
                $this->prepareEftRecordsSheet($writer, $eftRecordsSheet, $headerStyle);
            }
            $bankRecordsSheet = null;
            if ($includeBankRecords && $separateLinkedRecordSheets) {
                $bankRecordsSheet = $writer->addNewSheetAndMakeItCurrent();
                $this->prepareBankRecordsSheet($writer, $bankRecordsSheet, $headerStyle);
            }
            $fspRecordsSheet = null;
            if ($includeAnyFspRecords && $separateLinkedRecordSheets) {
                $fspRecordsSheet = $writer->addNewSheetAndMakeItCurrent();
                $this->prepareFspRecordsSheet($writer, $fspRecordsSheet, $fspHeaderStyle);
            }

            $sheetNumber = 1;
            $sheetPlanIndex = 0;
            $currentSheetPlan = $sheetPlans[$sheetPlanIndex];
            $sheet = $transactionSheets[$sheetPlanIndex];
            $writer->setCurrentSheet($sheet);
            $sheetRowCount = 0;
            $sheetOpeningBalance = $currentSheetPlan['opening_balance'];
            $sheetNet = 0.0;
            $sheetSummaries = [];
            $processedTransactions = 0;
            $cursor = null;
            $eftSheetRowByItemId = [];
            $bankSheetRowByEntryId = [];
            $fspSheetRowByItemId = [];
            $eftRecordCount = 0;
            $bankRecordCount = 0;
            $fspRecordCount = 0;
            $bankSheetDescriptionWidth = 42.0;
            $transactionBankDescriptionWidths = [];
            $bankEntriesBySequenceCache = collect();
            $fspBankMatchCache = collect();
            $stageTimings['planning'] += microtime(true) - $stageStartedAt;

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
                $stageStartedAt = microtime(true);
                $rows = $workingSet
                    ? $workingSetQuery->exportRowsAfter(
                        $workingSet,
                        $search ?: null,
                        $filters,
                        $cursor,
                        $databaseBatchSize
                    )
                    : $remoteService->fetchAllTransactionExportRowsAfter(
                        $search ?: null,
                        $filters,
                        $cursor,
                        $databaseBatchSize
                    );
                $stageTimings['transaction_queries'] += microtime(true) - $stageStartedAt;

                $linkedEftItems = collect();
                $eftItemsByTrust = collect();
                $bankEntriesBySequence = collect();
                $fspItemsByCashTransaction = collect();
                $fspBankEntriesByCashTransaction = collect();
                if ($rows->isNotEmpty()) {
                    $stageStartedAt = microtime(true);
                    $this->writeStatus($statusFile, [
                        'inProgress' => true,
                        'success' => null,
                        'message' => $workingSet
                            ? sprintf(
                                'Loading cached EFT and bank records for the next %s transactions...',
                                number_format($rows->count())
                            )
                            : sprintf(
                                'Matching EFT%s records for the next %s transactions...',
                                $includeBankRecords ? ' and bank' : '',
                                number_format($rows->count())
                            ),
                        'progress_pct' => $totalTransactions > 0
                            ? min(98, max(3, (int) floor(($processedTransactions / $totalTransactions) * 98)))
                            : 3,
                        'processed_transactions' => $processedTransactions,
                        'total_transactions' => $totalTransactions,
                        'started_at' => $startedAtIso,
                        'updated_at' => now()->toIso8601String(),
                    ]);

                    if ($workingSet) {
                        $linkedEftItems = $rows
                            ->flatMap(fn($row) => $this->cachedEnrichmentRecords($row, 'eft_items'))
                            ->unique('id')
                            ->values();
                        $eftItemsByTrust = $linkedEftItems->groupBy(fn($item) => (string) (int) $item->linked_id);
                        $bankEntriesBySequence = $rows
                            ->flatMap(fn($row) => $this->cachedEnrichmentRecords($row, 'eft_bank_entries'))
                            ->unique('id')
                            ->groupBy(fn($entry) => ctype_digit(trim((string) ($entry->settlement_number ?? '')))
                                ? (string) (int) trim((string) $entry->settlement_number)
                                : trim((string) ($entry->settlement_number ?? '')));
                    } else {
                        $trustIds = $rows
                            ->pluck('trust_transaction_id')
                            ->filter()
                            ->map(fn($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();
                        $linkedEftItems = $linkCache->eftItemsByLinkedIds($trustIds, $eftRepository);
                        $eftBankMatchStatuses = $eftBankMatchStatusService->statusesForItems($linkedEftItems);
                        $linkedEftItems->each(function ($item) use ($eftBankMatchStatuses) {
                            $sequence = $item->sequence_number !== null
                                && ctype_digit((string) $item->sequence_number)
                                    ? (string) (int) $item->sequence_number
                                    : null;
                            $item->bank_match_status = $sequence !== null
                                ? $eftBankMatchStatuses->get($sequence, EftBankMatchStatusService::UNKNOWN)
                                : EftBankMatchStatusService::UNKNOWN;
                        });
                        $eftItemsByTrust = $linkedEftItems->groupBy(fn($item) => (string) (int) $item->linked_id);

                        if ($includeBankRecords) {
                            $bankEntriesBySequence = $this->cachedBankEntriesForSequences(
                                $linkedEftItems->pluck('sequence_number')->filter()->all(),
                                $bankEntriesBySequenceCache
                            );
                            $eftBankMatchStatusService->annotateBankEntries($bankEntriesBySequence->flatten(1));
                        }
                    }
                    if ($includeEftRecords && $eftRecordsSheet instanceof Sheet) {
                        $this->appendEftRecords(
                            $writer,
                            $eftRecordsSheet,
                            $linkedEftItems,
                            $currencyStyle,
                            $eftSheetRowByItemId,
                            $eftRecordCount
                        );
                    }
                    if ($includeBankRecords && $bankRecordsSheet instanceof Sheet) {
                        $this->appendBankRecords(
                            $writer,
                            $bankRecordsSheet,
                            $bankEntriesBySequence->flatten(1),
                            $currencyStyle,
                            $wireFeeBankDetailStyle,
                            $wireFeeCurrencyStyle,
                            $bankSheetRowByEntryId,
                            $bankRecordCount,
                            $bankSheetDescriptionWidth
                        );
                    }
                    if (!$separateLinkedRecordSheets && $includeEftRecords) {
                        foreach ($linkedEftItems->unique('id') as $item) {
                            $itemId = (int) $item->id;
                            if (!isset($eftSheetRowByItemId[$itemId])) {
                                $eftSheetRowByItemId[$itemId] = true;
                                ++$eftRecordCount;
                            }
                        }
                    }
                    if (!$separateLinkedRecordSheets && $includeBankRecords) {
                        foreach ($bankEntriesBySequence->flatten(1)->unique('id') as $entry) {
                            $entryId = (int) $entry->id;
                            if (!isset($bankSheetRowByEntryId[$entryId])) {
                                $bankSheetRowByEntryId[$entryId] = true;
                                ++$bankRecordCount;
                            }
                        }
                    }
                    $writer->setCurrentSheet($sheet);
                    $stageTimings['eft_bank_matching'] += microtime(true) - $stageStartedAt;
                }

                if ($rows->isNotEmpty()) {
                    $stageStartedAt = microtime(true);
                    $this->writeStatus($statusFile, [
                        'inProgress' => true,
                        'success' => null,
                        'message' => sprintf(
                            $workingSet
                                ? 'Loading cached FSP records for the next %s transactions...'
                                : 'Matching selected FSP records for the next %s transactions...',
                            number_format($rows->count())
                        ),
                        'progress_pct' => $totalTransactions > 0
                            ? min(98, max(3, (int) floor(($processedTransactions / $totalTransactions) * 98)))
                            : 3,
                        'processed_transactions' => $processedTransactions,
                        'total_transactions' => $totalTransactions,
                        'started_at' => $startedAtIso,
                        'updated_at' => now()->toIso8601String(),
                    ]);
                    if ($workingSet) {
                        $fspItemsByCashTransaction = $rows->mapWithKeys(fn($row) => [
                            (string) (int) $row->cash_transaction_id => $this->cachedEnrichmentRecords($row, 'fsp_items'),
                        ]);
                        $fspBankEntriesByCashTransaction = $rows->mapWithKeys(fn($row) => [
                            (string) (int) $row->cash_transaction_id => $this->cachedEnrichmentRecords($row, 'fsp_bank_entries'),
                        ]);
                    } else {
                        $fspItemsByCashTransaction = $this->fspItemsByCashTransaction(
                            $rows,
                            $remoteService,
                            $linkCache,
                            $statusFspSourceTypes,
                            $fspRecordCollapser
                        );
                        $fspBankEntriesByCashTransaction = $this->fspBankEntriesByCashTransaction(
                            $fspItemsByCashTransaction,
                            $agraFspBankMatcher,
                            $fspBankMatchCache,
                            $transactionBankMatchStatusService
                        );
                    }
                    if ($includeAnyFspRecords) {
                        $linkedFspItems = $fspItemsByCashTransaction->flatten(1)
                            ->filter(fn($item) => in_array((string) $item->source_type, $includedFspSourceTypes, true))
                            ->unique('id')
                            ->values();
                        if ($fspRecordsSheet instanceof Sheet) {
                            $this->appendFspRecords(
                                $writer,
                                $fspRecordsSheet,
                                $linkedFspItems,
                                $fspDetailStyle,
                                $fspAmountDetailStyle,
                                $fspSheetRowByItemId,
                                $fspRecordCount
                            );
                        } else {
                            foreach ($linkedFspItems as $fspItem) {
                                $fspItemId = (int) $fspItem->id;
                                if (!isset($fspSheetRowByItemId[$fspItemId])) {
                                    $fspSheetRowByItemId[$fspItemId] = true;
                                    ++$fspRecordCount;
                                }
                            }
                        }
                    }
                    if ($includeBankRecords) {
                        $fspBankEntries = $fspBankEntriesByCashTransaction->flatten(1)->unique('id')->values();
                        if ($bankRecordsSheet instanceof Sheet) {
                            $this->appendBankRecords(
                                $writer,
                                $bankRecordsSheet,
                                $fspBankEntries,
                                $currencyStyle,
                                $wireFeeBankDetailStyle,
                                $wireFeeCurrencyStyle,
                                $bankSheetRowByEntryId,
                                $bankRecordCount,
                                $bankSheetDescriptionWidth
                            );
                        } elseif (!$separateLinkedRecordSheets) {
                            foreach ($fspBankEntries as $entry) {
                                $entryId = (int) $entry->id;
                                if (!isset($bankSheetRowByEntryId[$entryId])) {
                                    $bankSheetRowByEntryId[$entryId] = true;
                                    ++$bankRecordCount;
                                }
                            }
                        }
                    }
                    $writer->setCurrentSheet($sheet);
                    $stageTimings['fsp_bank_matching'] += microtime(true) - $stageStartedAt;
                }

                $stageStartedAt = microtime(true);
                foreach ($rows as $row) {
                    if (
                        $sheetRowCount >= $currentSheetPlan['expected_rows']
                        && $sheetPlanIndex < count($sheetPlans) - 1
                    ) {
                        $this->finalizeTransactionSheet($sheet, $sheetRowCount, $transactionHeaders);
                        $sheetSummary = $this->sheetSummary(
                            $sheetNumber, $currentSheetPlan['name'], $sheetRowCount,
                            $currentSheetPlan['from_date'], $currentSheetPlan['to_date'],
                            $sheetOpeningBalance, $sheetNet, $currentSheetPlan['cash_ledger_net']
                        );
                        $sheetSummaries[] = $sheetSummary;
                        ++$sheetNumber;
                        ++$sheetPlanIndex;
                        $currentSheetPlan = $sheetPlans[$sheetPlanIndex];
                        $sheet = $transactionSheets[$sheetPlanIndex];
                        $writer->setCurrentSheet($sheet);
                        $sheetRowCount = 0;
                        $sheetOpeningBalance = $currentSheetPlan['opening_balance'];
                        $sheetNet = 0.0;
                    }

                    $amount = (float) ($row->amount ?? 0);
                    $createdDate = $this->dateTime($row->created_date ?? null);
                    $trustId = !empty($row->trust_transaction_id) ? (string) (int) $row->trust_transaction_id : '';
                    $transactionEftItems = collect($eftItemsByTrust->get($trustId, collect()))->values();
                    $transactionBankEntries = $transactionEftItems
                        ->pluck('sequence_number')
                        ->filter()
                        ->map(fn($sequence) => (string) (int) $sequence)
                        ->unique()
                        ->flatMap(fn($sequence) => collect($bankEntriesBySequence->get($sequence, collect())))
                        ->unique('id')
                        ->values();
                    $transactionAllFspItems = collect($fspItemsByCashTransaction->get((string) (int) $row->cash_transaction_id, collect()))
                        ->values();
                    $transactionFspItems = $transactionAllFspItems
                        ->filter(fn($item) => in_array((string) $item->source_type, $includedFspSourceTypes, true))
                        ->values();
                    $transactionBankEntries = collect($fspBankEntriesByCashTransaction->get((string) (int) $row->cash_transaction_id, collect()))
                        ->concat($transactionBankEntries)
                        ->unique('id')
                        ->values();
                    $isPossibleWireFeeMatch = $transactionBankEntries
                        ->contains(fn($entry) => (bool) ($entry->is_possible_wire_fee_match ?? false));
                    $matchedToBankStatus = $transactionBankMatchStatusService->combine(
                        $transactionBankMatchStatusService->summarizeEftItems($transactionEftItems),
                        $transactionBankMatchStatusService->summarizeFspItems($transactionAllFspItems)
                    );
                    $matchRowStyle = $this->matchStatusStyle($matchRowStyles, $matchedToBankStatus);
                    $transactionBankDetailStyle = $this->matchStatusStyle(
                        $isPossibleWireFeeMatch ? $wireFeeMatchStyles : $bankMatchStyles,
                        $matchedToBankStatus
                    );
                    $transactionBankAmountStyle = $this->matchStatusStyle(
                        $isPossibleWireFeeMatch ? $wireFeeAmountMatchStyles : $bankAmountMatchStyles,
                        $matchedToBankStatus
                    );

                    $coreCells = [
                        'matched_to_bank' => new StringCell($matchedToBankStatus, $matchRowStyle),
                        'cash_transaction_id' => new StringCell((string) ($row->transaction_id ?? ''), $matchRowStyle),
                        'fund_transaction_id' => new StringCell(!empty($row->fund_transaction_id) ? 'F-' . $row->fund_transaction_id : '', $matchRowStyle),
                        'trust_transaction_id' => new StringCell(!empty($row->trust_transaction_id) ? 'T-' . $row->trust_transaction_id : '', $matchRowStyle),
                        'ledger_relationship' => new StringCell((string) ($row->ledger_relationship ?? ''), $matchRowStyle),
                        // Source IDs can exceed Excel's 15-digit numeric precision.
                        'source_id' => new StringCell((string) ($row->source_id ?? ''), $matchRowStyle),
                        'customer_name' => new StringCell(trim((string) ($row->customer_name ?? '')), $matchRowStyle),
                        'plan_account_id' => new StringCell((string) ($row->plan_account_id ?? ''), $matchRowStyle),
                        'transaction_type' => new StringCell((string) ($row->transaction_type ?? ''), $matchRowStyle),
                        'status' => new StringCell((string) ($row->status ?? ''), $matchRowStyle),
                        'trust_status' => new StringCell((string) ($row->trust_status ?? ''), $matchRowStyle),
                        'notes' => new StringCell((string) ($row->notes ?? ''), $matchRowStyle),
                        'created_date' => new StringCell($createdDate, $matchRowStyle),
                        'trade_date' => new StringCell($this->dateTime($row->trade_date ?? null), $matchRowStyle),
                        'processing_date' => new StringCell($this->dateTime($row->processing_date ?? null), $matchRowStyle),
                        'settlement_date' => new StringCell($this->dateTime($row->settlement_date ?? null), $matchRowStyle),
                        'currency_code' => new StringCell($this->currencyLabel((string) ($row->currency_code ?? '')), $matchRowStyle),
                        'amount' => new NumericCell(
                            $amount,
                            $isPossibleWireFeeMatch
                                ? $this->matchStatusStyle($wireFeeCurrencyMatchStyles, $matchedToBankStatus)
                                : $currencyStyle
                        ),
                    ];
                    $transactionCells = array_map(
                        fn(string $key) => $coreCells[$key],
                        $visibleTransactionColumnKeys
                    );
                    if (!$separateLinkedRecordSheets && $includeBankRecords) {
                        $bankSummaryCells = $transactionBankEntries->isNotEmpty()
                            ? $this->bankTransactionSummaryCells(
                                $transactionBankEntries,
                                $transactionBankDetailStyle,
                                $transactionBankAmountStyle
                            )
                            : $this->emptyDetailCells(
                                count($this->bankTransactionSummaryHeaders()),
                                $this->matchStatusStyle($bankMatchStyles, $matchedToBankStatus)
                            );
                        array_splice(
                            $transactionCells,
                            AllTransactionColumns::matchSummaryInsertionIndex(),
                            0,
                            $bankSummaryCells
                        );
                    }
                    $linkedCells = [];
                    if ($includeEftRecords && $separateLinkedRecordSheets) {
                        $firstEftItem = $transactionEftItems->first();
                        $targetRow = $firstEftItem ? ($eftSheetRowByItemId[(int) $firstEftItem->id] ?? null) : null;
                        $label = $transactionEftItems->count() === 1
                            ? 'EFT item #' . (int) $firstEftItem->id
                            : ($transactionEftItems->isNotEmpty() ? number_format($transactionEftItems->count()) . ' EFT records' : '');
                        $linkedCells[] = $this->internalHyperlinkCell('EFT', $targetRow, $label, $linkStyle);
                    }
                    if ($includeBankRecords && $separateLinkedRecordSheets) {
                        $firstBankEntry = $transactionBankEntries->first();
                        $targetRow = $firstBankEntry ? ($bankSheetRowByEntryId[(int) $firstBankEntry->id] ?? null) : null;
                        $label = $transactionBankEntries->count() === 1
                            ? 'Bank txn #' . (int) $firstBankEntry->id
                            : ($transactionBankEntries->isNotEmpty() ? number_format($transactionBankEntries->count()) . ' bank transactions' : '');
                        $linkedCells[] = $this->internalHyperlinkCell('Bank', $targetRow, $label, $linkStyle);
                    }
                    if ($includeAnyFspRecords && $separateLinkedRecordSheets) {
                        $firstFspItem = $transactionFspItems->first();
                        $targetRow = $firstFspItem ? ($fspSheetRowByItemId[(int) $firstFspItem->id] ?? null) : null;
                        $label = $transactionFspItems->count() === 1
                            ? 'FSP record #' . (int) $firstFspItem->id
                            : ($transactionFspItems->isNotEmpty() ? number_format($transactionFspItems->count()) . ' FSP records' : '');
                        $linkedCells[] = $this->internalHyperlinkCell('FSP', $targetRow, $label, $linkStyle);
                    }
                    if ($linkedCells !== []) {
                        array_splice(
                            $transactionCells,
                            $linkedColumnInsertionIndex,
                            0,
                            $linkedCells
                        );
                    }
                    if (!$separateLinkedRecordSheets && $includeEftRecords) {
                        $hasLaterDetails = ($includeBankRecords && $transactionBankEntries->isNotEmpty())
                            || ($includeAnyFspRecords && $transactionFspItems->isNotEmpty());
                        if ($transactionEftItems->isNotEmpty()) {
                            array_push(
                                $transactionCells,
                                ...$this->eftTransactionDetailCells(
                                    $transactionEftItems,
                                    $this->matchStatusStyle($eftMatchStyles, $matchedToBankStatus)
                                )
                            );
                        } elseif ($hasLaterDetails) {
                            array_push(
                                $transactionCells,
                                ...$this->emptyDetailCells(
                                    count($this->eftTransactionDetailHeaders()),
                                    $this->matchStatusStyle($eftMatchStyles, $matchedToBankStatus)
                                )
                            );
                        }
                    }
                    if (!$separateLinkedRecordSheets && $includeBankRecords && (
                        $transactionBankEntries->isNotEmpty()
                        || ($includeAnyFspRecords && $transactionFspItems->isNotEmpty())
                    )) {
                        if ($bankDescriptionColumn !== null && $transactionBankEntries->isNotEmpty()) {
                            $measuredWidth = $this->bankDescriptionWidth($transactionBankEntries);
                            $currentWidth = $transactionBankDescriptionWidths[$sheetPlanIndex] ?? 36.0;
                            if ($measuredWidth > $currentWidth) {
                                $transactionBankDescriptionWidths[$sheetPlanIndex] = $measuredWidth;
                            }
                        }
                        if ($transactionBankEntries->isNotEmpty()) {
                            array_push(
                                $transactionCells,
                                ...$this->bankTransactionDetailCells(
                                    $transactionBankEntries,
                                    $transactionBankDetailStyle,
                                    $transactionBankAmountStyle
                                )
                            );
                        } else {
                            array_push(
                                $transactionCells,
                                ...$this->emptyDetailCells(
                                    count($this->bankTransactionDetailHeaders()),
                                    $this->matchStatusStyle($bankMatchStyles, $matchedToBankStatus)
                                )
                            );
                        }
                    }
                    if (!$separateLinkedRecordSheets && $includeAnyFspRecords && $transactionFspItems->isNotEmpty()) {
                        array_push(
                            $transactionCells,
                            ...$this->fspTransactionDetailCells(
                                $transactionFspItems,
                                $this->matchStatusStyle($fspMatchStyles, $matchedToBankStatus),
                                $this->matchStatusStyle($fspAmountMatchStyles, $matchedToBankStatus)
                            )
                        );
                    }
                    while (count($transactionCells) < count($transactionHeaders)) {
                        $transactionCells[] = new EmptyCell(null, $matchRowStyle);
                    }
                    $writer->addRow(new Row($transactionCells, $matchRowStyle));

                    ++$sheetRowCount;
                    ++$processedTransactions;
                    $sheetNet += $amount;
                }
                $stageTimings['worksheet_rows'] += microtime(true) - $stageStartedAt;

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
                    'timings_seconds' => $this->roundedTimings($stageTimings),
                ]);
            } while ($rows->count() === $databaseBatchSize);

            $stageStartedAt = microtime(true);
            if (!$separateLinkedRecordSheets && $bankDescriptionColumn !== null) {
                foreach ($transactionSheets as $index => $transactionSheet) {
                    $transactionSheet->setColumnWidth(
                        $transactionBankDescriptionWidths[$index] ?? 36.0,
                        $bankDescriptionColumn
                    );
                }
            }
            if ($bankRecordsSheet instanceof Sheet) {
                $bankDescriptionIndex = array_search('Bank Description', $this->bankRecordHeaders(), true);
                if ($bankDescriptionIndex !== false) {
                    $bankRecordsSheet->setColumnWidth($bankSheetDescriptionWidth, $bankDescriptionIndex + 1);
                }
            }

            $this->finalizeTransactionSheet($sheet, $sheetRowCount, $transactionHeaders);
            $sheetSummaries[] = $this->sheetSummary(
                $sheetNumber, $currentSheetPlan['name'], $sheetRowCount,
                $currentSheetPlan['from_date'], $currentSheetPlan['to_date'],
                $sheetOpeningBalance, $sheetNet, $currentSheetPlan['cash_ledger_net']
            );
            if ($eftRecordsSheet instanceof Sheet) {
                $this->finalizeLinkedRecordsSheet($eftRecordsSheet, count($this->eftRecordHeaders()), $eftRecordCount);
            }
            if ($bankRecordsSheet instanceof Sheet) {
                $this->finalizeLinkedRecordsSheet($bankRecordsSheet, count($this->bankRecordHeaders()), $bankRecordCount);
            }
            if ($fspRecordsSheet instanceof Sheet) {
                $this->finalizeLinkedRecordsSheet($fspRecordsSheet, count($this->fspRecordHeaders()), $fspRecordCount);
            }

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
                (float) $balanceReport['final_balance'], (string) $balanceReport['balance_source'],
                $includeEftRecords ? $eftRecordCount : null,
                $includeBankRecords ? $bankRecordCount : null,
                $includeAnyFspRecords ? $fspRecordCount : null,
                $includedFspSourceTypes,
                $linkedRecordLayout
            );

            $writer->close();
            $writerIsOpen = false;
            $stageTimings['finalizing'] += microtime(true) - $stageStartedAt;
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
                'timings_seconds' => $this->roundedTimings($stageTimings),
                'linked_data_cache' => $linkCache->statistics(),
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
                'timings_seconds' => $this->roundedTimings($stageTimings),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($lockFile !== '') {
                @unlink($lockFile);
            }
        }
    }

    private function transactionHeaders(
        bool $includeEftRecords,
        bool $includeBankRecords,
        bool $includeFspRecords,
        bool $separateLinkedRecordSheets
    ): array
    {
        $headers = array_column(AllTransactionColumns::visible(), 'label');
        if ($separateLinkedRecordSheets) {
            $linkedHeaders = [];
            if ($includeEftRecords) {
                $linkedHeaders[] = 'Linked EFT Record';
            }
            if ($includeBankRecords) {
                $linkedHeaders[] = 'Linked Bank Record';
            }
            if ($includeFspRecords) {
                $linkedHeaders[] = 'Linked FSP Record';
            }
            array_splice($headers, AllTransactionColumns::linkedColumnInsertionIndex(), 0, $linkedHeaders);

            return $headers;
        }

        if ($includeEftRecords) {
            $headers = array_merge($headers, $this->eftTransactionDetailHeaders());
        }
        if ($includeBankRecords) {
            array_splice(
                $headers,
                AllTransactionColumns::matchSummaryInsertionIndex(),
                0,
                $this->bankTransactionSummaryHeaders()
            );
            $headers = array_merge($headers, $this->bankTransactionDetailHeaders());
        }
        if ($includeFspRecords) {
            $headers = array_merge($headers, $this->fspTransactionDetailHeaders());
        }

        return $headers;
    }

    private function prepareTransactionSheet(
        Writer $writer,
        Sheet $sheet,
        string $sheetName,
        Style $headerStyle,
        Style $eftHeaderStyle,
        Style $bankHeaderStyle,
        Style $fspHeaderStyle,
        array $headers
    ): void
    {
        $sheet->setName($sheetName);
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        foreach ($headers as $index => $header) {
            if ($header === 'Bank Description') {
                continue;
            }
            $width = match ($header) {
                'Customer Name' => 28,
                'Txn Type' => 36,
                'Notes' => 40,
                'Source ID' => 24,
                'Linked EFT Record' => 24,
                'Linked Bank Record' => 22,
                'Linked FSP Record' => 22,
                'Matched to Bank Transaction' => 26,
                'EFT File' => 36,
                'EFT Item ID', 'EFT Sequence', 'EFT Status', 'EFT Source' => 14,
                'EFT Type' => 24,
                'EFT Holder' => 48,
                'EFT Holder ID' => 24,
                'EFT Notes' => 36,
                'Bank Account', 'Bank Wire Ref' => 22,
                'Bank Counterparty', 'Bank Txn Variance' => 24,
                'Bank Source File' => 36,
                'Variance Note' => 56,
                'FSP Note' => 48,
                'FSP File' => 36,
                'FSP Source ID', 'FSP Dealer Account', 'FSP Intermediary Account', 'FSP Fund Account' => 24,
                'FSP Settlement Amount' => 22,
                'Currency' => 12,
                'Amount' => 16,
                default => 20,
            };
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(new Row(array_map(
            fn(string $header) => new StringCell(
                $header,
                str_starts_with($header, 'EFT ')
                    || $header === 'Linked EFT Record'
                    ? $eftHeaderStyle
                        : (str_starts_with($header, 'Bank ')
                            || str_starts_with($header, 'FSP Bank ')
                            || $header === 'Variance Note'
                            || $header === 'Linked Bank Record'
                        ? $bankHeaderStyle
                        : (str_starts_with($header, 'FSP ') || $header === 'Linked FSP Record'
                            ? $fspHeaderStyle
                            : $headerStyle))
            ),
            $headers
        )));
    }

    private function finalizeTransactionSheet(Sheet $sheet, int $dataRowCount, array $headers): void
    {
        $sheet->setAutoFilter(new AutoFilter(0, 1, count($headers) - 1, max(2, $dataRowCount + 1)));
    }

    private function eftRecordHeaders(): array
    {
        $labels = [
            'bank_match_status' => 'Matched to Bank Transaction',
            'id' => 'EFT Item ID',
            'linked_id' => 'Trust Txn ID',
            'file_id' => 'File ID',
        ] + array_column(AllTransactionColumns::eftVisible(), 'label', 'field');

        return array_map(
            fn(string $key): string => $labels[$key],
            $this->eftRecordColumnKeys()
        );
    }

    private function eftTransactionDetailHeaders(): array
    {
        return array_column(AllTransactionColumns::eftVisible(), 'label');
    }

    private function bankRecordHeaders(): array
    {
        return array_column(AllTransactionColumns::bankVisible(), 'label');
    }

    private function bankTransactionDetailHeaders(): array
    {
        return array_column(AllTransactionColumns::bankDetailVisible(), 'label');
    }

    private function bankTransactionSummaryHeaders(): array
    {
        return array_column(AllTransactionColumns::bankSummaryVisible(), 'label');
    }

    private function fspTransactionDetailHeaders(): array
    {
        return array_column(AllTransactionColumns::fspVisible(), 'label');
    }

    private function fspRecordHeaders(): array
    {
        return $this->fspTransactionDetailHeaders();
    }

    /** @return list<string> */
    private function eftRecordColumnKeys(): array
    {
        return array_values(array_unique(array_merge([
            'bank_match_status',
            'linked_id',
            'file_id',
        ], array_keys(AllTransactionColumns::eftVisible()))));
    }

    private function prepareEftRecordsSheet(Writer $writer, Sheet $sheet, Style $headerStyle): void
    {
        $sheet->setName('EFT');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $headers = $this->eftRecordHeaders();
        foreach ($headers as $index => $header) {
            $width = match ($header) {
                'Matched to Bank Transaction' => 26,
                'EFT File' => 36,
                'EFT Type', 'EFT Holder ID' => 24,
                'EFT Holder' => 48,
                'EFT Notes' => 42,
                default => 18,
            };
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues($headers, $headerStyle));
    }

    private function prepareBankRecordsSheet(Writer $writer, Sheet $sheet, Style $headerStyle): void
    {
        $sheet->setName('Bank');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $headers = $this->bankRecordHeaders();
        foreach ($headers as $index => $header) {
            $width = match ($header) {
                'Bank Description', 'Bank Source File' => 42,
                'Bank Counterparty', 'Bank Txn Variance' => 24,
                'Variance Note' => 56,
                default => 18,
            };
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues($headers, $headerStyle));
    }

    private function prepareFspRecordsSheet(Writer $writer, Sheet $sheet, Style $headerStyle): void
    {
        $sheet->setName('FSP');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $headers = $this->fspRecordHeaders();
        foreach ($headers as $index => $header) {
            $width = match ($header) {
                'FSP File' => 36,
                'FSP Source ID', 'FSP Dealer Account', 'FSP Intermediary Account', 'FSP Fund Account' => 24,
                'FSP Management Code', 'FSP Intermediary Code' => 22,
                'FSP Settlement Amount', 'FSP Items Net' => 22,
                'FSP Note' => 48,
                default => 18,
            };
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues($headers, $headerStyle));
    }

    private function finalizeLinkedRecordsSheet(Sheet $sheet, int $columnCount, int $dataRowCount): void
    {
        $sheet->setAutoFilter(new AutoFilter(0, 1, $columnCount - 1, max(2, $dataRowCount + 1)));
    }

    private function appendEftRecords(
        Writer $writer,
        Sheet $sheet,
        Collection $items,
        Style $currencyStyle,
        array &$sheetRowByItemId,
        int &$recordCount
    ): void {
        $writer->setCurrentSheet($sheet);
        foreach ($items->unique('id') as $item) {
            $itemId = (int) $item->id;
            if (isset($sheetRowByItemId[$itemId])) {
                continue;
            }

            ++$recordCount;
            $sheetRowByItemId[$itemId] = $recordCount + 1;
            $cells = [
                'bank_match_status' => new StringCell((string) ($item->bank_match_status ?? EftBankMatchStatusService::UNKNOWN), null),
                'id' => new StringCell((string) $itemId, null),
                'linked_id' => new StringCell(!empty($item->linked_id) ? 'T-' . (int) $item->linked_id : '', null),
                'file_id' => new StringCell($item->file_id !== null ? (string) (int) $item->file_id : '', null),
                'file_name' => new StringCell((string) ($item->file_name ?? ''), null),
                'sequence_number' => new StringCell($item->sequence_number !== null ? (string) (int) $item->sequence_number : '', null),
                'created_at' => new StringCell($this->dateTime($item->created_at ?? null), null),
                'effective_date' => new StringCell($this->dateTime($item->effective_date ?? null), null),
                'trade_date' => new StringCell($this->dateTime($item->trade_date ?? null), null),
                'settlement_date' => new StringCell($this->dateTime($item->settlement_date ?? null), null),
                'type' => new StringCell((string) ($item->type_name ?? $item->type_id ?? ''), null),
                'status_id' => new StringCell($item->status_id !== null ? (string) $item->status_id : '', null),
                'holder_name' => new StringCell((string) ($item->holder_name ?? ''), null),
                'holder_id' => new StringCell((string) ($item->holder_id ?? ''), null),
                'source' => new StringCell((string) ($item->source_name ?? $item->source_code ?? ''), null),
                'amount' => new NumericCell((float) ($item->amount ?? 0), $currencyStyle),
                'file_total' => isset($item->file_total)
                    ? new NumericCell((float) $item->file_total, $currencyStyle)
                    : new StringCell('', null),
                'notes' => new StringCell((string) ($item->notes ?? ''), null),
            ];
            $writer->addRow(new Row(array_values($this->orderedVisibleValues(
                $cells,
                array_flip($this->eftRecordColumnKeys())
            ))));
        }
    }

    private function appendBankRecords(
        Writer $writer,
        Sheet $sheet,
        Collection $entries,
        Style $currencyStyle,
        Style $wireFeeDetailStyle,
        Style $wireFeeCurrencyStyle,
        array &$sheetRowByEntryId,
        int &$recordCount,
        float &$descriptionWidth
    ): void {
        $writer->setCurrentSheet($sheet);
        $uniqueEntries = $entries->unique('id')->sortBy([['value_date', 'asc'], ['id', 'asc']]);
        $transactionTotal = (float) $uniqueEntries->sum(fn($entry) => (float) ($entry->amount ?? 0));
        foreach ($uniqueEntries as $entry) {
            $entryId = (int) $entry->id;
            if (isset($sheetRowByEntryId[$entryId])) {
                continue;
            }

            ++$recordCount;
            $sheetRowByEntryId[$entryId] = $recordCount + 1;
            $cleanDescription = $this->cleanBankDescription($entry->additional_info ?? null);
            $measuredWidth = $this->excelTextWidth($cleanDescription, 42.0);
            if ($measuredWidth > $descriptionWidth) {
                $descriptionWidth = $measuredWidth;
            }
            $isPossibleWireFeeMatch = (bool) ($entry->is_possible_wire_fee_match ?? false);
            $detailStyle = $isPossibleWireFeeMatch ? $wireFeeDetailStyle : null;
            $amountStyle = $isPossibleWireFeeMatch ? $wireFeeCurrencyStyle : $currencyStyle;
            $varianceCell = isset($entry->variance)
                ? new NumericCell((float) $entry->variance, $amountStyle)
                : new StringCell('', $detailStyle);
            $cells = [
                'id' => new StringCell((string) $entryId, $detailStyle),
                'value_date' => new StringCell($this->dateTime($entry->value_date ?? null), $detailStyle),
                'direction' => new StringCell((string) ($entry->credit_debit_indicator ?? ''), $detailStyle),
                'amount' => new NumericCell((float) ($entry->amount ?? 0), $amountStyle),
                'transaction_total' => new NumericCell($transactionTotal, $amountStyle),
                'currency' => new StringCell((string) ($entry->currency ?? ''), $detailStyle),
                'account_number' => new StringCell((string) ($entry->account_number ?? ''), $detailStyle),
                'settlement_number' => new StringCell((string) ($entry->settlement_number ?? ''), $detailStyle),
                'memo_type' => new StringCell((string) ($entry->memo_type ?? ''), $detailStyle),
                'counterparty' => new StringCell((string) ($entry->counterparty ?? ''), $detailStyle),
                'wire_reference' => new StringCell((string) ($entry->wire_payment_reference ?? ''), $detailStyle),
                'description' => new StringCell($cleanDescription, $detailStyle),
                'source_file' => new StringCell((string) ($entry->source_file ?? ''), $detailStyle),
                'reconciliation_status' => new StringCell((string) ($entry->reconciliation_status ?? ''), $detailStyle),
                'reconciliation_variance' => $varianceCell,
                'reconciliation_note' => new StringCell((string) ($entry->reconciliation_note ?? ''), $detailStyle),
            ];
            $writer->addRow(new Row(array_values($this->orderedVisibleValues(
                $cells,
                AllTransactionColumns::bankVisible()
            ))));
        }
    }

    private function appendFspRecords(
        Writer $writer,
        Sheet $sheet,
        Collection $items,
        Style $detailStyle,
        Style $amountStyle,
        array &$sheetRowByItemId,
        int &$recordCount
    ): void {
        $writer->setCurrentSheet($sheet);
        foreach ($items->unique('id')->sortBy([['settlement_date', 'asc'], ['id', 'asc']]) as $item) {
            $itemId = (int) $item->id;
            if (isset($sheetRowByItemId[$itemId])) {
                continue;
            }

            ++$recordCount;
            $sheetRowByItemId[$itemId] = $recordCount + 1;
            $cells = [
                'fsp_source' => new StringCell($item->source_type === 'ltm' ? '7960' : 'AGRA', $detailStyle),
                'source_file' => new StringCell((string) ($item->source_file ?? ''), $detailStyle),
                'items_total' => $item->items_total !== null
                    ? new NumericCell((float) $item->items_total, $amountStyle)
                    : new StringCell('', $detailStyle),
                'id' => new StringCell((string) $itemId, $detailStyle),
                'record_index' => new StringCell($item->record_index !== null ? (string) (int) $item->record_index : '', $detailStyle),
                'create_date' => new StringCell($this->dateOnly($item->create_date ?? null), $detailStyle),
                'trade_date' => new StringCell($this->dateOnly($item->trade_date ?? null), $detailStyle),
                'settlement_date' => new StringCell($this->dateOnly($item->settlement_date ?? null), $detailStyle),
                'side' => new StringCell((string) ($item->side ?? ''), $detailStyle),
                'transaction_type' => new StringCell((string) ($item->transaction_type ?? ''), $detailStyle),
                'order_id' => new StringCell((string) ($item->order_id ?? ''), $detailStyle),
                'source_id' => new StringCell((string) ($item->source_id ?? ''), $detailStyle),
                'management_code' => new StringCell((string) ($item->management_code ?? ''), $detailStyle),
                'dealer_code' => new StringCell((string) ($item->dealer_code ?? ''), $detailStyle),
                'dealer_account_id' => new StringCell((string) ($item->dealer_account_id ?? ''), $detailStyle),
                'rep_code' => new StringCell((string) ($item->rep_code ?? ''), $detailStyle),
                'intermediary_code' => new StringCell((string) ($item->intermediary_code ?? ''), $detailStyle),
                'intermediary_account_id' => new StringCell((string) ($item->intermediary_account_id ?? ''), $detailStyle),
                'account_type' => new StringCell((string) ($item->account_type ?? ''), $detailStyle),
                'fund_account_id' => new StringCell((string) ($item->fund_account_id ?? ''), $detailStyle),
                'fund_id' => new StringCell((string) ($item->fund_id ?? ''), $detailStyle),
                'currency' => new StringCell((string) ($item->currency ?? ''), $detailStyle),
                'gross_amount' => $item->gross_amount !== null
                    ? new NumericCell((float) $item->gross_amount, $amountStyle)
                    : new StringCell('', $detailStyle),
                'net_amount' => $item->net_amount !== null
                    ? new NumericCell((float) $item->net_amount, $amountStyle)
                    : new StringCell('', $detailStyle),
                'settlement_amount' => $item->settlement_amount !== null
                    ? new NumericCell((float) $item->settlement_amount, $amountStyle)
                    : new StringCell('', $detailStyle),
                'fsp_note' => new StringCell((string) ($item->fsp_note ?? ''), $detailStyle),
            ];
            $writer->addRow(new Row(array_values($this->orderedVisibleValues(
                $cells,
                AllTransactionColumns::fspVisible()
            ))));
        }
    }

    /** @return array<int, StringCell> */
    private function eftTransactionDetailCells(Collection $items, Style $style): array
    {
        $formatters = [
            'file_name' => fn($item) => trim((string) ($item->file_name ?? ''))
                ?: ($item->file_id !== null ? 'EFT file #' . (int) $item->file_id : 'Unprocessed EFT item #' . (int) $item->id),
            'id' => fn($item) => (string) (int) $item->id,
            'sequence_number' => fn($item) => $item->sequence_number !== null ? (string) (int) $item->sequence_number : '',
            'created_at' => fn($item) => $this->dateTime($item->created_at ?? null),
            'effective_date' => fn($item) => $this->dateTime($item->effective_date ?? null),
            'trade_date' => fn($item) => $this->dateTime($item->trade_date ?? null),
            'settlement_date' => fn($item) => $this->dateTime($item->settlement_date ?? null),
            'type' => fn($item) => (string) ($item->type_name ?? $item->type_id ?? ''),
            'status_id' => fn($item) => $item->status_id !== null ? (string) $item->status_id : '',
            'holder_name' => fn($item) => (string) ($item->holder_name ?? ''),
            'holder_id' => fn($item) => (string) ($item->holder_id ?? ''),
            'source' => fn($item) => (string) ($item->source_name ?? $item->source_code ?? ''),
            'amount' => fn($item) => $item->amount !== null ? $this->currencyText((float) $item->amount) : '',
            'file_total' => fn($item) => isset($item->file_total) ? $this->currencyText((float) $item->file_total) : '',
            'notes' => fn($item) => (string) ($item->notes ?? ''),
        ];
        $formatters = $this->orderedVisibleValues($formatters, AllTransactionColumns::eftVisible());

        return array_values(array_map(
            fn(callable $formatter) => new StringCell($this->joinedDetailValues($items, $formatter), $style),
            $formatters
        ));
    }

    /** @return array<int, StringCell> */
    private function bankTransactionDetailCells(Collection $entries, Style $style, Style $amountStyle): array
    {
        return $this->bankTransactionCells(
            $entries,
            AllTransactionColumns::bankDetailVisible(),
            $style,
            $amountStyle
        );
    }

    /** @return array<int, StringCell> */
    private function bankTransactionSummaryCells(Collection $entries, Style $style, Style $amountStyle): array
    {
        return $this->bankTransactionCells(
            $entries,
            AllTransactionColumns::bankSummaryVisible(),
            $style,
            $amountStyle
        );
    }

    /** @return array<int, StringCell> */
    private function bankTransactionCells(
        Collection $entries,
        array $definitions,
        Style $style,
        Style $amountStyle
    ): array
    {
        $formatters = [
            'id' => fn($entry) => (string) (int) $entry->id,
            'value_date' => fn($entry) => $this->dateTime($entry->value_date ?? null),
            'direction' => fn($entry) => (string) ($entry->credit_debit_indicator ?? ''),
            'amount' => fn($entry) => $entry->amount !== null ? $this->currencyText((float) $entry->amount) : '',
            'transaction_total' => fn() => '',
            'currency' => fn($entry) => (string) ($entry->currency ?? ''),
            'account_number' => fn($entry) => (string) ($entry->account_number ?? ''),
            'settlement_number' => fn($entry) => (string) ($entry->settlement_number ?? ''),
            'memo_type' => fn($entry) => (string) ($entry->memo_type ?? ''),
            'counterparty' => fn($entry) => (string) ($entry->counterparty ?? ''),
            'wire_reference' => fn($entry) => (string) ($entry->wire_payment_reference ?? ''),
            'description' => fn($entry) => $this->cleanBankDescription($entry->additional_info ?? null),
            'source_file' => fn($entry) => (string) ($entry->source_file ?? ''),
            'reconciliation_status' => fn($entry) => (string) ($entry->reconciliation_status ?? ''),
            'reconciliation_variance' => fn($entry) => isset($entry->variance) ? $this->currencyText((float) $entry->variance) : '',
            'reconciliation_note' => fn($entry) => (string) ($entry->reconciliation_note ?? ''),
        ];
        $formatters = $this->orderedVisibleValues($formatters, $definitions);

        return array_values(array_map(
            fn(callable $formatter, string $key) => new StringCell(
                $key === 'transaction_total'
                    ? $this->currencyText((float) $entries->unique('id')->sum(fn($entry) => (float) ($entry->amount ?? 0)))
                    : $this->joinedDetailValues($entries, $formatter),
                in_array($key, ['amount', 'transaction_total', 'reconciliation_variance'], true) ? $amountStyle : $style
            ),
            $formatters,
            array_keys($formatters)
        ));
    }

    /** @return array<int, StringCell> */
    private function fspTransactionDetailCells(Collection $items, Style $style, Style $amountStyle): array
    {
        $formatters = [
            'fsp_source' => fn($item) => ($item->source_type ?? '') === 'ltm' ? '7960' : 'AGRA',
            'source_file' => fn($item) => (string) ($item->source_file ?? ''),
            'items_total' => fn($item) => $item->items_total !== null
                ? $this->currencyText((float) $item->items_total)
                : '',
            'id' => fn($item) => (string) (int) $item->id,
            'record_index' => fn($item) => $item->record_index !== null ? (string) (int) $item->record_index : '',
            'create_date' => fn($item) => $this->dateOnly($item->create_date ?? null),
            'trade_date' => fn($item) => $this->dateOnly($item->trade_date ?? null),
            'settlement_date' => fn($item) => $this->dateOnly($item->settlement_date ?? null),
            'side' => fn($item) => (string) ($item->side ?? ''),
            'transaction_type' => fn($item) => (string) ($item->transaction_type ?? ''),
            'order_id' => fn($item) => (string) ($item->order_id ?? ''),
            'source_id' => fn($item) => (string) ($item->source_id ?? ''),
            'management_code' => fn($item) => (string) ($item->management_code ?? ''),
            'dealer_code' => fn($item) => (string) ($item->dealer_code ?? ''),
            'dealer_account_id' => fn($item) => (string) ($item->dealer_account_id ?? ''),
            'rep_code' => fn($item) => (string) ($item->rep_code ?? ''),
            'intermediary_code' => fn($item) => (string) ($item->intermediary_code ?? ''),
            'intermediary_account_id' => fn($item) => (string) ($item->intermediary_account_id ?? ''),
            'account_type' => fn($item) => (string) ($item->account_type ?? ''),
            'fund_account_id' => fn($item) => (string) ($item->fund_account_id ?? ''),
            'fund_id' => fn($item) => (string) ($item->fund_id ?? ''),
            'currency' => fn($item) => (string) ($item->currency ?? ''),
            'gross_amount' => fn($item) => $item->gross_amount !== null ? $this->currencyText((float) $item->gross_amount) : '',
            'net_amount' => fn($item) => $item->net_amount !== null ? $this->currencyText((float) $item->net_amount) : '',
            'settlement_amount' => fn($item) => $item->settlement_amount !== null ? $this->currencyText((float) $item->settlement_amount) : '',
            'fsp_note' => fn($item) => (string) ($item->fsp_note ?? ''),
        ];
        $formatters = $this->orderedVisibleValues($formatters, AllTransactionColumns::fspVisible());

        return array_values(array_map(
            fn(callable $formatter, string $key) => new StringCell(
                $this->joinedDetailValues($items, $formatter),
                in_array($key, ['items_total', 'gross_amount', 'net_amount', 'settlement_amount'], true) ? $amountStyle : $style
            ),
            $formatters,
            array_keys($formatters)
        ));
    }

    private function fspItemsByCashTransaction(
        Collection $rows,
        VieFundRemoteService $remoteService,
        VieFundExportLinkCache $linkCache,
        array $sourceTypes,
        FspMatchedRecordCollapser $fspRecordCollapser
    ): Collection
    {
        $sourceIdsByCashTransaction = $linkCache
            ->fundSourceIdsForCashTransactions(
                $rows->pluck('cash_transaction_id')->filter()->map(fn($id) => (int) $id)->unique()->values()->all(),
                $remoteService
            )
            ->groupBy(fn($mapping) => (string) (int) $mapping->cash_transaction_id)
            ->map(fn($mappings) => $mappings
                ->pluck('source_id')
                ->map(fn($sourceId) => trim((string) $sourceId))
                ->filter()
                ->unique()
                ->values());
        $sourceIds = $sourceIdsByCashTransaction->flatten()->unique()->values();
        if ($sourceIds->isEmpty() || $sourceTypes === []) {
            return collect();
        }

        $items = $fspRecordCollapser->withFileNetTotals(
            $fspRecordCollapser->collapse($sourceIds
                ->chunk((int) config('viefund.all_transactions_link_cache.local_query_batch_size', 5000))
                ->flatMap(fn(Collection $chunk) => SettlementInstruction::query()
                    ->whereIn('source_type', $sourceTypes)
                    ->whereIn('source_id', $chunk->all())
                    ->orderBy('settlement_date')
                    ->orderBy('id')
                    ->get(FspMatchedRecordCollapser::queryColumns())))
        );
        $itemsBySourceId = $items->groupBy(fn($item) => (string) $item->source_id);

        return $sourceIdsByCashTransaction->map(fn(Collection $cashSourceIds) => $cashSourceIds
            ->flatMap(fn($sourceId) => $itemsBySourceId->get((string) $sourceId, collect()))
            ->unique('id')
            ->values());
    }

    private function fspBankEntriesByCashTransaction(
        Collection $fspItemsByCashTransaction,
        AgraFspBankMatcher $matcher,
        Collection $matchCache,
        TransactionBankMatchStatusService $transactionBankMatchStatusService
    ): Collection {
        $items = $fspItemsByCashTransaction->flatten(1);
        $eligibleItems = $items
            ->filter(fn($item) => !empty($item->settlement_date)
                && !empty($item->currency)
                && (($item->source_type ?? '') !== 'fundserv_agra'
                    || strtoupper(trim((string) ($item->settlement_source ?? ''))) === 'I'));
        $eligibleItems
            ->groupBy(fn($item) => (string) ($item->source_type ?? 'fundserv_agra'))
            ->each(function (Collection $sourceItems, string $sourceType) use ($matchCache, $matcher) {
                $groups = $sourceItems->groupBy(
                    fn($item) => $matcher->key($item->settlement_date, $item->currency)
                );
                $missingItems = $groups
                    ->reject(fn(Collection $group, string $matchKey) => $matchCache->has($sourceType . '|' . $matchKey))
                    ->flatten(1);
                if ($missingItems->isEmpty()) {
                    return;
                }

                $newMatches = $matcher->matchesForItems($missingItems, $sourceType);
                $groups->keys()->each(function (string $matchKey) use ($matchCache, $newMatches, $sourceType) {
                    $cacheKey = $sourceType . '|' . $matchKey;
                    if (!$matchCache->has($cacheKey)) {
                        $matchCache->put($cacheKey, $newMatches->get($matchKey));
                    }
                });
            });

        return $fspItemsByCashTransaction->map(function (Collection $items) use ($matchCache, $matcher, $transactionBankMatchStatusService): Collection {
            return $items
                ->map(function ($item) use ($matchCache, $matcher, $transactionBankMatchStatusService) {
                    $sourceType = (string) ($item->source_type ?? 'fundserv_agra');
                    if (
                        empty($item->settlement_date)
                        || empty($item->currency)
                        || ($sourceType === 'fundserv_agra'
                            && strtoupper(trim((string) ($item->settlement_source ?? ''))) !== 'I')
                    ) {
                        $item->bank_match_status = $transactionBankMatchStatusService->statusForFspBankEntry(null);
                        return null;
                    }

                    $entry = $matchCache->get($sourceType . '|'
                        . $matcher->key($item->settlement_date, $item->currency));
                    $item->bank_match_status = $transactionBankMatchStatusService->statusForFspBankEntry($entry);

                    return $entry;
                })
                ->filter()
                ->unique('id')
                ->values();
        });
    }

    private function cachedBankEntriesForSequences(array $sequences, Collection $cache): Collection
    {
        $requested = collect($sequences)
            ->filter(fn($sequence) => $sequence !== null && ctype_digit((string) $sequence))
            ->map(fn($sequence) => (string) (int) $sequence)
            ->unique()
            ->values();
        $missing = $requested->reject(fn(string $sequence) => $cache->has($sequence))->values();
        if ($missing->isNotEmpty()) {
            $loaded = $this->bankEntriesForSequences($missing->all());
            $missing->each(fn(string $sequence) => $cache->put(
                $sequence,
                collect($loaded->get($sequence, collect()))
            ));
        }

        return $requested->mapWithKeys(fn(string $sequence) => [
            $sequence => collect($cache->get($sequence, collect())),
        ]);
    }

    private function cachedEnrichmentRecords(object $row, string $key): Collection
    {
        return collect((array) data_get($row, 'cached_enrichment.' . $key, []))
            ->map(fn($record) => (object) $record)
            ->values();
    }

    /** @return array<int, StringCell> */
    private function emptyDetailCells(int $count, Style $style): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(fn() => new StringCell('', $style), range(1, $count));
    }

    /** @return array<string, Style> */
    private function matchStatusStyles(Style $baseStyle, bool $includeTextColor = false): array
    {
        $palette = [
            EftBankMatchStatusService::COMPLETE => ['background' => 'C6F6D5', 'text' => '22543D'],
            EftBankMatchStatusService::TO_BE_VERIFIED => ['background' => 'FEFCBF', 'text' => '975A16'],
            EftBankMatchStatusService::POSSIBLE_MATCH => ['background' => 'BEE3F8', 'text' => '2A4365'],
            EftBankMatchStatusService::UNKNOWN => ['background' => 'EDF2F7', 'text' => '4A5568'],
        ];

        $styles = [];
        foreach ($palette as $status => $colors) {
            $style = clone $baseStyle;
            $style->setBackgroundColor($colors['background']);
            if ($includeTextColor) {
                $style->setFontColor($colors['text']);
            }
            $styles[$status] = $style;
        }

        return $styles;
    }

    /** @param array<string, Style> $styles */
    private function matchStatusStyle(array $styles, string $status): Style
    {
        return $styles[$status] ?? $styles[EftBankMatchStatusService::UNKNOWN];
    }

    /** Preserve the environment-configured column order. */
    private function orderedVisibleValues(array $values, array $visibleDefinitions): array
    {
        $ordered = [];
        foreach (array_keys($visibleDefinitions) as $key) {
            if (array_key_exists($key, $values)) {
                $ordered[$key] = $values[$key];
            }
        }

        return $ordered;
    }

    private function roundedTimings(array $timings): array
    {
        return array_map(fn($seconds) => round((float) $seconds, 2), $timings);
    }

    private function joinedDetailValues(Collection $records, callable $formatter): string
    {
        return $records->map(fn($record) => $formatter($record))->implode("\n");
    }

    private function currencyText(float $amount): string
    {
        $formatted = '$' . number_format(abs($amount), 2);

        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    }

    private function dateOnly(mixed $value): string
    {
        return $value ? Carbon::parse($value)->toDateString() : '';
    }

    private function cleanBankDescription(mixed $description): string
    {
        $cleaned = preg_replace('/\s+/u', ' ', trim((string) $description)) ?? '';
        $cleaned = preg_replace('/\s*,\s*/u', ', ', $cleaned) ?? $cleaned;

        return trim($cleaned, " ,\t\n\r\0\x0B");
    }

    private function bankDescriptionWidth(Collection $entries): float
    {
        return $entries->reduce(
            fn(float $width, $entry) => max(
                $width,
                $this->excelTextWidth($this->cleanBankDescription($entry->additional_info ?? null), 36.0)
            ),
            36.0
        );
    }

    private function excelTextWidth(string $value, float $minimum): float
    {
        if ($value === '') {
            return $minimum;
        }

        return min(180.0, max($minimum, (float) mb_strlen($value, 'UTF-8') + 2));
    }

    private function bankEntriesForSequences(array $sequences): Collection
    {
        $sequences = collect($sequences)
            ->filter(fn($sequence) => $sequence !== null && ctype_digit((string) $sequence))
            ->map(fn($sequence) => (string) (int) $sequence)
            ->unique()
            ->values();
        if ($sequences->isEmpty()) {
            return collect();
        }

        return $sequences
            ->chunk(500)
            ->flatMap(function (Collection $chunk): Collection {
                return DB::table('bank_statement_entries as b')
                    ->join('bank_statement_entry_analyses as a', function ($join) {
                        $join->on('a.bank_statement_entry_id', '=', 'b.id')
                            ->where('a.parser_version', self::BANK_PARSER_VERSION);
                    })
                    ->whereIn('a.settlement_number', $chunk->all())
                    ->select([
                        'b.id',
                        'b.value_date',
                        'b.credit_debit_indicator',
                        'b.amount',
                        'b.currency',
                        'b.account_number',
                        'b.additional_info',
                        'b.source_file',
                        'a.settlement_number',
                        'a.memo_type',
                        'a.counterparty',
                        'a.wire_payment_reference',
                    ])
                    ->orderBy('b.value_date')
                    ->orderBy('b.id')
                    ->get();
            })
            ->unique('id')
            ->groupBy(fn($entry) => ctype_digit(trim((string) $entry->settlement_number))
                ? (string) (int) trim((string) $entry->settlement_number)
                : trim((string) $entry->settlement_number));
    }

    private function internalHyperlinkCell(
        string $sheetName,
        ?int $targetRow,
        string $label,
        Style $linkStyle
    ): FormulaCell|StringCell {
        if (!$targetRow || $label === '') {
            return new StringCell('', null);
        }

        $escapedSheetName = str_replace("'", "''", $sheetName);
        $escapedLabel = str_replace('"', '""', $label);

        return new FormulaCell(
            sprintf('=HYPERLINK("#\'%s\'!A%d","%s")', $escapedSheetName, $targetRow, $escapedLabel),
            $linkStyle
        );
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
        string $balanceSource,
        ?int $linkedEftRecordCount,
        ?int $linkedBankRecordCount,
        ?int $linkedFspRecordCount,
        array $includedFspSourceTypes,
        string $linkedRecordLayout
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
            ['EFT Match Filter', !empty($filters['has_reconciliation_match']) ? 'Only transactions with EFT matches' : 'All transactions'],
            ['AGRA FSP Match Filter', array_key_exists('agra_fsp_source_ids_json', $filters) ? 'Only transactions with AGRA FSP matches' : 'All transactions'],
            ['7960 FSP Match Filter', array_key_exists('fsp_7960_source_ids_json', $filters) ? 'Only transactions with 7960 FSP matches' : 'All transactions'],
            ['EFT Record Output', $linkedEftRecordCount !== null
                ? number_format($linkedEftRecordCount) . ' unique records ' . ($linkedRecordLayout === 'single' ? 'in transaction columns' : 'on the EFT sheet')
                : 'Not included'],
            ['Bank Record Output', $linkedBankRecordCount !== null
                ? number_format($linkedBankRecordCount) . ' unique records ' . ($linkedRecordLayout === 'single' ? 'in transaction columns' : 'on the Bank sheet')
                : 'Not included'],
            ['FSP Record Output', $linkedFspRecordCount !== null
                ? number_format($linkedFspRecordCount) . ' unique ' . implode(' + ', array_map(
                    fn(string $sourceType) => $sourceType === 'ltm' ? '7960' : 'AGRA',
                    $includedFspSourceTypes
                )) . ' records ' . ($linkedRecordLayout === 'single' ? 'in transaction columns' : 'on the FSP sheet')
                : 'Not included'],
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

    private function fspSourceIdsJson(
        string $sourceType,
        string $dateBasis,
        ?string $dateFrom,
        ?string $dateTo
    ): string
    {
        $query = SettlementInstruction::query()
            ->where('source_type', $sourceType)
            ->whereNotNull('source_id')
            ->where('source_id', '<>', '');

        if ($dateBasis === 'settlement_date') {
            if ($dateFrom) {
                $query->where('settlement_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('settlement_date', '<=', $dateTo);
            }
        }

        return $query->distinct()
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(fn($sourceId) => trim((string) $sourceId))
            ->filter()
            ->values()
            ->toJson(JSON_THROW_ON_ERROR);
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
