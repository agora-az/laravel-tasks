<?php

namespace App\Http\Controllers;

use App\Exports\VieFundReportSheetExport;
use App\Models\BankStatementEntry;
use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundCashSnapshotRun;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyTotalsDrilldownController extends Controller
{
    private const PARSER_VERSION = 'v2';

    /** Fund transaction statuses (UB_Def_TrxStatus id => label). */
    private const FUND_STATUS_LABELS = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];

    private const VIEFUND_CURRENCY_CODE = '00';

    /** Date basis keys => labels. */
    private const DATE_BASIS_LABELS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];

    /** Date basis keys => short filename codes (matches the reports page). */
    private const DATE_BASIS_FILE_CODES = [
        'create_date' => 'cr',
        'trade_date' => 'tr',
        'processing_date' => 'pr',
        'settlement_date' => 'se',
    ];

    public function __construct(
        private readonly VieFundRemoteService $vieFundRemoteService,
        private readonly SqlServerEftRemoteRepository $eftRepository,
    ) {}

    /** Configured fallback fund status IDs (VIEFUND_DEFAULT_FUND_STATUS). */
    private function defaultStatusIds(): array
    {
        return (array) config('viefund.default_fund_status', [6]);
    }

    private function defaultBasis(): string
    {
        return (string) config('viefund.default_date_basis', 'settlement_date');
    }

    /**
     * Resolve the audited direct-cash criteria represented by a Daily Totals row.
     *
     * @return array{0: int[], 1: string, 2: string, 3: ?string}
     */
    private function resolveDayFilters(Carbon $day, ?string $variantKey): array
    {
        $row = $variantKey
            ? VieFundCashDailySnapshot::where('criteria_key', $variantKey)->first()
            : null;

        if (!$row) {
            $basis = $this->defaultBasis();
            $statusIds = $this->defaultStatusIds();
            $variantKey = VieFundCashDailySnapshot::criteriaKey($basis, self::VIEFUND_CURRENCY_CODE, $statusIds);
            $row = VieFundCashDailySnapshot::where('criteria_key', $variantKey)->first();
        }

        $statusIds = array_values(array_filter(
            array_map('intval', (array) $row?->status_ids),
            fn($id) => array_key_exists($id, self::FUND_STATUS_LABELS)
        ));
        if (empty($statusIds)) {
            $statusIds = $this->defaultStatusIds();
        }

        $basis = array_key_exists($row?->date_basis, self::DATE_BASIS_LABELS)
            ? $row->date_basis
            : $this->defaultBasis();
        $criteriaKey = $row?->criteria_key
            ?? VieFundCashDailySnapshot::criteriaKey($basis, self::VIEFUND_CURRENCY_CODE, $statusIds);
        $availabilityAsOf = VieFundCashSnapshotRun::where('criteria_key', $criteriaKey)
            ->where('status', 'completed')
            ->max('requested_to');

        return [$statusIds, $basis, $criteriaKey, $availabilityAsOf ? Carbon::parse($availabilityAsOf)->toDateString() : null];
    }

    private function describeStatuses(array $statusIds): string
    {
        $fundLabels = array_map(fn($id) => self::FUND_STATUS_LABELS[$id] ?? $id, $statusIds);

        return $fundLabels ? implode(', ', $fundLabels) : 'none';
    }

    public function bankDay(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $account = trim((string) $request->query('account', ''));
        $entryId = $request->integer('entry_id') ?: null;
        $settlementNumbers = collect(preg_split('/[,\s]+/', (string) $request->query('settlement_numbers', ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn($number) => trim((string) $number))
            ->filter()
            ->unique()
            ->take(500)
            ->values();
        $allDates = $request->boolean('all_dates') && $settlementNumbers->isNotEmpty();
        $onlyFundservBank = $request->has('only_fundserv_bank')
            ? $request->boolean('only_fundserv_bank')
            : false;
        $validPerPage = [50, 100, 250];
        $perPage = in_array((int) $request->query('per_page', 100), $validPerPage, true)
            ? (int) $request->query('per_page', 100)
            : 100;

        $transactions = BankStatementEntry::query()
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->when(!$allDates, fn($query) => $query->whereDate('bank_statement_entries.value_date', '=', $day->toDateString()))
            ->when($account !== '', fn($query) => $query->where('bank_statement_entries.account_number', $account))
            ->when($entryId, fn($query) => $query->where('bank_statement_entries.id', $entryId))
            ->when($settlementNumbers->isNotEmpty(), fn($query) => $query->whereIn('a.settlement_number', $settlementNumbers->all()))
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->select([
                'bank_statement_entries.id',
                'bank_statement_entries.value_date',
                'bank_statement_entries.credit_debit_indicator',
                'bank_statement_entries.amount',
                'bank_statement_entries.additional_info',
                'bank_statement_entries.source_file',
                'a.memo_type',
                'a.counterparty',
                'a.settlement_number',
                'a.wire_payment_reference',
            ])
            ->orderBy('bank_statement_entries.id')
            ->paginate($perPage)
            ->withQueryString();

        $summary = DB::table('bank_statement_entries')
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->when(!$allDates, fn($query) => $query->whereDate('bank_statement_entries.value_date', '=', $day->toDateString()))
            ->when($account !== '', fn($query) => $query->where('bank_statement_entries.account_number', $account))
            ->when($entryId, fn($query) => $query->where('bank_statement_entries.id', $entryId))
            ->when($settlementNumbers->isNotEmpty(), fn($query) => $query->whereIn('a.settlement_number', $settlementNumbers->all()))
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->selectRaw('COUNT(*) as transaction_count, SUM(CASE WHEN credit_debit_indicator = "DBIT" THEN -amount ELSE amount END) as net_total')
            ->first();

        return view('reconciliations/daily-bank-transactions', [
            'date' => $day->toDateString(),
            'transactions' => $transactions,
            'summary' => $summary,
            'onlyFundservBank' => $onlyFundservBank,
            'account' => $account,
            'entryId' => $entryId,
            'settlementNumbers' => $settlementNumbers,
            'allDates' => $allDates,
        ]);
    }

    public function bankDayExport(Request $request, string $date): BinaryFileResponse|StreamedResponse
    {
        $day = $this->parseDateOrFail($date);
        $account = trim((string) $request->query('account', ''));
        $settlementNumbers = collect(preg_split('/[,\s]+/', (string) $request->query('settlement_numbers', ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn($number) => trim((string) $number))
            ->filter()
            ->unique()
            ->take(500)
            ->values();
        $allDates = $request->boolean('all_dates') && $settlementNumbers->isNotEmpty();
        $onlyFundservBank = $request->has('only_fundserv_bank')
            ? $request->boolean('only_fundserv_bank')
            : false;
        $format = $request->query('format', 'csv');

        if (!in_array($format, ['csv', 'excel'], true)) {
            abort(422, 'Invalid export format.');
        }

        $rows = BankStatementEntry::query()
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->when(!$allDates, fn($query) => $query->whereDate('bank_statement_entries.value_date', '=', $day->toDateString()))
            ->when($account !== '', fn($query) => $query->where('bank_statement_entries.account_number', $account))
            ->when($settlementNumbers->isNotEmpty(), fn($query) => $query->whereIn('a.settlement_number', $settlementNumbers->all()))
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->select([
                'bank_statement_entries.id',
                'bank_statement_entries.value_date',
                'bank_statement_entries.credit_debit_indicator',
                'bank_statement_entries.amount',
                'bank_statement_entries.additional_info',
                'bank_statement_entries.source_file',
                'a.memo_type',
                'a.counterparty',
                'a.settlement_number',
                'a.wire_payment_reference',
            ])
            ->orderBy('bank_statement_entries.id')
            ->get();

        $sheetRows = [
            ['ID', 'Dir', 'Amount', 'Memo Type', 'Counterparty', 'Settlement #', 'Wire Ref', 'Description'],
        ];

        foreach ($rows as $txn) {
            $sheetRows[] = [
                $txn->id,
                $txn->credit_debit_indicator,
                (float) $txn->amount,
                $txn->memo_type,
                $txn->counterparty,
                $txn->settlement_number,
                $txn->wire_payment_reference,
                $txn->additional_info,
            ];
        }

        $filename = 'bank_daily_transactions_' . $day->toDateString() . '.' . ($format === 'excel' ? 'xlsx' : 'csv');

        if ($format === 'excel') {
            return Excel::download(
                new VieFundReportSheetExport($sheetRows, 'Bank Daily Transactions'),
                $filename,
                ExcelWriter::XLSX
            );
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['ID', 'Dir', 'Amount', 'Memo Type', 'Counterparty', 'Settlement #', 'Wire Ref', 'Description']);

            foreach ($rows as $txn) {
                fputcsv($out, [
                    $txn->id,
                    $txn->credit_debit_indicator,
                    $txn->amount,
                    $txn->memo_type,
                    $txn->counterparty,
                    $txn->settlement_number,
                    $txn->wire_payment_reference,
                    $txn->additional_info,
                ]);
            }

            fclose($out);
        }, $filename);
    }

    public function settlementSequences(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $account = trim((string) $request->query('account', ''));
        $include3000Sequences = $request->boolean('include_3000_sequences');

        $sourceSequences = DB::table('bank_statement_entries')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereDate('bank_statement_entries.value_date', $day->toDateString())
            ->when($account !== '', fn($query) => $query->where('bank_statement_entries.account_number', $account))
            ->whereNotNull('a.settlement_number')
            ->where('a.settlement_number', '<>', '')
            ->distinct()
            ->pluck('a.settlement_number')
            ->map(fn($sequence) => trim((string) $sequence))
            ->filter()
            ->when(!$include3000Sequences, fn($sequences) => $sequences->reject(
                fn($sequence) => ctype_digit($sequence)
                    && (int) $sequence >= 3000
                    && (int) $sequence < 4000
            ))
            ->values();

        $bankBySequence = DB::table('bank_statement_entries')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereIn('a.settlement_number', $sourceSequences->all())
            ->selectRaw('a.settlement_number')
            ->selectRaw('COUNT(*) as bank_transaction_count')
            ->selectRaw("SUM(CASE WHEN bank_statement_entries.credit_debit_indicator = 'DBIT' THEN -bank_statement_entries.amount ELSE bank_statement_entries.amount END) as bank_net_total")
            ->selectRaw('MIN(bank_statement_entries.value_date) as first_bank_date')
            ->selectRaw('MAX(bank_statement_entries.value_date) as last_bank_date')
            ->selectRaw('COUNT(DISTINCT bank_statement_entries.account_number) as bank_account_count')
            ->selectRaw("GROUP_CONCAT(DISTINCT bank_statement_entries.account_number ORDER BY bank_statement_entries.account_number SEPARATOR ', ') as bank_accounts")
            ->groupBy('a.settlement_number')
            ->get()
            ->keyBy(fn($row) => (string) (int) $row->settlement_number);

        $eftBySequence = $this->eftRepository
            ->totalsBySequences($sourceSequences->all())
            ->groupBy(fn($row) => (string) (int) $row->sequence_number);

        $rows = $sourceSequences
            ->map(fn($sequence) => (string) (int) $sequence)
            ->unique()
            ->map(function (string $sequence) use ($bankBySequence, $eftBySequence) {
                $bank = $bankBySequence->get($sequence);
                $eftFiles = $eftBySequence->get($sequence, collect());
                $bankNet = (float) ($bank?->bank_net_total ?? 0);
                $eftNet = $eftFiles->sum(fn($file) => (float) $file->net_total);

                return [
                    'sequence' => $sequence,
                    'bank_transaction_count' => (int) ($bank?->bank_transaction_count ?? 0),
                    'bank_net_total' => $bankNet,
                    'first_bank_date' => $bank?->first_bank_date,
                    'last_bank_date' => $bank?->last_bank_date,
                    'bank_account_count' => (int) ($bank?->bank_account_count ?? 0),
                    'bank_accounts' => (string) ($bank?->bank_accounts ?? ''),
                    'eft_file_count' => $eftFiles->count(),
                    'eft_transaction_count' => $eftFiles->sum(fn($file) => (int) $file->transaction_count),
                    'eft_net_total' => $eftNet,
                    'variance' => $bankNet - $eftNet,
                ];
            })
            ->sortBy('sequence', SORT_NATURAL)
            ->values();

        $summary = [
            'bank_net_total' => $rows->sum(fn(array $row) => $row['bank_net_total']),
            'eft_net_total' => $rows->sum(fn(array $row) => $row['eft_net_total']),
            'variance' => $rows->sum(fn(array $row) => $row['variance']),
        ];

        return view('reconciliations.daily-settlement-sequences', [
            'date' => $day->toDateString(),
            'account' => $account,
            'rows' => $rows,
            'summary' => $summary,
            'include3000Sequences' => $include3000Sequences,
        ]);
    }

    public function fspComparison(Request $request, string $source, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        abort_unless(in_array($source, ['agra', '7960'], true), 404);

        $sourceType = $source === '7960' ? 'ltm' : 'fundserv_agra';
        $sourceLabel = $source === '7960' ? '7960' : 'AGRA';
        $currency = strtoupper(trim((string) $request->query('currency', 'CAD')));
        $entryId = $request->integer('entry_id') ?: null;
        $perPage = in_array($request->integer('per_page', 100), [50, 100, 250], true)
            ? $request->integer('per_page', 100)
            : 100;
        $sort = in_array($request->query('sort'), ['side', 'amount'], true)
            ? (string) $request->query('sort')
            : null;
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $amountSearchInput = trim((string) $request->query('amount', ''));
        $normalizedAmountSearch = preg_replace('/[^0-9.]/', '', $amountSearchInput);
        $amountSearch = $normalizedAmountSearch !== '' ? $normalizedAmountSearch : null;

        $bankTransaction = DB::table('bank_statement_entries as b')
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'b.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->when($entryId, fn($query) => $query->where('b.id', $entryId))
            ->whereDate('b.value_date', $day->toDateString())
            ->select([
                'b.id',
                'b.value_date',
                'b.account_number',
                'b.credit_debit_indicator',
                'b.amount',
                'b.additional_info',
                'a.memo_type',
                'a.counterparty',
                'a.wire_payment_reference',
            ])
            ->first();

        $bankNet = $bankTransaction
            ? ($bankTransaction->credit_debit_indicator === 'DBIT' ? -(float) $bankTransaction->amount : (float) $bankTransaction->amount)
            : 0.0;

        $fspBase = DB::table('settlement_instructions')
            ->where('source_type', $sourceType)
            ->whereDate('settlement_date', $day->toDateString())
            ->where('currency', $currency);
        $fspSummary = (clone $fspBase)
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw("SUM(CASE WHEN side = 'SELL' THEN COALESCE(settlement_amount, 0) WHEN side = 'BUY' THEN -COALESCE(settlement_amount, 0) ELSE 0 END) as net_total")
            ->first();
        $fspTransactions = (clone $fspBase)
            ->when($amountSearch !== null, fn($query) => $query->whereRaw(
                'CAST(ABS(COALESCE(settlement_amount, 0)) AS CHAR) LIKE ?',
                ['%'.$amountSearch.'%']
            ))
            ->when($sort === 'side', fn($query) => $query->orderBy('side', $direction))
            ->when($sort === 'amount', fn($query) => $query->orderByRaw(
                "CASE WHEN side = 'BUY' THEN -COALESCE(settlement_amount, 0) WHEN side = 'SELL' THEN COALESCE(settlement_amount, 0) ELSE 0 END ".strtoupper($direction)
            ))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
        $fspNet = (float) ($fspSummary?->net_total ?? 0);

        return view('reconciliations.fsp-comparison', [
            'date' => $day->toDateString(),
            'source' => $source,
            'sourceType' => $sourceType,
            'sourceLabel' => $sourceLabel,
            'currency' => $currency,
            'bankTransaction' => $bankTransaction,
            'bankNet' => $bankNet,
            'fspTransactions' => $fspTransactions,
            'fspItemCount' => (int) ($fspSummary?->item_count ?? 0),
            'fspNet' => $fspNet,
            'variance' => $bankNet - $fspNet,
            'perPage' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'amountSearchInput' => $amountSearchInput,
        ]);
    }

    public function eftSequenceComparison(Request $request, string $date, string $sequence): View
    {
        $day = $this->parseDateOrFail($date);
        $sequence = trim($sequence);
        abort_unless($sequence !== '' && ctype_digit($sequence), 404);
        $account = trim((string) $request->query('account', ''));
        $amountSearchInput = trim((string) $request->query('amount', ''));
        $amountSearch = preg_replace('/[^0-9.]/', '', $amountSearchInput);
        $amountSearch = $amountSearch !== '' ? $amountSearch : null;
        $amountSortDirection = strtolower((string) $request->query('amount_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortByAmount = $request->query('sort') === 'amount';

        $bankTransactions = DB::table('bank_statement_entries as b')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'b.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->where('a.settlement_number', $sequence)
            ->select([
                'b.id',
                'b.value_date',
                'b.account_number',
                'b.credit_debit_indicator',
                'b.amount',
                'b.additional_info',
                'a.memo_type',
                'a.counterparty',
                'a.settlement_number',
                'a.wire_payment_reference',
            ])
            ->orderBy('b.value_date')
            ->orderBy('b.id')
            ->get();

        $bankNet = $bankTransactions->sum(fn($item) => $item->credit_debit_indicator === 'DBIT'
            ? -(float) $item->amount
            : (float) $item->amount);

        $eftFiles = $this->eftRepository->totalsBySequences([$sequence]);
        $eftItemCount = $eftFiles->sum(fn($file) => (int) $file->transaction_count);
        $eftNet = $eftFiles->sum(fn($file) => (float) $file->net_total);
        $eftItems = $this->eftRepository->paginateItems([
            'file_id' => '',
            'sequences' => $sequence,
            'date_basis' => 'created',
            'date_from' => '',
            'date_to' => '',
            'type' => '',
            'file_status' => '',
            'item_status' => '',
            'file_search' => '',
            'item_search' => '',
            'amount_contains' => $amountSearch,
        ], $sortByAmount ? 'signed_amount' : 'created_at', $sortByAmount ? $amountSortDirection : 'desc', 100, 'eft_page')->withQueryString();

        return view('reconciliations.eft-sequence-comparison', [
            'date' => $day->toDateString(),
            'account' => $account,
            'sequence' => $sequence,
            'include3000Sequences' => $request->boolean('include_3000_sequences'),
            'bankTransactions' => $bankTransactions,
            'bankNet' => $bankNet,
            'eftItems' => $eftItems,
            'eftItemCount' => $eftItemCount,
            'eftNet' => $eftNet,
            'variance' => $bankNet - $eftNet,
            'amountSearchInput' => $amountSearchInput,
            'sortByAmount' => $sortByAmount,
            'amountSortDirection' => $amountSortDirection,
        ]);
    }

    public function viefundDay(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $page = max(1, (int) $request->query('viefund_page', 1));
        $validPerPage = [50, 100, 250];
        $perPage = in_array((int) $request->query('per_page', 250), $validPerPage, true)
            ? (int) $request->query('per_page', 250)
            : 250;

        [$statusIds, $basis, $criteriaKey, $availabilityAsOf] = $this->resolveDayFilters($day, $request->query('variant'));
        $fundCriteria = $this->describeStatuses($statusIds);
        $hideZero = $request->boolean('hide_zero');
        $tableFilters = $request->only(['search', 'source_id', 'order_id', 'trx_type', 'status', 'sort', 'direction']);
        $hasActiveFilters = collect($request->only(['search', 'source_id', 'order_id', 'trx_type', 'status']))
            ->contains(fn($value) => trim((string) $value) !== '');

        $result = $this->vieFundRemoteService->fetchCustomerCashTransactionsByDateColumn(
            $day,
            $basis,
            array_merge(['status_ids' => $statusIds, 'availability_as_of' => $availabilityAsOf], $tableFilters),
            $perPage,
            $page,
            $hideZero
        );
        $snapshot = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)
            ->whereDate('total_date', $day->toDateString())
            ->first();

        $auditedSummary = (object) [
            'transaction_count' => $snapshot?->transaction_count ?? $result['transaction_count'],
            'net_total' => $snapshot?->net_total ?? $result['net_total'],
        ];
        $liveSummary = (object) ['transaction_count' => $result['transaction_count'], 'net_total' => $result['net_total']];

        return view('reconciliations/daily-viefund-transactions', [
            'date' => $day->toDateString(),
            'transactions' => $result['items'],
            'summary' => $hasActiveFilters ? $liveSummary : $auditedSummary,
            'auditedSummary' => $auditedSummary,
            'liveSummary' => $liveSummary,
            'fundCriteria' => $fundCriteria,
            'basisLabel' => self::DATE_BASIS_LABELS[$basis] ?? $basis,
            'criteriaKey' => $criteriaKey,
            'hideZero' => $hideZero,
            'hasActiveFilters' => $hasActiveFilters,
        ]);
    }

    /**
     * Stream the full merged fund + trust listing for a day as CSV, using the
     * same stored snapshot filters as the drilldown view (all rows, not just
     * the current page).
     */
    public function viefundDayExport(Request $request, string $date): BinaryFileResponse|StreamedResponse
    {
        $day = $this->parseDateOrFail($date);
        [$statusIds, $basis, $criteriaKey, $availabilityAsOf] = $this->resolveDayFilters($day, $request->query('variant'));
        $hideZero = $request->boolean('hide_zero');
        $format = $request->query('format', 'csv');
        $tableFilters = $request->only(['search', 'source_id', 'order_id', 'trx_type', 'status', 'sort', 'direction']);

        if (!in_array($format, ['csv', 'excel'], true)) {
            abort(422, 'Invalid export format.');
        }

        // A single day is bounded; pull every matching row in one page.
        $result = $this->vieFundRemoteService->fetchCustomerCashTransactionsByDateColumn(
            $day,
            $basis,
            array_merge(['status_ids' => $statusIds, 'availability_as_of' => $availabilityAsOf], $tableFilters),
            1000000,
            1,
            $hideZero
        );
        $rows = $result['items']->getCollection();

        $sheetRows = $this->buildViefundTransactionSheetRows($rows);

        $basisCode = self::DATE_BASIS_FILE_CODES[$basis] ?? 'se';
        $extension = $format === 'excel' ? 'xlsx' : 'csv';
        $filename = 'viefund_daily_transactions_' . $day->toDateString() . '_' . $basisCode . '.' . $extension;

        if ($format === 'excel') {
            return Excel::download(
                new VieFundReportSheetExport($sheetRows, 'Daily Transactions'),
                $filename,
                ExcelWriter::XLSX
            );
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Source',
                'Txn ID',
                'Cash Trx ID',
                'Source ID',
                'Order ID',
                'Customer',
                'Txn Type',
                'Order Status',
                'Notes',
                'Amount',
                'Created Date',
                'Trade Date',
                'Processing Date',
                'Settlement Date',
            ]);

            foreach ($rows as $txn) {
                fputcsv($out, [
                    ucfirst((string) data_get($txn, 'row_source', 'fund')),
                    data_get($txn, 'trx_id'),
                    data_get($txn, 'cash_trx_id'),
                    data_get($txn, 'source_id'),
                    data_get($txn, 'order_id'),
                    data_get($txn, 'client_name'),
                    data_get($txn, 'trx_type'),
                    data_get($txn, 'status', data_get($txn, 'order_status')),
                    data_get($txn, 'notes'),
                    (float) data_get($txn, 'amount', 0),
                    data_get($txn, 'created_date'),
                    data_get($txn, 'trade_date'),
                    data_get($txn, 'processing_date'),
                    data_get($txn, 'settlement_date'),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param iterable<\stdClass> $rows
     * @return array<int, array<int, string|int|float|null>>
     */
    private function buildViefundTransactionSheetRows(iterable $rows): array
    {
        $sheetRows = [[
            'Source',
            'Txn ID',
            'Cash Trx ID',
            'Source ID',
            'Order ID',
            'Customer',
            'Txn Type',
            'Order Status',
            'Notes',
            'Amount',
            'Created Date',
            'Trade Date',
            'Processing Date',
            'Settlement Date',
        ]];

        foreach ($rows as $txn) {
            $sheetRows[] = [
                ucfirst((string) data_get($txn, 'row_source', 'fund')),
                data_get($txn, 'trx_id'),
                data_get($txn, 'cash_trx_id'),
                data_get($txn, 'source_id'),
                data_get($txn, 'order_id'),
                data_get($txn, 'client_name'),
                data_get($txn, 'trx_type'),
                data_get($txn, 'status', data_get($txn, 'order_status')),
                data_get($txn, 'notes'),
                (float) data_get($txn, 'amount', 0),
                data_get($txn, 'created_date'),
                data_get($txn, 'trade_date'),
                data_get($txn, 'processing_date'),
                data_get($txn, 'settlement_date'),
            ];
        }

        return $sheetRows;
    }

    public function varianceDay(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $onlyFundservBank = $request->has('only_fundserv_bank')
            ? $request->boolean('only_fundserv_bank')
            : false;

        $bankTransactions = BankStatementEntry::query()
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereDate('bank_statement_entries.value_date', '=', $day->toDateString())
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->select([
                'bank_statement_entries.id',
                'bank_statement_entries.credit_debit_indicator',
                'bank_statement_entries.amount',
                'bank_statement_entries.additional_info',
                'a.memo_type',
                'a.counterparty',
                'a.settlement_number',
                'a.wire_payment_reference',
            ])
            ->orderBy('bank_statement_entries.id')
            ->paginate(60, ['*'], 'bank_page')
            ->withQueryString();

        $bankSummary = DB::table('bank_statement_entries')
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereDate('value_date', '=', $day->toDateString())
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->selectRaw('COUNT(*) as transaction_count, SUM(CASE WHEN credit_debit_indicator = "DBIT" THEN -amount ELSE amount END) as net_total')
            ->first();

        $viefundPage = max(1, (int) $request->query('viefund_page', 1));
        [$statusIds, $basis, $criteriaKey, $availabilityAsOf] = $this->resolveDayFilters($day, $request->query('variant'));
        $fundCriteria = $this->describeStatuses($statusIds);

        $viefundResult = $this->vieFundRemoteService->fetchCustomerCashTransactionsByDateColumn(
            $day,
            $basis,
            ['status_ids' => $statusIds, 'availability_as_of' => $availabilityAsOf],
            250,
            $viefundPage
        );
        $snapshot = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)
            ->whereDate('total_date', $day->toDateString())
            ->first();

        $viefundSummary = (object) [
            'transaction_count' => $snapshot?->transaction_count ?? $viefundResult['transaction_count'],
            'net_total' => $snapshot?->net_total ?? $viefundResult['net_total'],
        ];

        return view('reconciliations/daily-variance-comparison', [
            'date'                => $day->toDateString(),
            'bankTransactions'    => $bankTransactions,
            'bankSummary'         => $bankSummary,
            'viefundTransactions' => $viefundResult['items'],
            'viefundSummary'      => $viefundSummary,
            'onlyFundservBank'    => $onlyFundservBank,
            'fundCriteria'        => $fundCriteria,
            'basisLabel'          => self::DATE_BASIS_LABELS[$basis] ?? $basis,
            'criteriaKey'         => $criteriaKey,
        ]);
    }

    private function parseDateOrFail(string $date): Carbon
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            abort(404);
        }

        if ($day->toDateString() !== $date) {
            abort(404);
        }

        return $day;
    }
}
