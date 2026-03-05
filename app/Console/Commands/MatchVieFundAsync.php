<?php

namespace App\Console\Commands;

use App\Models\MatchingSession;
use App\Models\VieFundTransaction;
use App\Services\Reconciliation\VieFundFundservMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MatchVieFundAsync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconcile:match-viefund-async {--session=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Match VieFund to Fundserv transactions asynchronously with progress tracking';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sessionId = $this->option('session');

        if (!$sessionId) {
            $this->error('Session ID is required: --session=<id>');
            return 1;
        }

        $session = MatchingSession::find($sessionId);
        if (!$session) {
            $this->error("Matching session {$sessionId} not found");
            return 1;
        }

        try {
            Log::info("MatchVieFundAsync: Starting for session {$sessionId}");
            $this->info("Starting matching session {$sessionId}...");

            $matcher = app(VieFundFundservMatcher::class);
            $matchedCount = $matcher->matchAllWithProgressTracking($session);

            // Auto-reconcile parent matches with 100% aggregated confidence
            // Get all groups and their aggregated confidence
            $groups = DB::table('reconciliation_matches')
                ->select(
                    'right_id',
                    'right_type',
                    DB::raw('MIN(id) as parent_id'),
                    DB::raw('COUNT(*) as group_count'),
                    DB::raw('AVG(confidence) as avg_confidence')
                )
                ->where('reconcile_type', null)
                ->groupBy('right_id', 'right_type')
                ->get();

            $autoReconciledCount = 0;
            foreach ($groups as $group) {
                // Only auto-reconcile if aggregated confidence is 100%
                if ($group->avg_confidence == 1.0) {
                    $updated = DB::table('reconciliation_matches')
                        ->where('id', $group->parent_id)
                        ->update([
                            'reconcile_type' => 'auto',
                            'reconcile_date' => now(),
                            'reconcile_notes' => 'Auto reconciled - 100% match',
                        ]);
                    $autoReconciledCount += $updated;
                }
            }

            Log::info("MatchVieFundAsync: Auto-reconciled {$autoReconciledCount} matches with 100% confidence");
            $this->info("Auto-reconciled {$autoReconciledCount} matches with 100% confidence.");

            // Mark session as completed
            $session->update([
                'status' => 'completed',
                'matched_count' => $matchedCount,
                'completed_at' => now(),
            ]);

            Log::info("MatchVieFundAsync: Completed for session {$sessionId}. Created {$matchedCount} matches.");
            $this->info("Matching completed! Created {$matchedCount} matches.");

            return 0;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("MatchVieFundAsync: Failed for session {$sessionId}: " . $errorMsg);
            Log::error('Stack trace: ' . $e->getTraceAsString());
            $this->error('Error: ' . $errorMsg);

            // Only store first 500 chars of error to avoid TEXT column overflow
            $truncatedError = substr($errorMsg, 0, 500);
            if (strlen($errorMsg) > 500) {
                $truncatedError .= '... (truncated)';
            }

            $session->update([
                'status' => 'failed',
                'error_message' => $truncatedError,
                'completed_at' => now(),
            ]);

            return 1;
        }
    }
}
