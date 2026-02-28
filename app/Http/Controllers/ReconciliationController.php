<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reconciliation;
use Illuminate\Support\Facades\DB;
use App\Services\Reconciliation\TransactionMatcher;
use Illuminate\Pagination\LengthAwarePaginator;

class ReconciliationController extends Controller
{
    /**
     * Display a listing of reconciliations.
     */
    public function index()
    {
        $reconciliations = Reconciliation::latest()->paginate(15);
        return view('reconciliations.index', compact('reconciliations'));
    }

    /**
     * Show the form for creating a new reconciliation.
     */
    public function create()
    {
        return view('reconciliations.create');
    }

    /**
     * Store a newly created reconciliation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'description' => 'nullable|string',
        ]);

        $reconciliation = Reconciliation::create($validated);

        return redirect()->route('reconciliations.show', $reconciliation->id)
            ->with('success', 'Reconciliation report created successfully.');
    }

    /**
     * Display the specified reconciliation.
     */
    public function show($id)
    {
        $reconciliation = Reconciliation::findOrFail($id);
        return view('reconciliations.show', compact('reconciliation'));
    }

    /**
     * Export the reconciliation report.
     */
    public function export($id)
    {
        $reconciliation = Reconciliation::findOrFail($id);

        // Generate report content
        $content = "Reconciliation Report\n";
        $content .= "Title: {$reconciliation->title}\n";
        $content .= "Period: {$reconciliation->period_start} to {$reconciliation->period_end}\n";
        $content .= "\nDescription:\n{$reconciliation->description}\n";

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="reconciliation_' . $id . '.txt"');
    }

    /**
     * Temporary view for reconciliation matches.
     */
    public function matches(Request $request)
    {
        $sort = $request->query('sort', 'matched_at');
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortable = ['viefund', 'fundserv', 'bank', 'difference', 'confidence', 'matched_at'];

        if (!in_array($sort, $sortable, true)) {
            $sort = 'matched_at';
        }
        $totalMatches = DB::table('reconciliation_matches')->count();
        $fundservTotal = DB::table('fundserv_transactions')->count();
        $viefundTotal = DB::table('viefund_transactions')->count();
        $bankTotal = DB::table('bank_transactions')->count();

        $fundservMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'fundserv')
            ->distinct('left_id')
            ->count('left_id');

        $viefundMatched = DB::table('reconciliation_matches')
            ->where('right_type', 'viefund')
            ->distinct('right_id')
            ->count('right_id');

