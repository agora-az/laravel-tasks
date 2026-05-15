<?php

namespace App\Services\Reconciliation;

use App\Models\AccountFeeTransaction;
use App\Models\AdvisoryFeeTransaction;
use Illuminate\Support\Facades\DB;

class FeeTransactionMatcher
{
    public $matchedPairs = [];
    public $matchedAccountFeeIds = [];
    public $matchedAdvisoryFeeIds = [];

    /**
     * Match Account Fees to Advisory Fees
     * 
     * This is a high-confidence match looking for exact duplicates:
     * - Same client name
     * - Same settlement date
     * - Same transaction type
     * - Same amount (accounting for sign differences - fees may appear with opposite signs)
     */
    public function matchFeesToFees($dryRun = true)
    {
        $this->matchedPairs = [];
        $this->matchedAccountFeeIds = [];
        $this->matchedAdvisoryFeeIds = [];

        $totalProcessed = 0;
        $startTime = time();
        $matcher = $this;  // Capture $this for use in closure

        // Process each account fee
        AccountFeeTransaction::whereNotNull('settlement_date')->each(function ($accountFee) use (&$totalProcessed, &$startTime, $matcher) {
            $totalProcessed++;
            
            // Skip if already matched
            if (in_array($accountFee->id, $matcher->matchedAccountFeeIds)) {
                return true;
            }

            // Find matching advisory fee
            // Account for sign differences (advisory fees may be negative, account fees positive)
            $advisoryFee = AdvisoryFeeTransaction::where('full_name', $accountFee->client_name)
                ->where('settlement_date', $accountFee->settlement_date)
                ->where('transaction_type', $accountFee->transaction_type)
                ->whereRaw('ABS(ABS(CAST(amount AS DECIMAL(15,2))) - ?) < 0.01', [abs($accountFee->amount)])
                ->whereNotIn('id', $matcher->matchedAdvisoryFeeIds)
                ->first();

            if ($advisoryFee) {
                $confidence = $matcher->calculateFeeMatchConfidence($accountFee, $advisoryFee);
                
                $matcher->matchedPairs[] = [
                    'left_type' => 'account-fee',
                    'left_id' => $accountFee->id,
                    'right_type' => 'advisory-fee',
                    'right_id' => $advisoryFee->id,
                    'left_amount' => $accountFee->amount,
                    'right_amount' => $advisoryFee->amount,
                    'matched_amount' => abs($accountFee->amount),
                    'confidence' => $confidence,
                    'match_rule' => 'fee_to_fee_exact',
                    'matched_at' => now(),
                ];

                $matcher->matchedAccountFeeIds[] = $accountFee->id;
                $matcher->matchedAdvisoryFeeIds[] = $advisoryFee->id;
            }

            // Log progress every 5000
            if ($totalProcessed % 5000 == 0) {
                $elapsed = time() - $startTime;
                \Log::info("Fee matching progress", [
                    'processed' => $totalProcessed,
                    'matches_found' => count($matcher->matchedPairs),
                    'advisory_fees_matched' => count($matcher->matchedAdvisoryFeeIds),
                    'elapsed_seconds' => $elapsed,
                    'matches_percent' => round((count($matcher->matchedPairs) / ($totalProcessed > 0 ? $totalProcessed : 1)) * 100, 1),
                    'avg_per_record' => round($elapsed / $totalProcessed, 4),
                ]);
            }
            
            return true; // Continue iteration
        });

        if (!$dryRun) {
            $this->persistMatches();
        }

        return [
            'matches' => $this->matchedPairs,
            'matched_count' => count($this->matchedPairs),
            'account_fees_processed' => $totalProcessed,
            'account_fees_matched' => count($this->matchedAccountFeeIds),
            'advisory_fees_matched' => count($this->matchedAdvisoryFeeIds),
        ];
    }

