<?php

namespace App\Http\Controllers;

use App\Exports\VieFundReportSheetExport;
use App\Models\RemoteVieFundCustomerTransaction;
use App\Models\SettlementInstruction;
use App\Services\Reconciliation\AgraFspBankMatcher;
use App\Services\Reconciliation\FspMatchedRecordCollapser;
use App\Services\Reconciliation\TransactionBankMatchStatusService;
use App\Services\VieFund\VieFundDailyBalanceService;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionWorkingSetManager;
use App\Services\VieFund\VieFundTransactionWorkingSetQuery;
use App\Support\AllTransactionColumns;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\ExecutableFinder;
use Illuminate\View\View;
use Throwable;

class RemoteVieFundController extends Controller
{
    private const ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS = 14400;
    private const TRANSACTION_FILTER_SET_LIMIT = 10;
    private const ALL_TRANSACTION_DATE_BASES = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];
    private const ALL_TRANSACTION_INCEPTION_ENV_KEYS = [
        'create_date' => 'VIEFUND_REPORT_INCEPTION_CREATE_DATE',
        'trade_date' => 'VIEFUND_REPORT_INCEPTION_TRADE_DATE',
        'processing_date' => 'VIEFUND_REPORT_INCEPTION_PROCESSING_DATE',
        'settlement_date' => 'VIEFUND_REPORT_INCEPTION_SETTLEMENT_DATE',
    ];
    private const ALL_TRANSACTION_OUTPUT_ORDERS = [
        'asc' => 'Earliest first',
        'desc' => 'Latest first',
    ];
    private const ALL_TRANSACTION_SORT_COLUMNS = [
        'created_date' => 'Created Date',
        'trade_date' => 'Trade Date',
        'processing_date' => 'Processing Date',
        'settlement_date' => 'Settlement Date',
        'amount' => 'Amount',
    ];
    private const ALL_TRANSACTION_CURRENCIES = [
        '00' => 'CAD',
        '01' => 'USD',
    ];
    private const ALL_TRANSACTION_STATUSES = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];

    public function __construct(
        private readonly VieFundRemoteService $remoteService,
        private readonly VieFundDailyBalanceService $dailyBalanceService,
        private readonly AgraFspBankMatcher $agraFspBankMatcher,
        private readonly FspMatchedRecordCollapser $fspRecordCollapser,
        private readonly TransactionBankMatchStatusService $transactionBankMatchStatusService,
        private readonly VieFundTransactionWorkingSetManager $workingSetManager,
        private readonly VieFundTransactionWorkingSetQuery $workingSetQuery
    ) {}

    public function allTransactions(Request $request): View|JsonResponse
    {
        $asyncPage = $request->ajax() && $request->boolean('_table_page');
        $filterRequest = $this->transactionFilterRequest($request, 'all');

        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $search = trim((string) $filterRequest->query('search', ''));
        $trxTypesSelected = array_values(array_filter((array) $filterRequest->query('filter_trx_type', [])));
        $dateBasis = (string) $filterRequest->query('filter_date_basis', 'settlement_date');
        $dateBasis = array_key_exists($dateBasis, self::ALL_TRANSACTION_DATE_BASES) ? $dateBasis : 'settlement_date';
        $defaultSort = $dateBasis === 'create_date' ? 'created_date' : $dateBasis;
        $sort = (string) $filterRequest->query('sort', $defaultSort);
        $sort = array_key_exists($sort, self::ALL_TRANSACTION_SORT_COLUMNS) ? $sort : $defaultSort;
        $sortDirection = strtolower((string) $filterRequest->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $hasEftMatch = $filterRequest->boolean('filter_has_eft_match');
        $hasAgraFspMatch = $filterRequest->boolean('filter_has_agra_fsp_match');
        $has7960FspMatch = $filterRequest->boolean('filter_has_7960_fsp_match');
        $matchStatuses = array_values(array_intersect(
            ['Complete', 'Verify', 'Possible', 'Unknown'],
            (array) $filterRequest->query('filter_match_status', [])
        ));
        $currencyCode = (string) $filterRequest->query('filter_currency_code', '00');
        $currencyCode = array_key_exists($currencyCode, self::ALL_TRANSACTION_CURRENCIES) ? $currencyCode : '00';
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $filterRequest->query('filter_status', [6])),
            fn($status) => array_key_exists($status, self::ALL_TRANSACTION_STATUSES)
        )));
        $statusIds = $statusIds ?: [6];
        $filters = array_filter([
            'customer_name' => trim((string) $filterRequest->query('filter_customer_name', '')),
            'plan_account_id' => trim((string) $filterRequest->query('filter_plan_account_id', '')),
            'trx_id' => trim((string) $filterRequest->query('filter_trx_id', '')),
            'source_id' => trim((string) $filterRequest->query('filter_source_id', '')),
            'date_from' => trim((string) $filterRequest->query('filter_date_from', $filterRequest->query('filter_created_from', $defaultFrom))),
            'date_to' => trim((string) $filterRequest->query('filter_date_to', $filterRequest->query('filter_created_to', $defaultTo))),
            'date_basis' => $dateBasis,
            'output_order' => 'desc',
            'sort' => $sort,
            'sort_dir' => $sortDirection,
            'currency_code' => $currencyCode,
            'status_ids' => $statusIds,
            'trx_type' => $trxTypesSelected ?: null,
            'has_reconciliation_match' => $hasEftMatch ?: null,
            'has_agra_fsp_match' => $hasAgraFspMatch ?: null,
            'has_7960_fsp_match' => $has7960FspMatch ?: null,
            'match_statuses' => $matchStatuses ?: null,
        ]);
        $perPage = in_array((int) $filterRequest->query('per_page', 100), [50, 100, 250], true)
            ? (int) $filterRequest->query('per_page', 100)
            : 100;
        $connectionError = null;
        $transactions = null;
        $availableTrxTypes = [];
        $workingSet = $this->workingSetManager->ensure($filters);
        $workingSetQueryable = $workingSet?->hasQueryableGeneration() ?? false;

        try {
            if ($workingSetQueryable) {
                $transactions = $this->workingSetQuery->paginate(
                    $workingSet,
                    $perPage,
                    max(1, (int) $filterRequest->query('page', 1)),
                    $search ?: null,
                    $filters
                );
            } elseif ($hasAgraFspMatch) {
                $filters['agra_fsp_source_ids_json'] = $this->fspSourceIdsJson(
                    'fundserv_agra',
                    $dateBasis,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }
            if (!$workingSetQueryable && $has7960FspMatch) {
                $filters['fsp_7960_source_ids_json'] = $this->fspSourceIdsJson(
                    'ltm',
                    $dateBasis,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }
            if (!$workingSetQueryable) {
                $transactions = $this->remoteService->fetchAllTransactions(
                    $perPage,
                    max(1, (int) $filterRequest->query('page', 1)),
                    $search ?: null,
                    $filters
                );
            }
            if (!$asyncPage) {
                $availableTrxTypes = $this->remoteService->fetchDistinctTrxTypes();
            }
        } catch (Exception $e) {
            Log::error('Unable to load the complete VieFund transaction ledger.', ['exception' => $e]);
            $connectionError = 'Could not connect to the remote VieFund database: ' . $e->getMessage();
        }

        $dateBasisOptions = self::ALL_TRANSACTION_DATE_BASES;
        $sortOptions = self::ALL_TRANSACTION_SORT_COLUMNS;
        $currencyOptions = self::ALL_TRANSACTION_CURRENCIES;
        $statusOptions = self::ALL_TRANSACTION_STATUSES;
        $visibleTransactionColumns = AllTransactionColumns::visible();
        $visibleEftColumns = AllTransactionColumns::eftVisible();
        $visibleBankColumns = AllTransactionColumns::bankVisible();
        $visibleBankSummaryColumns = AllTransactionColumns::bankSummaryVisible();
        $visibleBankDetailColumns = AllTransactionColumns::bankDetailVisible();
        $visibleFspColumns = AllTransactionColumns::fspVisible();
        $columnGroupOrder = AllTransactionColumns::groupOrder();
        $workingSetStatus = $this->workingSetManager->status($workingSet);
        if ($asyncPage) {
            if ($connectionError || !$transactions) {
                return response()->json([
                    'message' => $connectionError ?: 'The transaction page could not be loaded.',
                ], 503, $this->exportStatusNoCacheHeaders());
            }

            $coreHeadings = [];
            foreach ($visibleTransactionColumns as $key => $definition) {
                $coreHeadings[] = array_merge($definition, ['key' => $key]);
            }
            $eftHeadings = array_values($visibleEftColumns);
            $bankSummaryHeadings = array_values($visibleBankSummaryColumns);
            $bankHeadings = array_values($visibleBankDetailColumns);
            $fspHeadings = array_values($visibleFspColumns);

            return response()->json([
                'rows_html' => view('viefund-transactions.partials.rows', compact(
                    'transactions',
                    'coreHeadings',
                    'eftHeadings',
                    'bankSummaryHeadings',
                    'bankHeadings',
                    'fspHeadings',
                    'columnGroupOrder',
                    'currencyOptions'
                ))->render(),
                'from' => $transactions->firstItem() ?? 0,
                'to' => $transactions->lastItem() ?? 0,
                'page' => $transactions->currentPage(),
                'per_page' => $perPage,
                'has_more_pages' => $transactions->hasMorePages(),
            ], 200, $this->exportStatusNoCacheHeaders());
        }

        $inceptionDates = [];
        foreach (array_keys(self::ALL_TRANSACTION_DATE_BASES) as $basis) {
            $inceptionDates[$basis] = $this->resolveAllTransactionInceptionDate($basis);
        }
        return view('viefund-transactions.index', compact(
            'transactions',
            'connectionError',
            'perPage',
            'search',
            'filters',
            'availableTrxTypes',
            'dateBasis',
            'sort',
            'sortDirection',
            'hasEftMatch',
            'hasAgraFspMatch',
            'has7960FspMatch',
            'matchStatuses',
            'workingSetStatus',
            'currencyCode',
            'statusIds',
            'dateBasisOptions',
            'sortOptions',
            'currencyOptions',
            'statusOptions',
            'inceptionDates',
            'visibleTransactionColumns',
            'visibleEftColumns',
            'visibleBankColumns',
            'visibleBankSummaryColumns',
            'visibleBankDetailColumns',
            'visibleFspColumns',
            'columnGroupOrder'
        ));
    }

    public function allTransactionsWorkingSetStatus(int $workingSet): JsonResponse
    {
        return response()->json(
            $this->workingSetManager->status(\App\Models\VieFundTransactionWorkingSet::query()->find($workingSet)),
            200,
            $this->exportStatusNoCacheHeaders()
        );
    }

    public function pauseAllTransactionsWorkingSet(int $workingSet): JsonResponse
    {
        $workingSet = \App\Models\VieFundTransactionWorkingSet::query()->findOrFail($workingSet);

        return response()->json(
            $this->workingSetManager->status($this->workingSetManager->pause($workingSet)),
            200,
            $this->exportStatusNoCacheHeaders()
        );
    }

    public function resumeAllTransactionsWorkingSet(int $workingSet): JsonResponse
    {
        $workingSet = \App\Models\VieFundTransactionWorkingSet::query()->findOrFail($workingSet);

        return response()->json(
            $this->workingSetManager->status($this->workingSetManager->resume($workingSet)),
            200,
            $this->exportStatusNoCacheHeaders()
        );
    }

    public function applyAllTransactionsFilters(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter_customer_name' => ['nullable', 'string', 'max:255'],
            'filter_plan_account_id' => ['nullable', 'string', 'max:100'],
            'filter_trx_id' => ['nullable', 'string', 'max:10000'],
            'filter_source_id' => ['nullable', 'string', 'max:10000'],
            'filter_date_from' => ['required', 'date'],
            'filter_date_to' => ['required', 'date', 'after_or_equal:filter_date_from'],
            'filter_date_basis' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_DATE_BASES))],
            'filter_currency_code' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_CURRENCIES))],
            'filter_status' => ['sometimes', 'array', 'max:7'],
            'filter_status.*' => ['integer', 'between:0,6', 'distinct'],
            'filter_trx_type' => ['sometimes', 'array', 'max:2000'],
            'filter_trx_type.*' => ['string', 'max:255', 'distinct'],
            'filter_has_eft_match' => ['sometimes', 'boolean'],
            'filter_has_agra_fsp_match' => ['sometimes', 'boolean'],
            'filter_has_7960_fsp_match' => ['sometimes', 'boolean'],
            'filter_match_status' => ['sometimes', 'array', 'max:4'],
            'filter_match_status.*' => ['string', 'in:Complete,Verify,Possible,Unknown', 'distinct'],
        ]);

        $transactionTypes = $this->canonicalTransactionTypes((array) ($validated['filter_trx_type'] ?? []));

        if ($transactionTypes === []) {
            unset($validated['filter_trx_type']);
        } else {
            sort($transactionTypes);
            $validated['filter_trx_type'] = $transactionTypes;
        }

        if (isset($validated['filter_status'])) {
            $validated['filter_status'] = array_values(array_unique(array_map(
                'intval',
                (array) $validated['filter_status']
            )));
            sort($validated['filter_status']);
        }

        return $this->rememberTransactionFilters($request, 'all', $validated, 'viefund-transactions.index');
    }

    public function applyCustomerTransactionFilters(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:500'],
            'filter_customer_id' => ['nullable', 'string', 'max:100'],
            'filter_customer_name' => ['nullable', 'string', 'max:255'],
            'filter_account_id' => ['nullable', 'string', 'max:100'],
            'filter_trx_id' => ['nullable', 'string', 'max:10000'],
            'filter_source_id' => ['nullable', 'string', 'max:10000'],
            'filter_created_from' => ['nullable', 'date'],
            'filter_created_to' => ['nullable', 'date'],
            'filter_status_group' => ['sometimes', 'array', 'max:3'],
            'filter_status_group.*' => ['string', 'in:not_completed,open,completed', 'distinct'],
            'filter_trx_type' => ['sometimes', 'array', 'max:2000'],
            'filter_trx_type.*' => ['string', 'max:255', 'distinct'],
        ]);

        $transactionTypes = $this->canonicalTransactionTypes((array) ($validated['filter_trx_type'] ?? []));
        if ($transactionTypes === []) {
            unset($validated['filter_trx_type']);
        } else {
            $validated['filter_trx_type'] = $transactionTypes;
        }

        return $this->rememberTransactionFilters($request, 'customer', $validated, 'remote-viefund.index');
    }

    public function fspMatches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cash_transaction_ids' => ['required', 'array', 'max:250'],
            'cash_transaction_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'sources' => ['required', 'array', 'min:1', 'max:2'],
            'sources.*' => ['required', 'in:agra,7960', 'distinct'],
        ]);
        $sourceTypes = collect($validated['sources'])
            ->mapWithKeys(fn(string $source) => [$source => $source === '7960' ? 'ltm' : 'fundserv_agra']);
        $sourceIdsByCashTransaction = $this->remoteService
            ->fetchFundSourceIdsForCashTransactions($validated['cash_transaction_ids'])
            ->groupBy('cash_transaction_id')
            ->map(fn($rows) => $rows->pluck('source_id')->map(fn($value) => trim((string) $value))->filter()->unique()->values());
        $sourceIds = $sourceIdsByCashTransaction->flatten()->unique()->values();

        $fspItems = $this->fspRecordCollapser->withFileNetTotals(
            $this->fspRecordCollapser->collapse(SettlementInstruction::query()
            ->whereIn('source_type', $sourceTypes->values()->all())
            ->whereIn('source_id', $sourceIds)
            ->orderBy('settlement_date')
            ->orderBy('id')
            ->get(FspMatchedRecordCollapser::queryColumns()))
        );
        $fspItemsBySourceId = $fspItems->groupBy(fn($record) => trim((string) $record->source_id));
        $bankMatchesBySource = $sourceTypes->mapWithKeys(fn(string $sourceType) => [
            $sourceType => $this->agraFspBankMatcher->matchesForItems(
                $fspItems->where('source_type', $sourceType)->values(),
                $sourceType
            ),
        ]);
        $fspItems->each(function (SettlementInstruction $item) use ($bankMatchesBySource): void {
            $entry = null;
            if (
                $item->settlement_date
                && $item->currency
                && ($item->source_type !== 'fundserv_agra'
                    || strtoupper(trim((string) $item->settlement_source)) === 'I')
            ) {
                $entry = $bankMatchesBySource->get($item->source_type, collect())->get(
                    $this->agraFspBankMatcher->key($item->settlement_date, $item->currency)
                );
            }
            $item->bank_match_status = $this->transactionBankMatchStatusService->statusForFspBankEntry($entry);
        });
        $records = $fspItems
            ->map(function (SettlementInstruction $record): array {
                $source = $record->source_type === 'ltm' ? '7960' : 'AGRA';

                return [
                    'id' => $record->id,
                    'fsp_source' => $source,
                    'source_file' => $record->source_file,
                    'items_total' => $record->items_total,
                    'record_index' => $record->record_index,
                    'create_date' => optional($record->create_date)->toDateString(),
                    'trade_date' => optional($record->trade_date)->toDateString(),
                    'settlement_date' => optional($record->settlement_date)->toDateString(),
                    'management_code' => $record->management_code,
                    'fund_account_id' => $record->fund_account_id,
                    'dealer_code' => $record->dealer_code,
                    'dealer_account_id' => $record->dealer_account_id,
                    'rep_code' => $record->rep_code,
                    'intermediary_code' => $record->intermediary_code,
                    'intermediary_account_id' => $record->intermediary_account_id,
                    'account_type' => $record->account_type,
                    'order_id' => $record->order_id,
                    'source_id' => $record->source_id,
                    'side' => $record->side,
                    'transaction_type' => $record->transaction_type,
                    'fund_id' => $record->fund_id,
                    'switch_from_fund_id' => $record->switch_from_fund_id,
                    'switch_to_fund_id' => $record->switch_to_fund_id,
                    'currency' => $record->currency,
                    'gross_amount' => $record->gross_amount,
                    'net_amount' => $record->net_amount,
                    'settlement_amount' => $record->settlement_amount,
                    'fsp_note' => $record->fsp_note,
                    'bank_match_status' => $record->bank_match_status,
                    'file_url' => route('settlement-instructions.index', [
                        'source_type' => $record->source_type,
                        'source_file' => $record->source_file,
                    ]),
                    'item_url' => route('settlement-instructions.index', [
                        'source_type' => $record->source_type,
                        'source_file' => $record->source_file,
                        'item_id' => $record->id,
                    ]),
                ];
            })
            ->groupBy('source_id');

        $recordsByCashTransaction = $sourceIdsByCashTransaction->map(function ($cashSourceIds) use ($records) {
            return $cashSourceIds
                ->flatMap(fn($sourceId) => $records->get($sourceId, collect()))
                ->unique('id')
                ->values();
        });

        $matchStatuses = $sourceIdsByCashTransaction->map(function ($cashSourceIds) use ($fspItemsBySourceId) {
            $items = $cashSourceIds
                ->flatMap(fn($sourceId) => $fspItemsBySourceId->get((string) $sourceId, collect()))
                ->unique('id')
                ->values();

            return $this->transactionBankMatchStatusService->summarizeFspItems($items);
        })->filter(fn($status) => $status !== null);

        $bankRecordsByCashTransaction = $sourceIdsByCashTransaction->map(function ($cashSourceIds) use ($fspItemsBySourceId, $bankMatchesBySource) {
            return $cashSourceIds
                ->flatMap(fn($sourceId) => $fspItemsBySourceId->get((string) $sourceId, collect()))
                ->map(function (SettlementInstruction $item) use ($bankMatchesBySource) {
                    if (
                        !$item->settlement_date
                        || !$item->currency
                        || ($item->source_type === 'fundserv_agra'
                            && strtoupper(trim((string) $item->settlement_source)) !== 'I')
                    ) {
                        return null;
                    }

                    return $bankMatchesBySource->get($item->source_type, collect())->get($this->agraFspBankMatcher->key(
                        $item->settlement_date,
                        $item->currency
                    ));
                })
                ->filter()
                ->unique(fn($entry) => (int) $entry->id)
                ->map(function ($entry): array {
                    return [
                        'id' => (int) $entry->id,
                        'source_file' => trim((string) ($entry->source_file ?? '')) ?: null,
                        'account_number' => trim((string) ($entry->account_number ?? '')) ?: null,
                        'value_date' => $entry->value_date,
                        'direction' => trim((string) ($entry->credit_debit_indicator ?? '')) ?: null,
                        'currency' => trim((string) ($entry->currency ?? '')) ?: null,
                        'amount' => $entry->amount !== null ? (float) $entry->amount : null,
                        'settlement_number' => trim((string) ($entry->settlement_number ?? '')) ?: null,
                        'memo_type' => trim((string) ($entry->memo_type ?? '')) ?: null,
                        'counterparty' => trim((string) ($entry->counterparty ?? '')) ?: null,
                        'wire_reference' => trim((string) ($entry->wire_payment_reference ?? '')) ?: null,
                        'description' => trim((string) ($entry->additional_info ?? '')) ?: null,
                        'reconciliation_status' => (string) ($entry->reconciliation_status ?? ''),
                        'reconciliation_variance' => isset($entry->variance) ? (float) $entry->variance : null,
                        'reconciliation_note' => (string) ($entry->reconciliation_note ?? ''),
                        'is_possible_wire_fee_match' => (bool) ($entry->is_possible_wire_fee_match ?? false),
                        'account_url' => !empty($entry->bank_statement_summary_id)
                            ? route('bank-entries.index', [
                                'view' => 'transactions',
                                'statement_summary_id' => (int) $entry->bank_statement_summary_id,
                            ])
                            : null,
                        'file_url' => trim((string) ($entry->source_file ?? '')) !== ''
                            ? route('bank-entries.index', [
                                'view' => 'summaries',
                                'source_file' => trim((string) $entry->source_file),
                            ])
                            : null,
                        'url' => route('reconciliations.daily-totals.bank-day', [
                            'date' => Carbon::parse($entry->value_date)->toDateString(),
                            'entry_id' => (int) $entry->id,
                        ]),
                    ];
                })
                ->values();
        });
        $recordsByCashTransaction = $recordsByCashTransaction->map(fn($records) => collect($records)
            ->map(fn(array $record) => AllTransactionColumns::filterLinkedPayload($record, 'fsp'))
            ->values());
        $bankRecordsByCashTransaction = $bankRecordsByCashTransaction->map(fn($records) => collect($records)
            ->map(fn(array $record) => AllTransactionColumns::filterLinkedPayload($record, 'bank'))
            ->values());

        return response()->json([
            'fsp_records' => $recordsByCashTransaction,
            'bank_records' => $bankRecordsByCashTransaction,
            'match_statuses' => $matchStatuses,
        ]);
    }

    public function allTransactionsCountStatus(Request $request): JsonResponse
    {
        $filterRequest = $this->transactionFilterRequest($request, 'all');

        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $dateBasis = (string) $filterRequest->query('filter_date_basis', 'settlement_date');
        $dateBasis = array_key_exists($dateBasis, self::ALL_TRANSACTION_DATE_BASES) ? $dateBasis : 'settlement_date';
        $currencyCode = (string) $filterRequest->query('filter_currency_code', '00');
        $currencyCode = array_key_exists($currencyCode, self::ALL_TRANSACTION_CURRENCIES) ? $currencyCode : '00';
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $filterRequest->query('filter_status', [6])),
            fn($status) => array_key_exists($status, self::ALL_TRANSACTION_STATUSES)
        )));
        $statusIds = $statusIds ?: [6];
        sort($statusIds);
        $transactionTypes = array_values(array_filter(array_map(
            fn($type) => trim((string) $type),
            (array) $filterRequest->query('filter_trx_type', [])
        )));
        sort($transactionTypes);
        $matchStatuses = array_values(array_intersect(
            ['Complete', 'Verify', 'Possible', 'Unknown'],
            (array) $filterRequest->query('filter_match_status', [])
        ));

        $filters = array_filter([
            'customer_name' => trim((string) $filterRequest->query('filter_customer_name', '')),
            'plan_account_id' => trim((string) $filterRequest->query('filter_plan_account_id', '')),
            'trx_id' => trim((string) $filterRequest->query('filter_trx_id', '')),
            'source_id' => trim((string) $filterRequest->query('filter_source_id', '')),
            'date_from' => trim((string) $filterRequest->query('filter_date_from', $filterRequest->query('filter_created_from', $defaultFrom))),
            'date_to' => trim((string) $filterRequest->query('filter_date_to', $filterRequest->query('filter_created_to', $defaultTo))),
            'date_basis' => $dateBasis,
            'currency_code' => $currencyCode,
            'status_ids' => $statusIds,
            'trx_type' => $transactionTypes ?: null,
            'has_reconciliation_match' => $filterRequest->boolean('filter_has_eft_match') ?: null,
            'has_agra_fsp_match' => $filterRequest->boolean('filter_has_agra_fsp_match') ?: null,
            'has_7960_fsp_match' => $filterRequest->boolean('filter_has_7960_fsp_match') ?: null,
            'match_statuses' => $matchStatuses ?: null,
        ]);
        $payload = [
            'search' => trim((string) $filterRequest->query('search', '')),
            'filters' => $filters,
        ];
        $signature = sha1(json_encode($payload, JSON_THROW_ON_ERROR));
        $valueKey = "viefund_all_transactions_total:{$signature}";
        $statusKey = "viefund_all_transactions_total_status:{$signature}";
        $lockKey = "viefund_all_transactions_total_lock:{$signature}";
        $perPage = in_array((int) $filterRequest->query('per_page', 100), [50, 100, 250], true)
            ? (int) $filterRequest->query('per_page', 100)
            : 100;
        $page = max(1, (int) $filterRequest->query('page', 1));
        $workingSet = $this->workingSetManager->findQueryable($filters);
        if ($workingSet) {
            $total = $this->workingSetQuery->count($workingSet, $payload['search'] ?: null, $filters);
            $complete = $workingSet->isReady();

            return response()->json([
                'state' => $complete ? 'complete' : 'partial',
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
                'page' => $page,
                'cached' => true,
                'source' => 'working_set',
                'rows_cached' => (int) $workingSet->rows_cached,
            ], 200, $this->exportStatusNoCacheHeaders());
        }

        if (Cache::has($valueKey)) {
            $total = (int) Cache::get($valueKey);

            return response()->json([
                'state' => 'complete',
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
                'page' => $page,
                'cached' => true,
            ], 200, $this->exportStatusNoCacheHeaders());
        }

        $status = Cache::get($statusKey);
        if (is_array($status) && ($status['state'] ?? null) === 'failed') {
            return response()->json($status, 200, $this->exportStatusNoCacheHeaders());
        }

        if (Cache::add($lockKey, true, now()->addMinutes(15))) {
            Cache::put($statusKey, [
                'state' => 'processing',
                'message' => 'Calculating the filtered transaction total...',
                'updated_at' => now()->toIso8601String(),
            ], now()->addMinutes(15));
            $phpPath = $this->consolePhpBinary();
            $logPath = storage_path('logs/viefund-all-transactions-count.log');
            $encodedPayload = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $command = sprintf(
                '%s %s report:cache-viefund-all-transactions-count --cache-key=%s --payload=%s >> %s 2>&1 &',
                escapeshellarg($phpPath),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($signature),
                escapeshellarg($encodedPayload),
                escapeshellarg($logPath)
            );
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (is_resource($process)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            } else {
                Cache::forget($lockKey);
                Cache::put($statusKey, [
                    'state' => 'failed',
                    'message' => 'The background total could not be started.',
                ], now()->addMinutes(2));
            }
        }

        return response()->json([
            'state' => 'processing',
            'message' => 'Calculating the filtered transaction total...',
        ], 202, $this->exportStatusNoCacheHeaders());
    }

    public function allTransactionsSummary(Request $request): JsonResponse
    {
        $filterRequest = $this->transactionFilterRequest($request, 'all');
        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $dateBasis = (string) $filterRequest->query('filter_date_basis', 'settlement_date');
        $dateBasis = array_key_exists($dateBasis, self::ALL_TRANSACTION_DATE_BASES) ? $dateBasis : 'settlement_date';
        $currencyCode = (string) $filterRequest->query('filter_currency_code', '00');
        $currencyCode = array_key_exists($currencyCode, self::ALL_TRANSACTION_CURRENCIES) ? $currencyCode : '00';
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $filterRequest->query('filter_status', [6])),
            fn($status) => array_key_exists($status, self::ALL_TRANSACTION_STATUSES)
        )));
        $statusIds = $statusIds ?: [6];
        sort($statusIds);

        try {
            $dateFrom = Carbon::createFromFormat(
                'Y-m-d',
                trim((string) $filterRequest->query('filter_date_from', $defaultFrom))
            )->startOfDay();
            $dateTo = Carbon::createFromFormat(
                'Y-m-d',
                trim((string) $filterRequest->query('filter_date_to', $defaultTo))
            )->startOfDay();

            if ($dateFrom->gt($dateTo)) {
                throw new Exception('The start date must be before or equal to the end date.');
            }

            // Deliberately exclude search, customer, transaction type, source, and match
            // filters so this remains a stable cash-ledger summary for the selected period.
            $workingSet = $this->workingSetManager->findReady([
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'date_basis' => $dateBasis,
                'currency_code' => $currencyCode,
                'status_ids' => $statusIds,
            ]);
            if (!$workingSet || !is_array($workingSet->balance_report)) {
                return response()->json([
                    'state' => 'processing',
                    'message' => 'Period summary will be available when caching completes.',
                ], 202, $this->exportStatusNoCacheHeaders());
            }

            $report = $workingSet->balance_report;
            $summary = [
                'opening_balance' => (float) $report['opening_balance'],
                'period_net' => (float) array_sum(array_column($report['rows'], 'daily_net_transactions')),
                'closing_balance' => (float) $report['final_balance'],
                'transaction_count' => (int) array_sum(array_column($report['rows'], 'transaction_count')),
                'balance_source' => (string) $report['balance_source'],
                'uses_snapshots' => (bool) $report['uses_snapshots'],
            ];
            $summary['period_label'] = $dateFrom->isSameDay($dateTo)
                ? $dateFrom->format('M j, Y')
                : ($dateFrom->isSameMonth($dateTo) && $dateFrom->isSameYear($dateTo)
                    ? $dateFrom->format('M j') . '–' . $dateTo->format('j, Y')
                    : ($dateFrom->isSameYear($dateTo)
                        ? $dateFrom->format('M j') . ' – ' . $dateTo->format('M j, Y')
                        : $dateFrom->format('M j, Y') . ' – ' . $dateTo->format('M j, Y')));

            return response()->json($summary, 200, $this->exportStatusNoCacheHeaders());
        } catch (Throwable $e) {
            Log::error('Unable to load the All Transactions period summary.', ['exception' => $e]);

            return response()->json([
                'message' => 'The period summary could not be loaded.',
            ], 503, $this->exportStatusNoCacheHeaders());
        }
    }

    public function allTransactionsMatchSummary(Request $request): JsonResponse
    {
        $filterRequest = $this->transactionFilterRequest($request, 'all');
        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $dateBasis = (string) $filterRequest->query('filter_date_basis', 'settlement_date');
        $dateBasis = array_key_exists($dateBasis, self::ALL_TRANSACTION_DATE_BASES) ? $dateBasis : 'settlement_date';
        $currencyCode = (string) $filterRequest->query('filter_currency_code', '00');
        $currencyCode = array_key_exists($currencyCode, self::ALL_TRANSACTION_CURRENCIES) ? $currencyCode : '00';
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $filterRequest->query('filter_status', [6])),
            fn($status) => array_key_exists($status, self::ALL_TRANSACTION_STATUSES)
        )));
        $statusIds = $statusIds ?: [6];
        sort($statusIds);
        $transactionTypes = array_values(array_filter(array_map(
            fn($type) => trim((string) $type),
            (array) $filterRequest->query('filter_trx_type', [])
        )));
        sort($transactionTypes);
        $search = trim((string) $filterRequest->query('search', ''));
        $filters = array_filter([
            'customer_name' => trim((string) $filterRequest->query('filter_customer_name', '')),
            'plan_account_id' => trim((string) $filterRequest->query('filter_plan_account_id', '')),
            'trx_id' => trim((string) $filterRequest->query('filter_trx_id', '')),
            'source_id' => trim((string) $filterRequest->query('filter_source_id', '')),
            'date_from' => trim((string) $filterRequest->query('filter_date_from', $defaultFrom)),
            'date_to' => trim((string) $filterRequest->query('filter_date_to', $defaultTo)),
            'date_basis' => $dateBasis,
            'currency_code' => $currencyCode,
            'status_ids' => $statusIds,
            'trx_type' => $transactionTypes ?: null,
            'has_reconciliation_match' => $filterRequest->boolean('filter_has_eft_match') ?: null,
            'has_agra_fsp_match' => $filterRequest->boolean('filter_has_agra_fsp_match') ?: null,
            'has_7960_fsp_match' => $filterRequest->boolean('filter_has_7960_fsp_match') ?: null,
        ]);
        $workingSet = $this->workingSetManager->findReady($filters);
        if (!$workingSet) {
            return response()->json([
                'state' => 'processing',
                'message' => 'Match summaries will be available when the period cache is ready.',
            ], 202, $this->exportStatusNoCacheHeaders());
        }

        try {
            $signature = sha1(json_encode([
                'working_set_id' => $workingSet->id,
                'generation' => $workingSet->readableGeneration(),
                'search' => $search,
                'filters' => $filters,
            ], JSON_THROW_ON_ERROR));
            $summary = Cache::remember(
                "viefund_all_transactions_match_summary:{$signature}",
                now()->addMinutes(5),
                fn(): array => $this->workingSetQuery->matchStatusSummary(
                    $workingSet,
                    $search ?: null,
                    $filters
                )
            );

            return response()->json([
                'state' => 'complete',
                'currency' => self::ALL_TRANSACTION_CURRENCIES[$currencyCode],
                'statuses' => collect($summary)->map(
                    fn(array $values, string $status): array => array_merge(['status' => $status], $values)
                )->values(),
            ], 200, $this->exportStatusNoCacheHeaders());
        } catch (Throwable $e) {
            Log::error('Unable to load the All Transactions match summary.', ['exception' => $e]);

            return response()->json([
                'message' => 'The match summary could not be loaded.',
            ], 503, $this->exportStatusNoCacheHeaders());
        }
    }

    public function startAllTransactionsExport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter_customer_name' => ['nullable', 'string', 'max:255'],
            'filter_plan_account_id' => ['nullable', 'string', 'max:100'],
            'filter_trx_id' => ['nullable', 'string', 'max:500'],
            'filter_source_id' => ['nullable', 'string', 'max:500'],
            'filter_date_from' => ['required', 'date'],
            'filter_date_to' => ['required', 'date', 'after_or_equal:filter_date_from'],
            'filter_date_basis' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_DATE_BASES))],
            'filter_output_order' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_OUTPUT_ORDERS))],
            'filter_currency_code' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_CURRENCIES))],
            'filter_status' => ['sometimes', 'array'],
            'filter_status.*' => ['integer', 'between:0,6'],
            'filter_trx_type' => ['sometimes', 'array'],
            'filter_trx_type.*' => ['string', 'max:255'],
            'filter_has_eft_match' => ['sometimes', 'boolean'],
            'filter_has_agra_fsp_match' => ['sometimes', 'boolean'],
            'filter_has_7960_fsp_match' => ['sometimes', 'boolean'],
            'filter_match_status' => ['sometimes', 'array', 'max:4'],
            'filter_match_status.*' => ['string', 'in:Complete,Verify,Possible,Unknown', 'distinct'],
            'split_sheets' => ['sometimes', 'boolean'],
            'linked_record_layout' => ['sometimes', 'in:single,split'],
            'include_eft_records' => ['sometimes', 'boolean'],
            'include_bank_records' => ['sometimes', 'boolean'],
            'include_fsp_records' => ['sometimes', 'boolean'],
            'include_7960_fsp_records' => ['sometimes', 'boolean'],
        ]);

        $statusIds = array_values(array_unique(array_map('intval', (array) ($validated['filter_status'] ?? [6]))));
        $workingSetFilters = [
            'date_from' => (string) $validated['filter_date_from'],
            'date_to' => (string) $validated['filter_date_to'],
            'date_basis' => (string) $validated['filter_date_basis'],
            'currency_code' => (string) $validated['filter_currency_code'],
            'status_ids' => $statusIds ?: [6],
        ];
        $workingSet = $this->workingSetManager->findReady($workingSetFilters);
        if (!$workingSet) {
            $workingSet = $this->workingSetManager->ensure($workingSetFilters);

            return response()->json([
                'success' => false,
                'state' => 'waiting_for_cache',
                'message' => 'Export queued in this browser. It will start when the period cache is complete.',
                'retry_after_seconds' => 3,
                'working_set' => $this->workingSetManager->status($workingSet),
            ], 409, $this->exportStatusNoCacheHeaders());
        }

        try {
            $phpPath = $this->consolePhpBinary();
        } catch (Throwable $exception) {
            Log::error('Unable to find PHP CLI for the All Transactions export.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The export process could not be started because PHP CLI is unavailable.',
            ], 500, $this->exportStatusNoCacheHeaders());
        }

        $userId = (int) auth()->id();
        $runId = (string) Str::uuid();
        $reportsDirectory = storage_path('app/reports');
        if (!is_dir($reportsDirectory)) {
            @mkdir($reportsDirectory, 0775, true);
        }

        $lockFile = $reportsDirectory . "/viefund-all-transactions-{$userId}.lock";
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $hasLiveLock = file_exists($lockFile)
            && (time() - filemtime($lockFile)) < self::ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return response()->json([
                'success' => false,
                'message' => 'Your previous All Transactions export is still running.',
            ], 409);
        }

        $outputBase = sprintf(
            'reports/viefund_all_transactions_%d_%s_%s',
            $userId,
            now()->format('Ymd_His'),
            substr(str_replace('-', '', $runId), 0, 8)
        );
        file_put_contents($lockFile, json_encode([
            'run_id' => $runId,
            'user_id' => $userId,
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
        file_put_contents($statusFile, json_encode([
            'run_id' => $runId,
            'inProgress' => true,
            'success' => null,
            'message' => 'All Transactions export queued...',
            'progress_pct' => 0,
            'data_source' => 'working_set',
            'working_set_id' => $workingSet->id,
            'working_set_generation' => $workingSet->readableGeneration(),
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $repeatArgs = static function (string $option, array $values): string {
            return implode(' ', array_map(
                static fn($value): string => $option . '=' . escapeshellarg((string) $value),
                $values
            ));
        };
        $transactionTypeArgs = $repeatArgs('--transaction-type', (array) ($validated['filter_trx_type'] ?? []));
        $statusArgs = $repeatArgs('--status', $statusIds ?: [6]);
        $matchStatusArgs = $repeatArgs('--match-status', (array) ($validated['filter_match_status'] ?? []));
        $splitSheetsArg = $request->boolean('split_sheets') ? '--split-sheets' : '';
        $hasEftMatchArg = $request->boolean('filter_has_eft_match') ? '--has-eft-match' : '';
        $hasAgraFspMatchArg = $request->boolean('filter_has_agra_fsp_match') ? '--has-agra-fsp-match' : '';
        $has7960FspMatchArg = $request->boolean('filter_has_7960_fsp_match') ? '--has-7960-fsp-match' : '';
        $includeEftRecordsArg = $request->boolean('include_eft_records') ? '--include-eft-records' : '';
        $includeBankRecordsArg = $request->boolean('include_bank_records') ? '--include-bank-records' : '';
        $includeFspRecordsArg = $request->boolean('include_fsp_records') ? '--include-fsp-records' : '';
        $include7960FspRecordsArg = $request->boolean('include_7960_fsp_records') ? '--include-7960-fsp-records' : '';
        $linkedRecordLayoutArg = '--linked-record-layout=' . escapeshellarg(
            (string) ($validated['linked_record_layout'] ?? 'split')
        );
        $logPath = storage_path('logs/viefund-all-transactions-export.log');

        $command = sprintf(
            '%s %s report:viefund-all-transactions --run-id=%s --search=%s --customer-name=%s --plan-account-id=%s --transaction-id=%s --source-id=%s --date-from=%s --date-to=%s --date-basis=%s --output-order=%s --currency-code=%s %s %s %s %s %s %s %s %s %s %s %s %s --output-base=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($runId),
            escapeshellarg(trim((string) ($validated['search'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_customer_name'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_plan_account_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_trx_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_source_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_date_from'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_date_to'] ?? ''))),
            escapeshellarg((string) $validated['filter_date_basis']),
            escapeshellarg((string) $validated['filter_output_order']),
            escapeshellarg((string) $validated['filter_currency_code']),
            $transactionTypeArgs,
            $statusArgs,
            $matchStatusArgs,
            $splitSheetsArg,
            $hasEftMatchArg,
            $hasAgraFspMatchArg,
            $has7960FspMatchArg,
            $includeEftRecordsArg,
            $includeBankRecordsArg,
            $includeFspRecordsArg,
            $include7960FspRecordsArg,
            $linkedRecordLayoutArg,
            escapeshellarg($outputBase),
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background VieFund All Transactions export.', [
            'user_id' => $userId,
            'run_id' => $runId,
            'output_base' => $outputBase,
        ]);

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            @unlink($lockFile);
            file_put_contents($statusFile, json_encode([
                'run_id' => $runId,
                'inProgress' => false,
                'success' => false,
                'message' => 'The export process could not be started.',
                'completed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            return response()->json(['success' => false, 'message' => 'The export process could not be started.'], 500);
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return response()->json([
            'success' => true,
            'message' => 'All Transactions Excel export started.',
            'run_id' => $runId,
        ], 202);
    }

    public function allTransactionsExportStatus(Request $request): JsonResponse
    {
        $runId = $this->allTransactionsRunId($request);
        if ($runId === null) {
            return response()->json([
                'run_id' => null,
                'inProgress' => false,
                'success' => null,
                'message' => 'Idle',
                'progress_pct' => null,
                'download_url' => null,
            ])->withHeaders($this->exportStatusNoCacheHeaders());
        }

        $userId = (int) auth()->id();
        $lockFile = storage_path("app/reports/viefund-all-transactions-{$userId}.lock");
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $hasLiveLock = $this->allTransactionsRunHasLiveLock($lockFile, $runId, $userId);
        $payload = [
            'run_id' => $runId,
            'inProgress' => $hasLiveLock,
            'success' => null,
            'message' => $hasLiveLock ? 'Export in progress...' : 'Idle',
            'progress_pct' => null,
            'download_url' => null,
        ];

        if (is_file($statusFile)) {
            $parsed = json_decode((string) file_get_contents($statusFile), true);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
                $payload['inProgress'] = $hasLiveLock
                    && (($parsed['inProgress'] ?? false) === true)
                    && (($parsed['success'] ?? null) === null);
            }
        }

        $workerPid = (int) ($payload['worker_pid'] ?? 0);
        if ($hasLiveLock && $workerPid === 0 && !empty($payload['updated_at'])) {
            try {
                if (Carbon::parse((string) $payload['updated_at'])->lt(now()->subMinute())) {
                    @unlink($lockFile);
                    $payload['inProgress'] = false;
                    $payload['success'] = false;
                    $payload['message'] = 'The export process stopped unexpectedly. Please retry the export.';
                    $payload['completed_at'] = now()->toIso8601String();
                    $payload['updated_at'] = now()->toIso8601String();
                    $this->writeJsonAtomically($statusFile, $payload);
                    $hasLiveLock = false;
                }
            } catch (Exception) {
                // Leave the normal lock timeout as the fallback for malformed legacy status data.
            }
        }
        if ($hasLiveLock && $workerPid > 0 && function_exists('posix_kill')) {
            $workerIsRunning = @posix_kill($workerPid, 0);
            if (!$workerIsRunning && function_exists('posix_get_last_error') && posix_get_last_error() === 1) {
                // EPERM means the process exists but this PHP process cannot signal it.
                $workerIsRunning = true;
            }

            if (!$workerIsRunning) {
                @unlink($lockFile);
                $payload['inProgress'] = false;
                $payload['success'] = false;
                $payload['message'] = 'The export process stopped unexpectedly. Please retry the export.';
                $payload['completed_at'] = now()->toIso8601String();
                $payload['updated_at'] = now()->toIso8601String();
                $this->writeJsonAtomically($statusFile, $payload);
            }
        }

        if (($payload['success'] ?? null) === true && !empty($payload['output_relative_path'])) {
            $relativePath = ltrim((string) $payload['output_relative_path'], '/');
            if (str_starts_with($relativePath, 'reports/') && is_file(storage_path('app/' . $relativePath))) {
                $payload['download_url'] = route('viefund-transactions.export.download', [
                    'run_id' => $runId,
                ]);
            }
        }

        return response()->json($payload)->withHeaders($this->exportStatusNoCacheHeaders());
    }

    public function downloadAllTransactionsExport(Request $request): BinaryFileResponse|RedirectResponse
    {
        $runId = $this->allTransactionsRunId($request);
        if ($runId === null) {
            return redirect()->route('viefund-transactions.index')->with('error', 'No transaction export run was selected.');
        }

        $userId = (int) auth()->id();
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $parsed = is_file($statusFile)
            ? json_decode((string) file_get_contents($statusFile), true)
            : null;
        $relativePath = is_array($parsed) ? ltrim((string) ($parsed['output_relative_path'] ?? ''), '/') : '';

        if ($relativePath === '' || !str_starts_with($relativePath, 'reports/viefund_all_transactions_' . $userId . '_')) {
            return redirect()->route('viefund-transactions.index')->with('error', 'No completed transaction export was found.');
        }

        $absolutePath = storage_path('app/' . $relativePath);
        if (!is_file($absolutePath)) {
            return redirect()->route('viefund-transactions.index')->with('error', 'The transaction export file was not found.');
        }

        return response()->download($absolutePath, basename($absolutePath));
    }

    public function index(Request $request): View
    {
        $filterRequest = $this->transactionFilterRequest($request, 'customer');
        $search = trim((string) $filterRequest->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $filterRequest->query('filter_trx_type', [])));
        $statusGroupSelected = array_values(array_intersect(
            (array) $filterRequest->query('filter_status_group', []),
            ['not_completed', 'open', 'completed']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $filterRequest->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $filterRequest->query('filter_customer_name', '')),
            'account_id'      => trim((string) $filterRequest->query('filter_account_id', '')),
            'trx_id'          => trim((string) $filterRequest->query('filter_trx_id', '')),
            'trust_trx_id'    => trim((string) $filterRequest->query('filter_trust_trx_id', '')),
            'source_id'       => trim((string) $filterRequest->query('filter_source_id', '')),
            'plan_account_id' => trim((string) $filterRequest->query('filter_plan_account_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'status_group'    => $statusGroupSelected ?: null,
            'created_from'    => trim((string) $filterRequest->query('filter_created_from', '')),
            'created_to'      => trim((string) $filterRequest->query('filter_created_to', '')),
        ]);
        $connectionError    = null;
        $transactions       = null;
        $planAccounts       = null;   // non-null → show plan-list mode instead of transaction table
        $totalRecords       = 0;
        $syncNeeded         = false;
        $localBalances      = collect();
        $currentBalance     = null;
        $calculatedBalance  = null;
        $calculatedBalances = [];
        $pageStartBalances  = [];
        $bannerName         = null;
        $availableTrxTypes  = [];

        // ── Sync status ───────────────────────────────────────────────────────
        // Three states: in-progress (lock file present) → needs sync → up to date
        $syncInProgress = false;
        $syncNeeded     = false;
        $lastSyncedAt   = $this->lastSuccessfulTransactionSyncAt();

        $lockFile = storage_path('app/viefund-sync.lock');
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 14400) {
            // Lock file present and less than 4 hours old → sync is running
            $syncInProgress = true;
        } elseif (env('VIEFUND_FORCE_SYNC_NEEDED', false)) {
            // Dev/test override in .env
            $syncNeeded = true;
        } else {
            // Watermark check: compare local max cash_trx_id against remote max.
            // Cache the remote MAX for 5 minutes to avoid a round-trip on every page load.
            try {
                $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
                $localMax  = (int) (RemoteVieFundCustomerTransaction::max('cash_trx_id') ?? 0);
                $remoteMax = (int) \Illuminate\Support\Facades\Cache::remember(
                    'viefund_remote_max_id',
                    300,
                    fn() =>
                    DB::connection('viefund_sqlsrv')
                        ->table("{$schema}.UB_FundTrxCash as fc")
                        ->join("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                        ->max('ct.ID')
                );
                $syncNeeded = $remoteMax > $localMax;
            } catch (Exception) {
                // Suppress silently — main query will surface any real connection error
            }
        }

        try {
            // Only fetch transactions when the user has provided a filter or search term.
            // On the initial (unfiltered) page load we show a prompt instead.
            $hasQuery = $search || !empty(array_filter($filters));

            // Mode switching:
            // • account_id set                → full transaction table (focused view)
            // • customer_id only (no account) → plan-list mode filtered to that customer
            // • any other filter/search       → show matching plan account list
            $isTransactionMode = !empty($filters['account_id']);

            // Defer the expensive COUNT query — only needed when displaying the summary card
            if ($hasQuery && $isTransactionMode) {
                $totalRecords = $this->remoteService->countTransactions();
            }

            if ($hasQuery && !$isTransactionMode) {
                // Plan-list mode: return distinct matching plan accounts
                $planAccounts = $this->remoteService->fetchMatchingPlanAccounts($search ?: null, $filters);
            } elseif ($hasQuery) {
                $transactions = $this->remoteService->fetchTransactions($search ?: null, $filters);
                $availableTrxTypes = $this->remoteService->fetchDistinctTrxTypes($filters);
                $cashTrxIds = $transactions->getCollection()
                    ->pluck('cash_trx_id')
                    ->filter()
                    ->values();

                if ($cashTrxIds->isNotEmpty()) {
                    $localBalances = RemoteVieFundCustomerTransaction::whereIn('cash_trx_id', $cashTrxIds)
                        ->pluck('running_balance', 'cash_trx_id');
                }
            }

            // Banner name + current balance for focused (transaction) mode only
            if ($isTransactionMode && !empty($filters['customer_id'])) {
                $bannerName         = $filters['customer_name'] ?? null;
                $currentBalance     = $this->remoteService->getLatestBalance($filters);
                $calculatedBalances = $this->remoteService->getCalculatedBalancesByPlan($filters);
                $calculatedBalance  = !empty($calculatedBalances) ? array_sum($calculatedBalances) : null;
                $pageStartBalances  = $this->remoteService->getPageStartBalancesByPlan(
                    $filters,
                    $transactions->currentPage(),
                    $transactions->perPage(),
                    $search ?: null
                );
            } elseif (!empty($filters['account_id'])) {
                $customer = $this->remoteService->getCustomerForPlanAccount($filters['account_id']);
                if ($customer) {
                    $bannerName         = $customer['customer_name'];
                    $currentBalance     = $this->remoteService->getLatestBalance($filters);
                    $calculatedBalances = $this->remoteService->getCalculatedBalancesByPlan($filters);
                    $calculatedBalance  = !empty($calculatedBalances) ? array_sum($calculatedBalances) : null;
                    $pageStartBalances  = $this->remoteService->getPageStartBalancesByPlan(
                        $filters,
                        $transactions->currentPage(),
                        $transactions->perPage(),
                        $search ?: null
                    );
                }
            }
        } catch (Exception $e) {
            $connectionError = 'Could not connect to the remote VieFund database: ' . $e->getMessage();
        }

        return view('remote-viefund.index', compact(
            'transactions',
            'planAccounts',
            'totalRecords',
            'search',
            'filters',
            'connectionError',
            'localBalances',
            'currentBalance',
            'calculatedBalance',
            'calculatedBalances',
            'pageStartBalances',
            'bannerName',
            'availableTrxTypes',
            'syncNeeded',
            'syncInProgress',
            'lastSyncedAt'
        ));
    }

    public function export(Request $request): BinaryFileResponse|StreamedResponse
    {
        $filterRequest = $this->transactionFilterRequest($request, 'customer');
        $format = $filterRequest->query('format', 'csv');
        $search = trim((string) $filterRequest->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $filterRequest->query('filter_trx_type', [])));
        $statusGroupSelected = array_values(array_intersect(
            (array) $filterRequest->query('filter_status_group', []),
            ['not_completed', 'open', 'completed']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $filterRequest->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $filterRequest->query('filter_customer_name', '')),
            'account_id'      => trim((string) $filterRequest->query('filter_account_id', '')),
            'trx_id'          => trim((string) $filterRequest->query('filter_trx_id', '')),
            'trust_trx_id'    => trim((string) $filterRequest->query('filter_trust_trx_id', '')),
            'source_id'       => trim((string) $filterRequest->query('filter_source_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'status_group'    => $statusGroupSelected ?: null,
            'created_from'    => trim((string) $filterRequest->query('filter_created_from', '')),
            'created_to'      => trim((string) $filterRequest->query('filter_created_to', '')),
        ]);

        $rows = $this->remoteService->exportTransactions($search ?: null, $filters)
            ->sortBy([['plan_dealer_account_id', 'asc'], ['created_date', 'asc'], ['trx_id', 'asc']])
            ->values();

        $runningBalances = [];
        $rows = $rows->map(function ($row) use (&$runningBalances) {
            $planId = (string) ($row->plan_dealer_account_id ?? '');
            $runningBalances[$planId] = $runningBalances[$planId] ?? 0.0;

            $amount = $row->amount !== null ? (float) $row->amount : 0.0;
            $runningBalances[$planId] = round(($runningBalances[$planId] + $amount) * 10000) / 10000;
            $row->calculated_balance = $runningBalances[$planId];
            $row->display_trx_id = $this->buildDisplayTrxId($row);

            return $row;
        });

        $accountId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($filters['account_id'] ?? 'export'));
        $timestamp = now()->format('Ymd_His');
        $filename = "viefund_trx_{$accountId}_{$timestamp}";

        $headers = [
            'Display Txn ID',
            'Fund Trx ID',
            'Cash Trx ID',
            'Trust Trx ID',
            'Source ID',
            'Client Name',
            'Rep Code',
            'Plan Account ID',
            'Txn Type',
            'Txn Type Detail',
            'Status',
            'Created Date',
            'Trade Date',
            'Processing Date',
            'Settlement Date',
            'Amount -',
            'Amount +',
            'Calculated Balance',
            'Notes',
        ];

        if ($format === 'excel') {
            return Excel::download(
                new VieFundReportSheetExport(
                    $this->buildExportSheetRows($headers, $rows),
                    'Transactions'
                ),
                $filename . '.xlsx',
                ExcelWriter::XLSX
            );
        }
        return $this->streamCsv($filename, $headers, $rows);
    }

    /**
     * @param array<int, string> $headers
     * @param iterable<object> $rows
     * @return array<int, array<int, string|int|float|null>>
     */
    private function buildExportSheetRows(array $headers, iterable $rows): array
    {
        $sheetRows = [$headers];

        foreach ($rows as $row) {
            $amount  = (float) $row->amount;
            $balance = $row->calculated_balance !== null ? (float) $row->calculated_balance : null;
            $sheetRows[] = [
                $row->display_trx_id,
                $row->fund_trx_id,
                $row->row_source === 'fund' ? $row->cash_trx_id : '',
                $row->trust_trx_id ?? $row->linked_trust_trx_id ?? '',
                $row->source_id,
                trim($row->client_name),
                $row->rep_code,
                $row->plan_dealer_account_id,
                $row->trx_type,
                $row->cash_trx_type,
                $row->status ?? '',
                $row->created_date,
                $row->trade_date,
                $row->processing_date,
                $row->settlement_date,
                $amount < 0 ? number_format(abs($amount), 2) : '',
                $amount >= 0 ? number_format($amount, 2) : '',
                $balance !== null ? number_format($balance, 2) : '',
                $row->notes ?? '',
            ];
        }

        return $sheetRows;
    }

    private function streamCsv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens it correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                $amount  = (float) $row->amount;
                $balance = $row->calculated_balance !== null ? (float) $row->calculated_balance : null;
                fputcsv($out, [
                    $row->display_trx_id,
                    $row->fund_trx_id,
                    $row->row_source === 'fund' ? $row->cash_trx_id : '',
                    $row->trust_trx_id ?? $row->linked_trust_trx_id ?? '',
                    $row->source_id,
                    trim($row->client_name),
                    $row->rep_code,
                    $row->plan_dealer_account_id,
                    $row->trx_type,
                    $row->cash_trx_type,
                    $row->status ?? '',
                    $row->created_date,
                    $row->trade_date,
                    $row->processing_date,
                    $row->settlement_date,
                    $amount < 0  ? number_format(abs($amount), 2) : '',
                    $amount >= 0 ? number_format($amount, 2) : '',
                    $balance !== null ? number_format($balance, 2) : '',
                    $row->notes ?? '',
                ]);
            }
            fclose($out);
        }, $filename . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildDisplayTrxId(object $row): string
    {
        if (($row->row_source ?? '') === 'trust') {
            $trustId = $row->trust_trx_id ?? $row->cash_trx_id ?? null;
            return $trustId ? 'T-' . $trustId : 'T-UNKNOWN';
        }

        if (!empty($row->cash_trx_id)) {
            return 'C-' . $row->cash_trx_id;
        }

        if (!empty($row->fund_trx_id)) {
            return 'F-' . $row->fund_trx_id;
        }

        return 'F-UNKNOWN';
    }

    public function syncStatus(): JsonResponse
    {
        $lockFile   = storage_path('app/viefund-sync.lock');
        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < 14400;

        $syncNeeded = false;
        if (!$inProgress) {
            try {
                $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
                $localMax  = (int) (RemoteVieFundCustomerTransaction::max('cash_trx_id') ?? 0);
                $remoteMax = (int) DB::connection('viefund_sqlsrv')
                    ->table("{$schema}.UB_FundTrxCash as fc")
                    ->join("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                    ->max('ct.ID');
                $syncNeeded = $remoteMax > $localMax;
            } catch (Exception) {
                // Suppress
            }
        }

        $lastSyncedAt = $this->lastSuccessfulTransactionSyncAt();

        return response()->json([
            'inProgress' => $inProgress,
            'syncNeeded' => $syncNeeded,
            'lastSyncedAt' => $lastSyncedAt?->timezone(config('app.display_timezone', 'America/Toronto'))->format('M j, Y g:i A T'),
        ]);
    }

    private function lastSuccessfulTransactionSyncAt(): ?Carbon
    {
        $statusFile = storage_path('app/viefund-transactions-sync-status.json');
        if (!file_exists($statusFile)) {
            return null;
        }

        try {
            $status = json_decode((string) file_get_contents($statusFile), true, flags: JSON_THROW_ON_ERROR);

            return isset($status['completed_at']) ? Carbon::parse($status['completed_at']) : null;
        } catch (Exception) {
            return null;
        }
    }

    public function sync(Request $request): RedirectResponse
    {
        $artisanPath = base_path('artisan');
        $lockFile    = storage_path('app/viefund-sync.lock');
        $logPath     = storage_path('logs/viefund-sync.log');
        $phpPath     = $this->consolePhpBinary();

        // Create lock file so the UI shows "in progress" immediately
        file_put_contents($lockFile, date('c'));

        $command = sprintf(
            '%s %s viefund:sync-customers >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching viefund:sync-customers in background: ' . $command);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $filterToken = strtolower(trim((string) $request->input('filter_token', '')));

        return redirect()->route('remote-viefund.index', preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $filterToken
        ) ? ['filter_token' => $filterToken] : []);
    }

    public function planAccounts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        try {
            return response()->json($this->remoteService->searchPlanAccounts($search));
        } catch (Exception $e) {
            return response()->json([], 500);
        }
    }

    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        try {
            return response()->json($this->remoteService->searchCustomers($search));
        } catch (Exception $e) {
            return response()->json([], 500);
        }
    }

    public function planAccountSnapshot(Request $request): JsonResponse
    {
        $accountId = trim((string) $request->query('account_id', ''));
        if ($accountId === '') {
            return response()->json(['error' => 'account_id is required'], 422);
        }
        try {
            $snapshot = $this->remoteService->getPlanAccountSnapshot($accountId);
            if (!$snapshot) {
                return response()->json(['error' => 'No snapshot found for this account.'], 404);
            }
            return response()->json($snapshot);
        } catch (Exception $e) {
            Log::error('Plan account snapshot error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not load snapshot.'], 500);
        }
    }

    private function transactionFilterRequest(Request $request, string $scope): Request
    {
        $token = strtolower(trim((string) $request->query('filter_token', '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $token)) {
            return $request;
        }

        $filterSets = (array) $request->session()->get("viefund_transaction_filter_sets.{$scope}", []);
        $storedFilters = $filterSets[$token]['filters'] ?? null;
        if (!is_array($storedFilters)) {
            return $request;
        }

        // Keep the potentially large filter payload in the server-side session.
        // Small navigation values in the URL (page, sort, per_page) still take precedence.
        return $request->duplicate(array_merge($storedFilters, $request->query->all()));
    }

    private function rememberTransactionFilters(
        Request $request,
        string $scope,
        array $filters,
        string $routeName
    ): RedirectResponse {
        $token = (string) Str::uuid();
        $sessionKey = "viefund_transaction_filter_sets.{$scope}";
        $filterSets = array_filter((array) $request->session()->get($sessionKey, []), 'is_array');
        $filterSets[$token] = [
            'filters' => array_filter($filters, fn($value) => $value !== null && $value !== '' && $value !== []),
            'created_at' => now()->timestamp,
        ];
        uasort($filterSets, fn(array $left, array $right) => ($left['created_at'] ?? 0) <=> ($right['created_at'] ?? 0));
        $request->session()->put(
            $sessionKey,
            array_slice($filterSets, -self::TRANSACTION_FILTER_SET_LIMIT, null, true)
        );

        return redirect()->route($routeName, ['filter_token' => $token]);
    }

    private function canonicalTransactionTypes(array $transactionTypes): array
    {
        $transactionTypes = array_values(array_unique(array_filter(array_map(
            fn($type) => trim((string) $type),
            $transactionTypes
        ))));
        sort($transactionTypes);

        if ($transactionTypes === []) {
            return [];
        }

        try {
            $availableTypes = $this->remoteService->fetchDistinctTrxTypes();
            sort($availableTypes);
            if ($availableTypes !== [] && $transactionTypes === $availableTypes) {
                return [];
            }
        } catch (Exception $e) {
            Log::warning('Unable to simplify the transaction type filter.', ['exception' => $e]);
        }

        return $transactionTypes;
    }

    private function allTransactionsRunId(Request $request): ?string
    {
        $runId = strtolower(trim((string) $request->input('run_id', '')));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $runId)
            ? $runId
            : null;
    }

    private function allTransactionsStatusFile(int $userId, string $runId): string
    {
        return storage_path("app/reports/viefund-all-transactions-{$userId}-{$runId}-status.json");
    }

    private function consolePhpBinary(): string
    {
        $configured = trim((string) config('app.php_cli_path', ''));
        $fpmSibling = str_contains(basename(PHP_BINARY), 'php-fpm')
            ? dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php'
            : null;
        $currentBinary = str_contains(basename(PHP_BINARY), 'php-fpm') ? null : PHP_BINARY;
        $candidates = array_filter([
            $configured,
            $fpmSibling,
            (new ExecutableFinder())->find('php'),
            $currentBinary,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('A PHP CLI executable could not be found. Configure PHP_PATH.');
    }

    private function allTransactionsRunHasLiveLock(string $lockFile, string $runId, int $userId): bool
    {
        if (!is_file($lockFile) || (time() - filemtime($lockFile)) >= self::ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS) {
            return false;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);

        return is_array($lock)
            && hash_equals((string) ($lock['run_id'] ?? ''), $runId)
            && (int) ($lock['user_id'] ?? 0) === $userId;
    }

    private function exportStatusNoCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }

    private function writeJsonAtomically(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT);
        $temporaryFile = $encoded !== false ? tempnam($directory, basename($path) . '.tmp.') : false;
        if ($temporaryFile === false) {
            return;
        }

        if (@file_put_contents($temporaryFile, $encoded, LOCK_EX) === false || !@rename($temporaryFile, $path)) {
            @unlink($temporaryFile);
        }
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

        // Settlement dates are comparable on both reports. For the other VieFund
        // date bases, retain the complete imported FSP identifier set so a valid
        // cross-date match is never discarded by an unrelated FSP date field.
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

    /** Resolve the configured or cached inception date for a cash date basis. */
    private function resolveAllTransactionInceptionDate(string $dateBasis): ?string
    {
        $specificEnvKey = self::ALL_TRANSACTION_INCEPTION_ENV_KEYS[$dateBasis] ?? null;
        $configured = $specificEnvKey ? env($specificEnvKey) : null;
        $configured = $configured ?: env('VIEFUND_REPORT_INCEPTION_DATE');

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($configured))->toDateString();
            } catch (\Throwable) {
                // Fall through to the cached date refreshed by the reports workflow.
            }
        }

        return Cache::get("viefund:inception-date:{$dateBasis}");
    }
}
