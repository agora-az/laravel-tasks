<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reconciliation;
use App\Models\BankStatementEntry;
use App\Models\VieFundTransaction;
use App\Models\MatchingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Reconciliation\VieFundFundservMatcher;
use App\Services\Reconciliation\FeeTransactionMatcher;
use App\Services\VieFund\VieFundRemoteService;
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
        $direction = strtolower($request->query('direction', 'asc')) === 'asc' ? 'asc' : 'desc';
        $sortable = ['viefund', 'fundserv', 'bank', 'difference', 'confidence', 'matched_at'];

        if (!in_array($sort, $sortable, true)) {
            $sort = 'matched_at';
        }

        // Get reconciliation filter from query parameters
        $reconciliationFilter = $request->query('reconciliation_filter', 'hide_reconciled');
        if (!in_array($reconciliationFilter, ['show_all', 'hide_reconciled', 'show_reconciled'])) {
            $reconciliationFilter = 'hide_reconciled';
        }

        // Get criterion filters from query parameters
        $criteriaFilters = [];
        $availableCriteria = ['order_id', 'settlement_date', 'transaction_type', 'amount', 'fund_code', 'source_id'];
        foreach ($availableCriteria as $criterion) {
            $filterValue = $request->query("criteria_{$criterion}", 'off');
            if (in_array($filterValue, ['matched', 'unmatched'])) {
                $criteriaFilters[$criterion] = $filterValue;
            }
        }

        // Get search filters from query parameters
        $searchTable = $request->query('search_table');
        $searchField = $request->query('search_field');
        $searchValue = $request->query('search_value');

        $totalMatches = DB::table('reconciliation_matches')->count();
        $fundservTotal = DB::table('fundserv_transactions')->count();
        $viefundTotal = DB::table('viefund_transactions')->count();
        $bankTotal = DB::table('bank_transactions')->count();
        $accountFeesTotal = DB::table('account_fee_transactions')->count();
        $advisoryFeesTotal = DB::table('advisory_fee_transactions')->count();

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

        $accountFeesMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'account-fee')
            ->distinct('left_id')
            ->count('left_id');

        $advisoryFeesMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'advisory-fee')
            ->distinct('left_id')
            ->count('left_id');

        // Step 1: Determine which transaction IDs match the search criteria
        $searchConstraintIds = null;
        $searchConstraintType = null; // 'left_id', 'right_id', or 'bank_transaction_id'
        
        if ($searchTable && $searchField && $searchValue) {
            if ($searchTable === 'viefund') {
                $searchConstraintIds = VieFundTransaction::where($searchField, 'LIKE', '%' . $searchValue . '%')
                    ->pluck('id')
                    ->toArray();
                $searchConstraintType = 'left_id';
            } elseif ($searchTable === 'fundserv') {
                $searchConstraintIds = DB::table('fundserv_transactions')
                    ->where($searchField, 'LIKE', '%' . $searchValue . '%')
                    ->pluck('id')
                    ->toArray();
                $searchConstraintType = 'right_id';
            } elseif ($searchTable === 'bank') {
                $searchConstraintIds = DB::table('bank_transactions')
                    ->where($searchField, 'LIKE', '%' . $searchValue . '%')
                    ->pluck('id')
                    ->toArray();
                $searchConstraintType = 'bank_transaction_id';
            }
        }

        // Step 2: Get distinct VieFund fund_wo_number groups with pagination
        $perPage = 50;
        $page = (int) $request->query('page', 1);
        
        // Query fund_wo_numbers directly from viefund_transactions when searching VieFund
        // This ensures we get ALL fund_wo_numbers from matching viefund transactions, not just those with matches
        if ($searchConstraintType === 'left_id' && $searchConstraintIds) {
            // Get distinct fund_wo_numbers from the matching viefund transactions
            $fundWoPaginated = DB::table('viefund_transactions')
                ->whereIn('id', $searchConstraintIds)
                ->distinct('fund_wo_number')
                ->select('fund_wo_number')
                ->orderBy('fund_wo_number', $direction)
                ->paginate($perPage, ['*'], 'page', $page);
        } else {
            // For searches on other transaction types, use the original logic
            $fundWoQuery = DB::table('reconciliation_matches as rm')
                ->leftJoin('viefund_transactions as v_left', function ($join) {
                    $join->on('rm.left_id', '=', 'v_left.id')
                        ->where('rm.left_type', '=', 'viefund');
                })
                ->leftJoin('fundserv_transactions as f_right', function ($join) {
                    $join->on('rm.right_id', '=', 'f_right.id')
                        ->where('rm.right_type', '=', 'fundserv');
                });

            // Apply search constraint
            if ($searchConstraintIds && !empty($searchConstraintIds)) {
                if ($searchConstraintType === 'right_id') {
                    $fundWoQuery->whereIn('rm.right_id', $searchConstraintIds);
                } elseif ($searchConstraintType === 'bank_transaction_id') {
                    $fundWoQuery->whereIn('rm.bank_transaction_id', $searchConstraintIds);
                }
            }

            // Apply reconciliation filter at database level
            if ($reconciliationFilter === 'hide_reconciled') {
                $fundWoQuery->whereNull('rm.reconcile_type');
            } elseif ($reconciliationFilter === 'show_reconciled') {
                $fundWoQuery->whereNotNull('rm.reconcile_type');
            }

            // Get distinct fund_wo_number groups for pagination
            $fundWoPaginated = $fundWoQuery
                ->distinct('v_left.fund_wo_number')
                ->select('v_left.fund_wo_number')
                ->orderBy('v_left.fund_wo_number', $direction)
                ->paginate($perPage, ['*'], 'page', $page);
        }

        $fundWoNumbers = $fundWoPaginated->pluck('fund_wo_number')->toArray();

        // Step 3: Load all matches for these fund_wo_number groups
        // IMPORTANT: We use $fundWoNumbers (derived from search constraints) to determine which groups to show,
        // but we GET ALL reconciliation_matches for those groups, not just the ones matching the search criteria
        $matches = DB::table('reconciliation_matches as rm')
            ->leftJoin('viefund_transactions as v_left', function ($join) {
                $join->on('rm.left_id', '=', 'v_left.id')
                    ->where('rm.left_type', '=', 'viefund');
            })
            ->leftJoin('fundserv_transactions as f_right', function ($join) {
                $join->on('rm.right_id', '=', 'f_right.id')
                    ->where('rm.right_type', '=', 'fundserv');
            })
            ->where('rm.left_type', '=', 'viefund');  // Only viefund matches

        // Filter by reconciliation status
        if ($reconciliationFilter === 'hide_reconciled') {
            $matches->whereNull('rm.reconcile_type');
        } elseif ($reconciliationFilter === 'show_reconciled') {
            $matches->whereNotNull('rm.reconcile_type');
        }

        // Filter to only the fund_wo_numbers from this page (this is the only filter from search)
        $matches->whereIn('v_left.fund_wo_number', $fundWoNumbers);

        $matches = $matches
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
                'v_left.trx_id as viefund_trx_id',
                'v_left.fund_wo_number as viefund_fund_wo_number',
                'v_left.fund_trx_amount as viefund_amount',
                'v_left.settlement_date as viefund_settlement_date',
                'f_right.order_id as fundserv_order_id',
                'f_right.settlement_amt as fundserv_amount',
                'f_right.actual_amount as fundserv_actual_amount',
                'f_right.settlement_date as fundserv_settlement_date',
            ])
            ->orderBy('v_left.fund_wo_number', $direction)
            ->orderBy('v_left.trx_id', 'asc')
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
                // Group by fund_wo_number (to match pagination strategy)
                return $match->viefund_fund_wo_number;
            })
            ->map(function ($group, $key) {
                // Sort group by VieFund Trx ID ascending (for consistent child record ordering)
                $group = $group->sortBy(function ($item) {
                    return (int) ($item->viefund_trx_id ?? 0);
                })->values();
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

                // Calculate aggregated criteria (met only if ALL items have it met, except amount which uses aggregated totals)
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

                    // Build aggregated criteria array
                    $aggregatedCriteria = [];
                    $matchedCriteriaCount = 0;
                    $totalCriteriaCount = count($allRulesMap);
                    
                    foreach ($allRulesMap as $rule => $matchedValues) {
                        // Special handling for 'amount' criterion: use aggregated totals, not individual matches
                        if ($rule === 'amount') {
                            // Amount is matched if aggregated diff is essentially zero (within 0.01 for floating point precision)
                            $criterionMatched = $diff !== null && abs($diff) < 0.01;
                            $matchedCount = $criterionMatched ? count($matchedValues) : 0;
                        } else {
                            // For other criteria: matched only if ALL items have it met
                            $criterionMatched = count($matchedValues) === $group->count() && !in_array(false, $matchedValues, true);
                            $matchedCount = array_sum($matchedValues);
                        }
                        
                        if ($criterionMatched) {
                            $matchedCriteriaCount++;
                        }
                        
                        $aggregatedCriteria[] = [
                            'rule' => $rule,
                            'matched' => $criterionMatched,
                            'matched_count' => $matchedCount,
                            'total_count' => count($matchedValues),
                        ];
                    }
                    
                    // Recalculate aggregated confidence based on corrected criteria (only for parent/first record)
                    // Confidence = number of matched criteria / total criteria
                    if ($totalCriteriaCount > 0 && $group->count() > 1) {
                        // Multi-transaction group: recalculate confidence based on aggregated criteria
                        $aggregatedConfidence = $matchedCriteriaCount / $totalCriteriaCount;
                    }
                    // For single transactions, keep the original confidence
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

        // Filter grouped matches by reconciliation status (already done at DB level, but apply again for consistency)
        if ($reconciliationFilter === 'hide_reconciled') {
            $groupedMatches = $groupedMatches->filter(function ($group) {
                return empty($group['first']->reconcile_type);
            })->values();
        } elseif ($reconciliationFilter === 'show_reconciled') {
            $groupedMatches = $groupedMatches->filter(function ($group) {
                return !empty($group['first']->reconcile_type);
            })->values();
        }
        // 'show_all' doesn't need filtering

        // Use the paginator from fund_wo_number pagination, but with grouped matches
        $matchesPaginator = new LengthAwarePaginator(
            $groupedMatches->values(),
            $fundWoPaginated->total(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
        $pagedGroups = $groupedMatches->values();

        return view('reconciliations.matches', compact(
            'matchesPaginator',
            'pagedGroups',
            'totalMatches',
            'fundservTotal',
            'viefundTotal',
            'bankTotal',
            'accountFeesTotal',
            'advisoryFeesTotal',
            'fundservMatched',
            'viefundMatched',
            'bankMatched',
            'accountFeesMatched',
            'advisoryFeesMatched',
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
     * Display the dashboard with remote VieFund statistics.
     */
    public function dashboard()
    {
        $stats = null;
        $connectionError = null;
        $bankStats = [
            'entry_count' => 0,
            'source_file_count' => 0,
            'latest_value_date' => null,
            'analyzed_count' => 0,
        ];

        try {
            $bankStats['entry_count'] = BankStatementEntry::count();
            $bankStats['source_file_count'] = (int) BankStatementEntry::distinct('source_file')->count('source_file');
            $bankStats['latest_value_date'] = BankStatementEntry::max('value_date');
            $bankStats['analyzed_count'] = (int) DB::table('bank_statement_entry_analyses')
                ->where('parser_version', '=', 'v2')
                ->count();
        } catch (\Exception $e) {
            Log::warning('Failed to load bank dashboard metrics: ' . $e->getMessage());
        }

        try {
            $stats = \Illuminate\Support\Facades\Cache::remember('viefund_dashboard_stats', 86400, function () {
                return app(VieFundRemoteService::class)->getDashboardStats();
            });
        } catch (\Exception $e) {
            $connectionError = $e->getMessage();
        }

        return view('reconciliations.dashboard', compact('stats', 'connectionError', 'bankStats'));
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

    /**
     * Get available search fields for each transaction table
     */
    public function getSearchFields()
    {
        return response()->json([
            'viefund' => [
                'trx_id' => 'Transaction ID',
                'client_name' => 'Client Name',
                'account_id' => 'Account ID',
                'source_id' => 'Source ID',
                'fund_code' => 'Fund Code',
                'fund_wo_number' => 'Fund WO Number',
                'status' => 'Status',
                'plan_description' => 'Plan Description',
            ],
            'fundserv' => [
                'order_id' => 'Order ID',
                'dealer_account_id' => 'Dealer Account ID',
                'source_identifier' => 'Source Identifier',
                'fund_id' => 'Fund ID',
                'code' => 'Code',
                'company' => 'Company',
                'src' => 'Source',
            ],
            'bank' => [
                'account_number' => 'Account Number',
                'description' => 'Description',
                'type' => 'Type',
                'currency' => 'Currency',
            ],
        ]);
    }

    /**
     * Search transactions and return matching reconciliation match IDs
     */
    public function searchTransactions(Request $request)
    {
        $validated = $request->validate([
            'field' => 'required|string',
            'value' => 'required|string',
            'table' => 'required|in:viefund,fundserv,bank',
        ]);

        try {
            $field = $validated['field'];
            $value = $validated['value'];
            $table = $validated['table'];

            $matchIds = [];

            // Search based on transaction table
            if ($table === 'viefund') {
                // Find VieFund transactions matching the search
                $transactionIds = VieFundTransaction::where($field, 'LIKE', '%' . $value . '%')
                    ->pluck('id')
                    ->toArray();
                
                if (empty($transactionIds)) {
                    return response()->json(['success' => false, 'matchIds' => [], 'message' => 'No transactions found']);
                }

                // Get all reconciliation_matches where left_id is in these transaction IDs
                $matchIds = DB::table('reconciliation_matches')
                    ->whereIn('left_id', $transactionIds)
                    ->pluck('id')
                    ->toArray();

            } elseif ($table === 'fundserv') {
                // Find Fundserv transactions matching the search
                $transactionIds = DB::table('fundserv_transactions')
                    ->where($field, 'LIKE', '%' . $value . '%')
                    ->pluck('id')
                    ->toArray();
                
                if (empty($transactionIds)) {
                    return response()->json(['success' => false, 'matchIds' => [], 'message' => 'No transactions found']);
                }

                // Get all reconciliation_matches where right_id is in these transaction IDs
                $matchIds = DB::table('reconciliation_matches')
                    ->whereIn('right_id', $transactionIds)
                    ->pluck('id')
                    ->toArray();

            } elseif ($table === 'bank') {
                // Find Bank transactions matching the search
                $transactionIds = DB::table('bank_transactions')
                    ->where($field, 'LIKE', '%' . $value . '%')
                    ->pluck('id')
                    ->toArray();
                
                if (empty($transactionIds)) {
                    return response()->json(['success' => false, 'matchIds' => [], 'message' => 'No transactions found']);
                }

                // Get all reconciliation_matches where bank_transaction_id is in these transaction IDs
                $matchIds = DB::table('reconciliation_matches')
                    ->whereIn('bank_transaction_id', $transactionIds)
                    ->pluck('id')
                    ->toArray();
            }

            if (empty($matchIds)) {
                return response()->json(['success' => false, 'matchIds' => [], 'message' => 'No matching records found']);
            }

            return response()->json(['success' => true, 'matchIds' => $matchIds, 'count' => count($matchIds)]);

        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Run fee transaction matching
     */
    public function runFeeMatching()
    {
        try {
            $matcher = new FeeTransactionMatcher();
            $result = $matcher->matchFeesToFees(false);

            return redirect()->route('reconciliations.fee-matching-results')
                ->with('success', "Fee matching completed! {$result['matched_count']} matches found.");
        } catch (\Exception $e) {
            Log::error('Fee matching error: ' . $e->getMessage());
            return redirect()->back()
                ->withErrors('error', 'An error occurred during fee matching: ' . $e->getMessage());
        }
    }

    /**
     * Display fee matching results and unmatched fees
     */
    public function feeMatchingResults()
    {
        $matcher = new FeeTransactionMatcher();
        $summary = $matcher->getMatchSummary();
        
        $unmatchedAccountFees = $matcher->getUnmatchedAccountFees()->paginate(50);
        $unmatchedAdvisoryFees = $matcher->getUnmatchedAdvisoryFees()->paginate(50);

        return view('reconciliations.fee-matching-results', compact(
            'summary',
            'unmatchedAccountFees',
            'unmatchedAdvisoryFees'
        ));
    }
}
