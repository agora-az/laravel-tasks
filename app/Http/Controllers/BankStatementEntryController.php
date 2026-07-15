<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankStatementEntryController extends Controller
{
    private const PARSER_VERSION = 'v2';

    public function index(Request $request)
    {
        $query = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            )
            ->select([
                'bank_statement_entries.id',
                'bank_statement_entries.source_file',
                'bank_statement_entries.account_number',
                'bank_statement_entries.value_date',
                'bank_statement_entries.credit_debit_indicator',
                'bank_statement_entries.currency',
                'bank_statement_entries.amount',
                'bank_statement_entries.additional_info',
                'a.memo_type',
                'a.settlement_number',
                'a.wire_payment_reference',
                'a.counterparty',
                'a.inferred_channel',
                'a.confidence',
            ]);

        // Filters
        if ($request->filled('date_from')) {
            $query->where('bank_statement_entries.value_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('bank_statement_entries.value_date', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $query->where('a.inferred_channel', $request->channel);
        }
        if ($request->filled('direction')) {
            $query->where('bank_statement_entries.credit_debit_indicator', $request->direction);
        }
        if ($request->filled('memo_type')) {
            $query->where('a.memo_type', 'like', '%' . $request->memo_type . '%');
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('bank_statement_entries.additional_info', 'like', '%' . $term . '%')
                    ->orWhere('a.counterparty', 'like', '%' . $term . '%')
                    ->orWhere('a.settlement_number', 'like', '%' . $term . '%')
                    ->orWhere('a.wire_payment_reference', 'like', '%' . $term . '%');
            });
        }

        // Sorting
        $sortField = in_array($request->sort, ['value_date', 'amount', 'inferred_channel', 'memo_type', 'counterparty'])
            ? $request->sort
            : 'value_date';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        $columnMap = [
            'value_date' => 'bank_statement_entries.value_date',
            'amount' => 'bank_statement_entries.amount',
            'inferred_channel' => 'a.inferred_channel',
            'memo_type' => 'a.memo_type',
            'counterparty' => 'a.counterparty',
        ];
        $query->orderBy($columnMap[$sortField], $sortDir)->orderBy('bank_statement_entries.id');

        $entries = $query->paginate(50)->withQueryString();

        // Summary totals (respecting filters but not pagination)
        $totalsQuery = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            );

        if ($request->filled('date_from')) {
            $totalsQuery->where('bank_statement_entries.value_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $totalsQuery->where('bank_statement_entries.value_date', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $totalsQuery->where('a.inferred_channel', $request->channel);
        }
        if ($request->filled('direction')) {
            $totalsQuery->where('bank_statement_entries.credit_debit_indicator', $request->direction);
        }
        if ($request->filled('memo_type')) {
            $totalsQuery->where('a.memo_type', 'like', '%' . $request->memo_type . '%');
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $totalsQuery->where(function ($q) use ($term) {
                $q->where('bank_statement_entries.additional_info', 'like', '%' . $term . '%')
                    ->orWhere('a.counterparty', 'like', '%' . $term . '%')
                    ->orWhere('a.settlement_number', 'like', '%' . $term . '%')
                    ->orWhere('a.wire_payment_reference', 'like', '%' . $term . '%');
            });
        }

        $totals = $totalsQuery->selectRaw(
            'count(*) as total_count,
             sum(case when bank_statement_entries.credit_debit_indicator = "CRDT" then bank_statement_entries.amount else 0 end) as total_credits,
             sum(case when bank_statement_entries.credit_debit_indicator = "DBIT" then bank_statement_entries.amount else 0 end) as total_debits'
        )->first();

        // Filter options
        $channels = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('inferred_channel')
            ->distinct()
            ->orderBy('inferred_channel')
            ->pluck('inferred_channel');

        $memoTypes = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('memo_type')
            ->selectRaw('memo_type, count(*) as c')
            ->groupBy('memo_type')
            ->orderByDesc('c')
            ->pluck('memo_type');

        return view('bank-entries.index', compact(
            'entries',
            'totals',
            'channels',
            'memoTypes',
        ));
    }
}
