<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DailyTotalsDrilldownController extends Controller
{
    private const PARSER_VERSION = 'v2';

    public function __construct(private readonly VieFundRemoteService $vieFundRemoteService) {}

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

        $summary = $this->vieFundRemoteService->fetchDailyNetTotals($day, $day)->first();

        $transactions = $this->vieFundRemoteService
            ->fetchDailySettlementFundTransactions($day, 250, $page)
            ->withQueryString();

        return view('reconciliations/daily-viefund-transactions', [
            'date' => $day->toDateString(),
            'transactions' => $transactions,
            'summary' => $summary,
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
        $viefundTransactions = $this->vieFundRemoteService
            ->fetchDailySettlementFundTransactions($day, 250, $viefundPage)
            ->withQueryString();

        $viefundSummary = $this->vieFundRemoteService->fetchDailyNetTotals($day, $day)->first();

        return view('reconciliations/daily-variance-comparison', [
            'date'                => $day->toDateString(),
            'bankTransactions'    => $bankTransactions,
            'bankSummary'         => $bankSummary,
            'viefundTransactions' => $viefundTransactions,
            'viefundSummary'      => $viefundSummary,
            'onlyFundservBank'    => $onlyFundservBank,
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
