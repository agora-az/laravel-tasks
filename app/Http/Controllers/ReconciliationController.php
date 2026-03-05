<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reconciliation;
use App\Models\VieFundTransaction;
use App\Models\MatchingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Reconciliation\VieFundFundservMatcher;
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
        Log::info('Matches page loaded');
        $sort = $request->query('sort', 'matched_at');
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortable = ['viefund', 'fundserv', 'bank', 'difference', 'confidence', 'matched_at'];

        if (!in_array($sort, $sortable, true)) {
            $sort = 'matched_at';
        }

        // Get reconciliation filter from query parameters
        // Default: hide_reconciled (hide reconciled matches)
        // Options: show_all, hide_reconciled, show_reconciled
        $reconciliationFilter = $request->query('reconciliation_filter', 'hide_reconciled');
        if (!in_array($reconciliationFilter, ['show_all', 'hide_reconciled', 'show_reconciled'])) {
            $reconciliationFilter = 'hide_reconciled';
        }

        // Get criterion filters from query parameters
        // Format: criteria_order_id=matched, criteria_settlement_date=unmatched, etc.
        $criteriaFilters = [];
        $availableCriteria = ['order_id', 'settlement_date', 'transaction_type', 'amount', 'fund_code', 'source_id'];
        foreach ($availableCriteria as $criterion) {
            $filterValue = $request->query("criteria_{$criterion}", 'off');
            if (in_array($filterValue, ['matched', 'unmatched'])) {
                $criteriaFilters[$criterion] = $filterValue;
            }
        }
        $totalMatches = DB::table('reconciliation_matches')->count();
        $fundservTotal = DB::table('fundserv_transactions')->count();
        $viefundTotal = DB::table('viefund_transactions')->count();
        $bankTotal = DB::table('bank_transactions')->count();

        $fundservMatched = DB::table('reconciliation_matches')
            ->where('right_type', 'fundserv')
            ->distinct('right_id')
            ->count('right_id');

        $viefundMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'viefund')
            ->distinct('left_id')
            ->count('left_id');

        $bankMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'bank')
            ->distinct('left_id')
            ->count('left_id');

        $matches = DB::table('reconciliation_matches as rm')
            ->leftJoin('viefund_transactions as v_left', function ($join) {
                $join->on('rm.left_id', '=', 'v_left.id')
                    ->where('rm.left_type', '=', 'viefund');
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
                'rm.match_criteria_met',
                'rm.reconcile_type',
                'rm.reconcile_date',
                'rm.reconcile_notes',
                'rm.created_at',
                'v_left.fund_wo_number as viefund_fund_wo_number',
                'v_left.fund_trx_amount as viefund_amount',
                'v_left.settlement_date as viefund_settlement_date',
                'f_right.order_id as fundserv_order_id',
                'f_right.settlement_amt as fundserv_amount',
                'f_right.actual_amount as fundserv_actual_amount',
                'f_right.settlement_date as fundserv_settlement_date',
            ])
            ->orderBy('rm.id')
            ->get();

        $matches = $matches->map(function ($row) {
            $row->metadata_array = null;
            if (!empty($row->metadata)) {
                $decoded = json_decode($row->metadata, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row->metadata_array = $decoded;
                }
            }

            // Also decode match_criteria_met if present
            $row->criteria_array = null;
            if (!empty($row->match_criteria_met)) {
                $decoded = json_decode($row->match_criteria_met, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row->criteria_array = $decoded;
                }
            }
            return $row;
        });

        $groupedMatches = $matches
            ->groupBy(function ($match) {
                // Group by fundserv transaction ID
                return "fundserv|{$match->right_id}";
            })
            ->map(function ($group, $key) {
                // Sort group by ID ascending so first item is the parent (lowest ID)
                $group = $group->sortBy('id')->values();
                $first = $group->first();
                $viefundTotal = $group->sum(function ($item) {
                    return (float) ($item->viefund_amount ?? 0);
                });
                $fundservAmount = $first->fundserv_actual_amount ?? $first->fundserv_amount;
                $diff = null;

                if ($fundservAmount !== null && $viefundTotal !== null) {
                    $diff = abs((float) $fundservAmount) - abs((float) $viefundTotal);
                }

                // Calculate aggregated confidence (average of all items)
                $aggregatedConfidence = $group->avg(function ($item) {
                    return (float) ($item->confidence ?? 0);
                });

                // Calculate aggregated criteria (met only if ALL items have it met)
                $aggregatedCriteria = null;
                if (!empty($first->criteria_array)) {
                    // Get all unique criteria rules from all items
                    $allRulesMap = [];
                    foreach ($group as $item) {
                        if (!empty($item->criteria_array)) {
                            foreach ($item->criteria_array as $criterion) {
                                $rule = $criterion['rule'];
                                if (!isset($allRulesMap[$rule])) {
                                    $allRulesMap[$rule] = [];
                                }
                                $allRulesMap[$rule][] = $criterion['matched'];
                            }
                        }
                    }

                    // Build aggregated criteria array: a criterion is met only if ALL items have it met
                    $aggregatedCriteria = [];
                    foreach ($allRulesMap as $rule => $matchedValues) {
                        $allMatched = count($matchedValues) === $group->count() && !in_array(false, $matchedValues, true);
                        $matchedCount = array_sum($matchedValues); // Count how many transactions matched
                        $aggregatedCriteria[] = [
                            'rule' => $rule,
                            'matched' => $allMatched,
                            'matched_count' => $matchedCount,
                            'total_count' => count($matchedValues),
                        ];
                    }
                }

                return [
                    'key' => $key,
                    'items' => $group->values(),
                    'count' => $group->count(),
                    'fundserv_amount' => $fundservAmount,
                    'viefund_total' => $viefundTotal,
                    'bank_amount' => null,
                    'diff' => $diff,
                    'is_multi' => $group->count() > 1,
                    'first' => $first,
                    'aggregated_confidence' => $aggregatedConfidence,
                    'aggregated_criteria' => $aggregatedCriteria,
                ];
            })
            ->sortBy(function ($group) use ($sort) {
                if ($sort === 'confidence') {
                    // Use aggregated confidence for multi-match groups, individual confidence for single matches
                    return $group['is_multi'] ? $group['aggregated_confidence'] : ($group['first']->confidence ?? 0);
                }
                if ($sort === 'viefund') {
                    return $group['viefund_total'] ?? 0;
                }
                if ($sort === 'fundserv') {
                    return $group['fundserv_amount'] ?? 0;
                }
                if ($sort === 'difference') {
                    return $group['diff'] ?? 0;
                }

                return $group['first']->created_at ?? null;
            }, SORT_REGULAR, $direction === 'desc')
            ->values();

        // Filter grouped matches by selected criteria (AND logic)
        if (!empty($criteriaFilters)) {
            $groupedMatches = $groupedMatches->filter(function ($group) use ($criteriaFilters) {
                // Use aggregated criteria for multi-match groups, individual criteria for single matches
                $criteria = $group['is_multi'] ? $group['aggregated_criteria'] : ($group['first']->criteria_array ?? []);

                // Check ALL filters - all must pass (AND logic)
                foreach ($criteriaFilters as $filterCriterion => $filterValue) {
                    $criterionMatched = false;
                    foreach ($criteria as $criterion) {
                        if ($criterion['rule'] === $filterCriterion) {
                            $criterionMatched = $criterion['matched'] === true;
                            break;
                        }
                    }

                    // Apply the filter logic
                    if ($filterValue === 'matched' && !$criterionMatched) {
                        // Filter wants matched, but it's not matched
                        return false;
                    }
                    if ($filterValue === 'unmatched' && $criterionMatched) {
                        // Filter wants unmatched, but it is matched
                        return false;
                    }
                }
                return true;
            })->values();
        }

        // Filter grouped matches by reconciliation status
        if ($reconciliationFilter === 'hide_reconciled') {
            $groupedMatches = $groupedMatches->filter(function ($group) {
                // Hide matches that have been reconciled (where reconcile_type is not null)
                return empty($group['first']->reconcile_type);
            })->values();
        } elseif ($reconciliationFilter === 'show_reconciled') {
            $groupedMatches = $groupedMatches->filter(function ($group) {
                // Show only matches that have been reconciled (where reconcile_type is not null)
                return !empty($group['first']->reconcile_type);
            })->values();
        }
        // 'show_all' doesn't need filtering

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
            'direction',
            'criteriaFilters',
            'availableCriteria',
            'reconciliationFilter'
        ));
    }

    /**
     * Run reconciliation matching rules.
     */
    public function runMatches()
    {
        Log::info('runMatches() called - creating matching session');

        try {
            // Check if there's already a processing session
            $activeSession = MatchingSession::where('status', 'processing')->first();
            if ($activeSession) {
                return redirect()->route('reconciliations.matches')
                    ->with('info', 'A matching session is already in progress.');
            }

            // Create a new matching session
            $session = MatchingSession::create([
                'status' => 'processing',
                'total_records' => VieFundTransaction::count(),
                'processed_records' => 0,
                'matched_count' => 0,
                'started_at' => now(),
            ]);

            Log::info('Created matching session ID: ' . $session->id);

            // Start the matching process asynchronously
            // Using proper shell redirection to detach from the web process
            $artisanPath = base_path('artisan');
            $logPath = storage_path('logs/matching-' . $session->id . '.log');

            // Use PHP path from environment or fallback to standard Unix production path
            $phpPath = env('PHP_PATH', '/usr/local/bin/php');

            // Redirect all output and detach with &
            // This works better than nohup in web contexts
            $command = sprintf(
                '%s %s reconcile:match-viefund-async --session=%d > %s 2>&1 &',
                escapeshellarg($phpPath),
                escapeshellarg($artisanPath),
                $session->id,
                escapeshellarg($logPath)
            );

            Log::info('Running command: ' . $command);

            // Close file descriptors to fully detach
            $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin
                1 => array("pipe", "w"),  // stdout
                2 => array("pipe", "w")   // stderr
            );

            $process = proc_open($command, $descriptorspec, $pipes, null, null, array('bypass_shell' => false));

            if (is_resource($process)) {
                // Close all pipes
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                Log::info('Process spawned successfully for session: ' . $session->id);
            } else {
                Log::error('Failed to spawn background process');
            }

            Log::info('Dispatched matching command for session: ' . $session->id);

            return redirect()->route('reconciliations.matches')
                ->with('info', 'Matching process started. Progress will be shown below.');
        } catch (\Throwable $e) {
            Log::error('ERROR in runMatches: ' . $e->getMessage());
            Log::error('File: ' . $e->getFile() . ':' . $e->getLine());

            return redirect()->route('reconciliations.matches')
                ->with('error', 'Error starting match process: ' . $e->getMessage());
        }
    }

    /**
     * Get the current active matching session via AJAX.
     */
    public function getActiveMatchingSession()
    {
        $session = MatchingSession::where('status', 'processing')
            ->orWhere('status', 'pending')
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['error' => 'No active session'], 404);
        }

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'total_records' => $session->total_records,
            'processed_records' => $session->processed_records,
            'matched_count' => $session->matched_count,
            'progress_percentage' => $session->progress_percentage,
            'error_message' => $session->error_message,
        ]);
    }

    /**
     * Get the status of a matching session via AJAX.
     */
    public function getMatchingStatus($sessionId)
    {
        $session = MatchingSession::find($sessionId);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'total_records' => $session->total_records,
            'processed_records' => $session->processed_records,
            'matched_count' => $session->matched_count,
            'progress_percentage' => $session->progress_percentage,
            'current_pass_number' => $session->current_pass_number,
            'current_pass' => $session->current_pass,
            'error_message' => $session->error_message,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * Reset a matching session by stopping its process and marking it as failed.
     */
    public function resetMatchingSession($sessionId)
    {
        $session = MatchingSession::find($sessionId);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        if (!in_array($session->status, ['processing', 'pending'])) {
            return response()->json(['error' => 'Can only reset sessions that are processing or pending'], 400);
        }

        try {
            // Kill any background PHP processes related to this session
            // Look for processes running reconcile:match-viefund-async with this session ID
            $sessionIdArg = escapeshellarg('--session=' . $session->id);
            
            // Use pkill to find and kill processes by pattern
            $killCommand = sprintf(
                "pkill -f %s 2>/dev/null || true",
                escapeshellarg("reconcile:match-viefund-async.*session=" . $session->id)
            );
            
            Log::info('Killing process for session ' . $session->id . ': ' . $killCommand);
            exec($killCommand);

            // Update the session as failed with user interruption message
            $session->update([
                'status' => 'failed',
                'error_message' => 'Interrupted by user',
                'completed_at' => now(),
            ]);

            Log::info('Session ' . $session->id . ' reset by user');

            return response()->json([
                'success' => true,
                'message' => 'Matching session has been reset successfully',
                'session' => [
                    'id' => $session->id,
                    'status' => $session->status,
                    'error_message' => $session->error_message,
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Error resetting matching session: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to reset session: ' . $e->getMessage()
            ], 500);
        }
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
            ->where('right_type', 'fundserv')
            ->distinct('right_id')
            ->count('right_id');

        $viefundMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'viefund')
            ->distinct('left_id')
            ->count('left_id');

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
                $item->display_label = VieFundFundservMatcher::getRuleLabel($item->match_rule);
                return $item;
            });

        // Get recent matches
        $recentMatches = DB::table('reconciliation_matches')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $item->display_label = VieFundFundservMatcher::getRuleLabel($item->match_rule);
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

    /**
     * Get detailed transaction data for a match (API endpoint)
     */
    public function getMatchDetails($matchId)
    {
        $match = DB::table('reconciliation_matches as rm')
            ->leftJoin('viefund_transactions as v', function ($join) {
                $join->on('rm.left_id', '=', 'v.id')
                    ->where('rm.left_type', '=', 'viefund');
            })
            ->leftJoin('fundserv_transactions as f', function ($join) {
                $join->on('rm.right_id', '=', 'f.id')
                    ->where('rm.right_type', '=', 'fundserv');
            })
            ->select('rm.*', 'v.*', 'f.*')
            ->select([
                'rm.id as match_id',
                'rm.match_rule',
                'rm.left_id',
                'rm.right_id',
                'rm.right_type',
                'rm.confidence',
                'rm.match_criteria_met',
                'rm.reconcile_type',
                'rm.reconcile_date',
                'rm.reconcile_notes',

                // VieFund fields
                'v.id as viefund_id',
                'v.client_name',
                'v.rep_code',
                'v.plan_description',
                'v.institution',
                'v.account_id',
                'v.trx_id',
                'v.created_date as viefund_created_date',
                'v.trx_type as viefund_trx_type',
                'v.trade_date as viefund_trade_date',
                'v.settlement_date as viefund_settlement_date',
                'v.processing_date',
                'v.fund_source_id as viefund_source_id',
                'v.status as viefund_status',
                'v.amount',
                'v.balance',
                'v.fund_code',
                'v.fund_trx_type',
                'v.fund_trx_amount',
                'v.fund_settlement_source',
                'v.fund_wo_number',
                'v.currency',

                // Fundserv fields
                'f.id as fundserv_id',
                'f.company',
                'f.settlement_date as fundserv_settlement_date',
                'f.code',
                'f.src',
                'f.trade_date as fundserv_trade_date',
                'f.fund_id',
                'f.dealer_account_id',
                'f.order_id',
                'f.source_identifier',
                'f.tx_type',
                'f.settlement_amt',
                'f.actual_amount',
            ])
            ->where('rm.id', $matchId)
            ->first();

        if (!$match) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        // Decode criteria
        $criteria = [];
        if (!empty($match->match_criteria_met)) {
            $decoded = json_decode($match->match_criteria_met, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $criteria = $decoded;
            }
        }

        // Check if this is a parent (minimum ID) in its group
        $parentId = DB::table('reconciliation_matches')
            ->where('right_id', $match->right_id)
            ->where('right_type', $match->right_type)
            ->min('id');

        // Count how many matches exist for this Fundserv
        $matchCount = DB::table('reconciliation_matches')
            ->where('right_id', $match->right_id)
            ->where('right_type', $match->right_type)
            ->count();

        $isParent = ($match->match_id == $parentId);

        return response()->json([
            'match' => $match,
            'confidence' => $match->confidence,
            'is_parent' => $isParent,
            'match_count' => $matchCount,
            'criteria' => $criteria,
            'viefund' => (object) [
                'id' => $match->viefund_id,
                'client_name' => $match->client_name,
                'rep_code' => $match->rep_code,
                'plan_description' => $match->plan_description,
                'institution' => $match->institution,
                'account_id' => $match->account_id,
                'trx_id' => $match->trx_id,
                'created_date' => $match->viefund_created_date,
                'trx_type' => $match->viefund_trx_type,
                'trade_date' => $match->viefund_trade_date,
                'settlement_date' => $match->viefund_settlement_date,
                'processing_date' => $match->processing_date,
                'fund_source_id' => $match->viefund_source_id,
                'status' => $match->viefund_status,
                'amount' => $match->amount,
                'balance' => $match->balance,
                'fund_code' => $match->fund_code,
                'fund_trx_type' => $match->fund_trx_type,
                'fund_trx_amount' => $match->fund_trx_amount,
                'fund_settlement_source' => $match->fund_settlement_source,
                'fund_wo_number' => $match->fund_wo_number,
                'currency' => $match->currency,
            ],
            'fundserv' => (object) [
                'id' => $match->fundserv_id,
                'company' => $match->company,
                'settlement_date' => $match->fundserv_settlement_date,
                'code' => $match->code,
                'src' => $match->src,
                'trade_date' => $match->fundserv_trade_date,
                'fund_id' => $match->fund_id,
                'dealer_account_id' => $match->dealer_account_id,
                'order_id' => $match->order_id,
                'source_identifier' => $match->source_identifier,
                'tx_type' => $match->tx_type,
                'settlement_amt' => $match->settlement_amt,
                'actual_amount' => $match->actual_amount,
            ]
        ]);
    }

    /**
     * Reconcile a match
     */
    public function reconcileMatch($matchId)
    {
        $match = DB::table('reconciliation_matches')->where('id', $matchId)->first();

        if (!$match) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        try {
            $reconcileType = request()->input('reconcile_type', 'manual');
            $reconcileNotes = request()->input('reconcile_notes');

            // Update the specific match
            DB::table('reconciliation_matches')
                ->where('id', $matchId)
                ->update([
                    'reconcile_type' => $reconcileType,
                    'reconcile_date' => now(),
                    'reconcile_notes' => $reconcileNotes,
                ]);

            // If this is a multi-match group, also update the parent (lowest ID)
            $parentMatch = DB::table('reconciliation_matches')
                ->where('right_id', $match->right_id)
                ->where('right_type', $match->right_type)
                ->orderBy('id', 'asc')
                ->first();

            if ($parentMatch && $parentMatch->id !== $matchId) {
                // This is a child, update the parent
                DB::table('reconciliation_matches')
                    ->where('id', $parentMatch->id)
                    ->update([
                        'reconcile_type' => $reconcileType,
                        'reconcile_date' => now(),
                        'reconcile_notes' => $reconcileNotes,
                    ]);
            }

            return response()->json(['success' => true, 'message' => 'Match reconciled successfully']);
        } catch (\Exception $e) {
            Log::error('Error reconciling match: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Reconcile all matches in a group (same right_id)
     */
    public function reconcileMatchGroup($matchId)
    {
        $match = DB::table('reconciliation_matches')->where('id', $matchId)->first();

        if (!$match) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        try {
            $reconcileType = request()->input('reconcile_type', 'manual');
            $reconcileNotes = request()->input('reconcile_notes');

            // Reconcile all matches with the same right_id and right_type
            DB::table('reconciliation_matches')
                ->where('right_id', $match->right_id)
                ->where('right_type', $match->right_type)
                ->update([
                    'reconcile_type' => $reconcileType,
                    'reconcile_date' => now(),
                    'reconcile_notes' => $reconcileNotes,
                ]);

            return response()->json(['success' => true, 'message' => 'Group reconciled successfully']);
        } catch (\Exception $e) {
            Log::error('Error reconciling match group: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear reconciliation for a match
     */
    public function clearReconciliation($matchId)
    {
        $match = DB::table('reconciliation_matches')->where('id', $matchId)->first();

        if (!$match) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        try {
            // Clear the specific match
            DB::table('reconciliation_matches')
                ->where('id', $matchId)
                ->update([
                    'reconcile_type' => null,
                    'reconcile_date' => null,
                    'reconcile_notes' => null,
                ]);

            // If this is a multi-match group, also clear the parent (lowest ID)
            $parentMatch = DB::table('reconciliation_matches')
                ->where('right_id', $match->right_id)
                ->where('right_type', $match->right_type)
                ->orderBy('id', 'asc')
                ->first();

            if ($parentMatch && $parentMatch->id !== $matchId) {
                // This is a child, clear the parent too
                DB::table('reconciliation_matches')
                    ->where('id', $parentMatch->id)
                    ->update([
                        'reconcile_type' => null,
                        'reconcile_date' => null,
                        'reconcile_notes' => null,
                    ]);
            }

            return response()->json(['success' => true, 'message' => 'Reconciliation cleared successfully']);
        } catch (\Exception $e) {
            Log::error('Error clearing reconciliation: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