        $bankMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'bank')
            ->distinct('left_id')
            ->count('left_id');

        $matches = DB::table('reconciliation_matches as rm')
            ->leftJoin('fundserv_transactions as f_left', function ($join) {
                $join->on('rm.left_id', '=', 'f_left.id')
                    ->where('rm.left_type', '=', 'fundserv');
            })
            ->leftJoin('viefund_transactions as v_right', function ($join) {
                $join->on('rm.right_id', '=', 'v_right.id')
                    ->where('rm.right_type', '=', 'viefund');
            })
            ->leftJoin('bank_transactions as b_left', function ($join) {
                $join->on('rm.left_id', '=', 'b_left.id')
                    ->where('rm.left_type', '=', 'bank');
            })
            ->leftJoin('fundserv_transactions as f_right', function ($join) {
                $join->on('rm.right_id', '=', 'f_right.id')
                    ->where('rm.right_type', '=', 'fundserv');
            })
            ->select([
                'rm.id',
                'rm.match_rule',
                'rm.left_type',
                'rm.left_id',
                'rm.right_type',
                'rm.right_id',
                'rm.matched_amount',
                'rm.confidence',
                'rm.status',
                'rm.metadata',
                'rm.created_at',
                'f_left.order_id as fundserv_order_id',
                'f_left.settlement_amt as fundserv_amount',
                'f_left.actual_amount as fundserv_actual_amount',
                'v_right.fund_wo_number as viefund_fund_wo_number',
                'v_right.fund_trx_amount as viefund_amount',
                'v_right.settlement_date as viefund_settlement_date',
                'v_right.trade_date as viefund_trade_date',
                'v_right.processing_date as viefund_processing_date',
                'b_left.txn_date as bank_txn_date',
                'b_left.amount as bank_amount',
                'b_left.description as bank_description',
                'f_right.settlement_date as fundserv_right_date',
                'f_right.settlement_amt as fundserv_right_amount',
                'f_right.actual_amount as fundserv_right_actual_amount',
            ])
            ->orderByDesc('rm.created_at')
            ->get();

        $matches = $matches->map(function ($row) {
            $row->metadata_array = null;
            if (!empty($row->metadata)) {
                $decoded = json_decode($row->metadata, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row->metadata_array = $decoded;
                }
            }
            return $row;
        });

        $viefundIds = $matches
            ->filter(function ($row) {
                return $row->match_rule === TransactionMatcher::RULE_BANK_TO_FUNDSERV
                    && !empty($row->metadata_array['viefund_id']);
            })
            ->map(function ($row) {
                return (int) $row->metadata_array['viefund_id'];
            })
            ->unique()
            ->values();

        $viefundMap = collect();
        if ($viefundIds->isNotEmpty()) {
            $viefundMap = DB::table('viefund_transactions')
                ->whereIn('id', $viefundIds)
                ->select([
                    'id',
                    'fund_trx_amount',
                    'fund_wo_number',
                    'settlement_date',
                    'trade_date',
                    'processing_date',
                ])
                ->get()
                ->keyBy('id');
        }

        if ($viefundMap->isNotEmpty()) {
            $matches = $matches->map(function ($row) use ($viefundMap) {
                if ($row->match_rule === TransactionMatcher::RULE_BANK_TO_FUNDSERV
                    && empty($row->viefund_amount)
                    && !empty($row->metadata_array['viefund_id'])) {
                    $vie = $viefundMap->get((int) $row->metadata_array['viefund_id']);
                    if ($vie) {
                        $row->viefund_amount = $vie->fund_trx_amount;
                        if (empty($row->viefund_fund_wo_number)) {
                            $row->viefund_fund_wo_number = $vie->fund_wo_number;
                        }
                        if (empty($row->viefund_settlement_date)) {
                            $row->viefund_settlement_date = $vie->settlement_date;
                        }
                        if (empty($row->viefund_trade_date)) {
                            $row->viefund_trade_date = $vie->trade_date;
                        }
                        if (empty($row->viefund_processing_date)) {
                            $row->viefund_processing_date = $vie->processing_date;
                        }
                    }
                }
                return $row;
            });
        }

        $groupedMatches = $matches
            ->groupBy(function ($match) {
                if ($match->match_rule === TransactionMatcher::RULE_ORDER_ID_TO_FUND_WO) {
                    $orderKey = $match->fundserv_order_id ?? $match->viefund_fund_wo_number ?? $match->left_id;
                    return "order-id|{$match->left_id}|{$orderKey}";
                }

                if ($match->match_rule === TransactionMatcher::RULE_BANK_TO_FUNDSERV) {
                    $orderKey = $match->fundserv_order_id ?? $match->viefund_fund_wo_number ?? $match->right_id;
                    return "bank-fundserv|{$match->left_id}|{$orderKey}";
                }

                if ($match->match_rule === TransactionMatcher::RULE_BANK_TO_VIEFUND) {
                    $orderKey = $match->viefund_fund_wo_number ?? $match->right_id;
                    return "bank-viefund|{$match->left_id}|{$orderKey}";
                }

                return "single|{$match->id}";
            })
            ->map(function ($group, $key) {
                $first = $group->first();
                $viefundTotal = $group->sum(function ($item) {
                    return (float) ($item->viefund_amount ?? 0);
                });
                $matchedTotal = $group->sum(function ($item) {
                    return (float) ($item->matched_amount ?? 0);
                });
                $fundservAmount = $first->fundserv_actual_amount
                    ?? $first->fundserv_amount
                    ?? $first->fundserv_right_actual_amount
                    ?? $first->fundserv_right_amount
                    ?? null;
                $bankAmount = $first->bank_amount ?? null;
                $diff = null;

                if ($fundservAmount !== null) {
                    $diff = abs((float) $fundservAmount) - abs((float) $viefundTotal);
                } elseif ($bankAmount !== null) {
                    $diff = abs((float) $bankAmount) - abs((float) $viefundTotal);
                }

                return [
                    'key' => $key,
                    'items' => $group->values(),
                    'count' => $group->count(),
                    'fundserv_amount' => $fundservAmount,
                    'bank_amount' => $bankAmount,
                    'viefund_total' => $viefundTotal,
                    'matched_total' => $matchedTotal,
                    'diff' => $diff,
                    'is_multi' => $group->count() > 1,
                    'first' => $first,
                ];
            })
            ->sortBy(function ($group) use ($sort) {
                if ($sort === 'viefund') {
                    return $group['viefund_total'] ?? 0;
                }
                if ($sort === 'fundserv') {
                    return $group['fundserv_amount'] ?? 0;
                }
                if ($sort === 'bank') {
                    return $group['bank_amount'] ?? 0;
                }
                if ($sort === 'difference') {
                    return $group['diff'] ?? 0;
                }
                if ($sort === 'confidence') {
                    return $group['first']->confidence ?? 0;
                }

                return $group['first']->created_at ?? null;
            }, SORT_REGULAR, $direction === 'desc')
            ->values();

        $perPage = 50;
        $page = (int) $request->query('page', 1);
        $pagedGroups = $groupedMatches->forPage($page, $perPage)->values();

        $matchesPaginator = new LengthAwarePaginator(
            $pagedGroups,
            $groupedMatches->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('reconciliations.matches', compact(
            'matchesPaginator',
            'pagedGroups',
            'totalMatches',
            'fundservTotal',
            'viefundTotal',
            'bankTotal',
            'fundservMatched',
            'viefundMatched',
            'bankMatched',
            'sort',
            'direction'
        ));
    }

    /**
     * Run reconciliation matching rules.
     */
    public function runMatches()
    {
        /** @var TransactionMatcher $matcher */
        $matcher = app(TransactionMatcher::class);

        $orderCount = $matcher->matchFundservOrderIdToVieFundWoNumber(false);
        $bankFundservCount = $matcher->matchBankToFundservViaOrderId(false)
            + $matcher->matchBankToFundservAmountDate(false);
        $bankVieFundCount = $matcher->matchBankToVieFundAmountDate(false);

        return redirect()->route('reconciliations.matches')
            ->with('success', "Matches updated. Order-ID: {$orderCount}, Bank-Fundserv: {$bankFundservCount}, Bank-VieFund: {$bankVieFundCount}.");
    }

    /**
     * Delete all reconciliation matches.
     */
    public function deleteMatches()
    {
        DB::table('reconciliation_matches')->truncate();

        return redirect()->route('reconciliations.matches')
            ->with('success', 'All reconciliation matches deleted.');
    }

    /**
     * Display the dashboard with reconciliation statistics.
     */
    public function dashboard()
    {
        // Get totals
        $fundservTotal = DB::table('fundserv_transactions')->count();
        $viefundTotal = DB::table('viefund_transactions')->count();
        $bankTotal = DB::table('bank_transactions')->count();
        $totalTransactions = $fundservTotal + $viefundTotal + $bankTotal;

        // Get matched counts
        $fundservMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'fundserv')
            ->distinct('left_id')
            ->count('left_id');

        $viefundMatched = DB::table('reconciliation_matches')
            ->where('right_type', 'viefund')
            ->distinct('right_id')
            ->count('right_id');

        $bankMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'bank')
            ->distinct('left_id')
            ->count('left_id');

        $totalMatches = DB::table('reconciliation_matches')->count();

        // Get match confidence distribution
        $confidenceData = DB::table('reconciliation_matches')
            ->select(DB::raw('FLOOR(confidence * 10) * 10 as confidence_range'), DB::raw('count(*) as count'))
            ->groupBy('confidence_range')
            ->orderBy('confidence_range')
            ->get();

        // Get matches by rule
        $matchesByRule = DB::table('reconciliation_matches')
            ->select('match_rule', DB::raw('count(*) as count'))
            ->groupBy('match_rule')
            ->get()
            ->map(function ($item) {
                $item->display_label = TransactionMatcher::getRuleLabel($item->match_rule);
                return $item;
            });

        // Get recent matches
        $recentMatches = DB::table('reconciliation_matches')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $item->display_label = TransactionMatcher::getRuleLabel($item->match_rule);
                return $item;
            });

        // Calculate percentages
        $fundservMatchPercentage = $fundservTotal > 0 ? round(($fundservMatched / $fundservTotal) * 100, 1) : 0;
        $viefundMatchPercentage = $viefundTotal > 0 ? round(($viefundMatched / $viefundTotal) * 100, 1) : 0;
        $bankMatchPercentage = $bankTotal > 0 ? round(($bankMatched / $bankTotal) * 100, 1) : 0;

        return view('reconciliations.dashboard', compact(
            'fundservTotal',
            'viefundTotal',
            'bankTotal',
            'totalTransactions',
            'fundservMatched',
            'viefundMatched',
            'bankMatched',
            'totalMatches',
            'fundservMatchPercentage',
            'viefundMatchPercentage',
            'bankMatchPercentage',
            'confidenceData',
            'matchesByRule',
            'recentMatches'
        ));
    }
}
