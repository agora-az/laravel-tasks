<?php

namespace App\Http\Controllers;

use App\Exports\VieFundReportSheetExport;
use App\Models\BankStatementEntry;
use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundCashSnapshotRun;
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

    public function __construct(private readonly VieFundRemoteService $vieFundRemoteService) {}

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
            ->whereDate('bank_statement_entries.value_date', '=', $day->toDateString())
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
            ->whereDate('value_date', '=', $day->toDateString())
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
        ]);
    }

    public function bankDayExport(Request $request, string $date): BinaryFileResponse|StreamedResponse
    {
        $day = $this->parseDateOrFail($date);
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
            ->whereDate('bank_statement_entries.value_date', '=', $day->toDateString())
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

        $result = $this->vieFundRemoteService->fetchCustomerCashTransactionsByDateColumn(
            $day,
            $basis,
            ['status_ids' => $statusIds, 'availability_as_of' => $availabilityAsOf],
            $perPage,
            $page,
            $hideZero
        );
        $snapshot = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)
            ->whereDate('total_date', $day->toDateString())
            ->first();

        $summary = (object) [
            'transaction_count' => $snapshot?->transaction_count ?? $result['transaction_count'],
            'net_total' => $snapshot?->net_total ?? $result['net_total'],
        ];

        return view('reconciliations/daily-viefund-transactions', [
            'date' => $day->toDateString(),
            'transactions' => $result['items'],
            'summary' => $summary,
            'liveSummary' => (object) ['transaction_count' => $result['transaction_count'], 'net_total' => $result['net_total']],
            'fundCriteria' => $fundCriteria,
            'basisLabel' => self::DATE_BASIS_LABELS[$basis] ?? $basis,
            'criteriaKey' => $criteriaKey,
            'hideZero' => $hideZero,
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

        if (!in_array($format, ['csv', 'excel'], true)) {
            abort(422, 'Invalid export format.');
        }

        // A single day is bounded; pull every matching row in one page.
        $result = $this->vieFundRemoteService->fetchCustomerCashTransactionsByDateColumn(
            $day,
            $basis,
            ['status_ids' => $statusIds, 'availability_as_of' => $availabilityAsOf],
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
