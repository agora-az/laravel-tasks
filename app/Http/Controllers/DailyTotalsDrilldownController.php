<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use App\Models\VieFundDailyTotal;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
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

    private const TRUST_STATUS_OPTIONS = ['Deleted', 'Unsettled', 'Settled'];

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

    /** Configured fallback trust status names (VIEFUND_DEFAULT_TRUST_STATUS). */
    private function defaultTrustStatusNames(): array
    {
        return (array) config('viefund.default_trust_status', ['Settled']);
    }

    private function defaultBasis(): string
    {
        return (string) config('viefund.default_date_basis', 'settlement_date');
    }

    /**
     * Resolve the variant (date basis + fund statuses + trust names) to
     * reproduce, so the live drilldown matches the daily-totals row. Prefers the
     * snapshot row identified by the URL's variant_key; falls back to any row for
     * the date; then to config defaults.
     *
     * @return array{0: int[], 1: string[], 2: string}
     */
    private function resolveDayFilters(Carbon $day, ?string $variantKey): array
    {
        $row = $variantKey
            ? VieFundDailyTotal::where('variant_key', $variantKey)->first()
            : VieFundDailyTotal::whereDate('total_date', $day->toDateString())->first();

        if (!$row) {
            return [$this->defaultStatusIds(), $this->defaultTrustStatusNames(), $this->defaultBasis()];
        }

        $statusIds = array_values(array_filter(
            array_map('intval', (array) $row->status_ids),
            fn($id) => array_key_exists($id, self::FUND_STATUS_LABELS)
        ));
        if (empty($statusIds)) {
            $statusIds = $this->defaultStatusIds();
        }

        $trustStatusNames = array_values(array_intersect(self::TRUST_STATUS_OPTIONS, (array) $row->trust_status_names));

        $basis = array_key_exists($row->date_basis, self::DATE_BASIS_LABELS)
            ? $row->date_basis
            : $this->defaultBasis();

        return [$statusIds, $trustStatusNames, $basis];
    }

    /**
     * Human-readable criteria strings for the drilldown "Criteria" card.
     *
     * @return array{0: string, 1: string}
     */
    private function describeFilters(array $statusIds, array $trustStatusNames): array
    {
        $fundLabels = array_map(fn($id) => self::FUND_STATUS_LABELS[$id] ?? $id, $statusIds);

        return [
            $fundLabels ? implode(', ', $fundLabels) : 'none',
            $trustStatusNames ? implode(', ', $trustStatusNames) : 'excluded',
        ];
    }

    public function bankDay(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $onlyFundservBank = $request->has('only_fundserv_bank')
            ? $request->boolean('only_fundserv_bank')
            : false;

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
            ->paginate(100, ['*'], 'bank_page')
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

    public function viefundDay(Request $request, string $date): View
    {
        $day = $this->parseDateOrFail($date);
        $page = max(1, (int) $request->query('viefund_page', 1));

        [$statusIds, $trustStatusNames, $basis] = $this->resolveDayFilters($day, $request->query('variant'));
        [$fundCriteria, $trustCriteria] = $this->describeFilters($statusIds, $trustStatusNames);
        $hideZero = $request->boolean('hide_zero');

        $result = $this->vieFundRemoteService->fetchDailyTransactions($day, $statusIds, $trustStatusNames, 250, $page, $basis, $hideZero);

        $summary = (object) [
            'transaction_count' => $result['transaction_count'],
            'net_total' => $result['net_total'],
        ];

        return view('reconciliations/daily-viefund-transactions', [
            'date' => $day->toDateString(),
            'transactions' => $result['items'],
            'summary' => $summary,
            'fundCriteria' => $fundCriteria,
            'trustCriteria' => $trustCriteria,
            'basisLabel' => self::DATE_BASIS_LABELS[$basis] ?? $basis,
            'includesTrust' => !empty($trustStatusNames),
            'hideZero' => $hideZero,
        ]);
    }

    /**
     * Stream the full merged fund + trust listing for a day as CSV, using the
     * same stored snapshot filters as the drilldown view (all rows, not just
     * the current page).
     */
    public function viefundDayExport(Request $request, string $date): StreamedResponse
    {
        $day = $this->parseDateOrFail($date);
        [$statusIds, $trustStatusNames, $basis] = $this->resolveDayFilters($day, $request->query('variant'));
        $hideZero = $request->boolean('hide_zero');

        // A single day is bounded; pull every matching row in one page.
        $result = $this->vieFundRemoteService->fetchDailyTransactions($day, $statusIds, $trustStatusNames, PHP_INT_MAX, 1, $basis, $hideZero);
        $rows = $result['items']->getCollection();

        $basisCode = self::DATE_BASIS_FILE_CODES[$basis] ?? 'se';
        $filename = 'viefund_daily_transactions_' . $day->toDateString() . '_' . $basisCode . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Source', 'Txn ID', 'Cash Trx ID', 'Source ID', 'Customer', 'Txn Type', 'Order Status',
                'Notes', 'Amount', 'Created Date', 'Trade Date', 'Processing Date', 'Settlement Date',
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
        [$statusIds, $trustStatusNames, $basis] = $this->resolveDayFilters($day, $request->query('variant'));
        [$fundCriteria, $trustCriteria] = $this->describeFilters($statusIds, $trustStatusNames);

        $viefundResult = $this->vieFundRemoteService->fetchDailyTransactions($day, $statusIds, $trustStatusNames, 250, $viefundPage, $basis);

        $viefundSummary = (object) [
            'transaction_count' => $viefundResult['transaction_count'],
            'net_total' => $viefundResult['net_total'],
        ];

        return view('reconciliations/daily-variance-comparison', [
            'date'                => $day->toDateString(),
            'bankTransactions'    => $bankTransactions,
            'bankSummary'         => $bankSummary,
            'viefundTransactions' => $viefundResult['items'],
            'viefundSummary'      => $viefundSummary,
            'onlyFundservBank'    => $onlyFundservBank,
            'fundCriteria'        => $fundCriteria,
            'trustCriteria'       => $trustCriteria,
            'basisLabel'          => self::DATE_BASIS_LABELS[$basis] ?? $basis,
            'includesTrust'       => !empty($trustStatusNames),
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