    /**
     * Calculate confidence for a fee-to-fee match
     * 
     * Scale:
     * - 0.95: Perfect match (exact amount, same sign)
     * - 0.90: Debit/Credit match (opposite signs, typical for accounting systems)
     *         This is actually a STRONG match as it indicates proper double-entry bookkeeping
     */
    public function calculateFeeMatchConfidence($accountFee, $advisoryFee)
    {
        $accountAmount = floatval($accountFee->amount);
        $advisoryAmount = floatval($advisoryFee->amount);

        // Perfect match - same sign
        if ((($accountAmount >= 0 && $advisoryAmount >= 0) || ($accountAmount < 0 && $advisoryAmount < 0)) &&
            abs($accountAmount - $advisoryAmount) < 0.01) {
            return 0.95;
        }

        // Debit/Credit match - opposite signs (this is normal for accounting)
        // These are actually very reliable matches because they indicate double-entry bookkeeping
        if ((($accountAmount >= 0 && $advisoryAmount < 0) || ($accountAmount < 0 && $advisoryAmount >= 0)) &&
            abs(abs($accountAmount) - abs($advisoryAmount)) < 0.01) {
            return 0.90;
        }

        return 0.85; // Fallback (shouldn't reach here)
    }

    /**
     * Persist matched pairs to reconciliation_matches table
     */
    private function persistMatches()
    {
        foreach ($this->matchedPairs as $match) {
            DB::table('reconciliation_matches')->insert([
                'left_type' => $match['left_type'],
                'left_id' => $match['left_id'],
                'right_type' => $match['right_type'],
                'right_id' => $match['right_id'],
                'left_amount' => $match['left_amount'],
                'right_amount' => $match['right_amount'],
                'matched_amount' => $match['matched_amount'],
                'confidence' => $match['confidence'],
                'match_rule' => $match['match_rule'],
                'matched_at' => $match['matched_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Get unmatched account fees
     */
    public function getUnmatchedAccountFees()
    {
        $matchedIds = DB::table('reconciliation_matches')
            ->where('left_type', 'account-fee')
            ->pluck('left_id')
            ->toArray();

        return AccountFeeTransaction::whereNotIn('id', $matchedIds)
            ->orderBy('settlement_date', 'desc')
            ->get();
    }

    /**
     * Get unmatched advisory fees
     */
    public function getUnmatchedAdvisoryFees()
    {
        $matchedIds = DB::table('reconciliation_matches')
            ->where('right_type', 'advisory-fee')
            ->pluck('right_id')
            ->toArray();

        return AdvisoryFeeTransaction::whereNotIn('id', $matchedIds)
            ->orderBy('settlement_date', 'desc')
            ->get();
    }

    /**
     * Get match summary statistics
     */
    public function getMatchSummary()
    {
        $accountFeeTotal = AccountFeeTransaction::count();
        $advisoryFeeTotal = AdvisoryFeeTransaction::count();
        
        $accountFeeMatched = DB::table('reconciliation_matches')
            ->where('left_type', 'account-fee')
            ->distinct('left_id')
            ->count('left_id');

        $advisoryFeeMatched = DB::table('reconciliation_matches')
            ->where('right_type', 'advisory-fee')
            ->distinct('right_id')
            ->count('right_id');

        return [
            'account_fees' => [
                'total' => $accountFeeTotal,
                'matched' => $accountFeeMatched,
                'unmatched' => $accountFeeTotal - $accountFeeMatched,
                'match_percentage' => $accountFeeTotal > 0 ? round(($accountFeeMatched / $accountFeeTotal) * 100, 1) : 0,
            ],
            'advisory_fees' => [
                'total' => $advisoryFeeTotal,
                'matched' => $advisoryFeeMatched,
                'unmatched' => $advisoryFeeTotal - $advisoryFeeMatched,
                'match_percentage' => $advisoryFeeTotal > 0 ? round(($advisoryFeeMatched / $advisoryFeeTotal) * 100, 1) : 0,
            ],
        ];
    }
}
