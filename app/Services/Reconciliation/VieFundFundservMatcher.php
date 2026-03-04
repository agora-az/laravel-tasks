<?php

namespace App\Services\Reconciliation;

use App\Models\MatchCriteria;
use App\Models\VieFundTransaction;
use App\Models\FundservTransaction;
use App\Models\ReconciliationMatch;
use App\Models\MatchingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VieFundFundservMatcher
{
    public const RULE_VIEFUND_FUNDSERV = 'viefund_to_fundserv_criteria_based';
    private const TOTAL_PASSES = 5; // Total number of matching passes

    // Criterion codes
    private const CRITERION_FUND_WO_ORDER_ID = 'fund_wo_order_id';
    private const CRITERION_SETTLEMENT_DATE = 'settlement_date';
    private const CRITERION_AMOUNT_AND_TYPE = 'amount_and_type';
    private const CRITERION_FUND_CODE_AND_FUND_ID = 'fund_code_and_fund_id';
    private const CRITERION_SOURCE_IDENTIFIER = 'source_identifier';

    /**
     * Convert a rule key to a human-readable label.
     */
    public static function getRuleLabel(string $ruleKey): string
    {
        return match ($ruleKey) {
            self::RULE_VIEFUND_FUNDSERV => 'VieFund to Fundserv',
            default => str_replace('_', ' ', ucwords($ruleKey, '_')),
        };
    }

    /**
     * VieFund transaction types and their Fundserv type mappings.
     * Maps VieFund fund_trx_type to expected Fundserv tx_type for matching.
     */
    private const VIEFUND_TO_FUNDSERV_TYPE_MAP = [
        'Purchase' => 'Buy',
        'Purchase PAC' => 'Buy',
        'Rebalancing purchase' => 'Buy',
        'Redemption' => 'Sell',
        'Rebalancing redemption' => 'Sell',
        'Fee' => 'Fee',
        'Fee Redemption' => 'Fee',
        'Automatic/Systematic' => null, // Can be either Buy or Sell
        'Fund TrxType' => null, // Need to determine from context
    ];

    /**
     * Match all VieFund transactions to Fundserv transactions based on criteria.
     */
    public function matchAll(bool $dryRun = false): int
    {
        Log::info('matchAll(): Starting');
        $inserted = 0;
        $batch = [];
        $batchSize = 500;

        try {
            // Get all VieFund transactions that aren't already matched to Fundserv
            Log::info('matchAll(): Querying unmatched VieFund transactions');
            Log::info('matchAll(): Building query...');
            
            // First, check if VieFundTransaction table has data
            $totalCount = VieFundTransaction::count();
            Log::info('matchAll(): Total VieFund transactions: ' . $totalCount);
            
            if ($totalCount === 0) {
                Log::info('matchAll(): No VieFund transactions found');
                return 0;
            }
            
            // Get matched IDs to exclude
            $matchedIds = DB::table('reconciliation_matches')
                ->where('left_type', 'viefund')
                ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
                ->pluck('left_id')
                ->toArray();
            
            Log::info('matchAll(): Found ' . count($matchedIds) . ' already matched VieFund IDs');
            
            // Process in chunks to avoid memory issues
            Log::info('matchAll(): Processing transactions in chunks...');
            $chunkSize = 50; // Smaller chunks = faster processing for large datasets
            
            if (count($matchedIds) > 0) {
                $query = VieFundTransaction::whereNotIn('id', $matchedIds);
            } else {
                $query = VieFundTransaction::query();
            }
            
            $processedCount = 0;
            $query->chunk($chunkSize, function ($chunk) use (&$inserted, &$batch, &$processedCount, $batchSize, $dryRun) {
                $processedCount += $chunk->count();
                Log::info('matchAll(): [' . now()->format('H:i:s') . '] Processing chunk, total processed: ' . $processedCount);
                
                foreach ($chunk as $vieFundTrx) {
                    Log::debug('matchAll(): Processing VieFund ID ' . $vieFundTrx->id);
                    
                    // Get candidate Fundserv transactions
                    $fundservCandidates = $this->getCandidateFundservTransactions($vieFundTrx);

                    if ($fundservCandidates->isEmpty()) {
                        Log::debug('matchAll(): No candidates for VieFund ID ' . $vieFundTrx->id);
                        continue;
                    }

                    Log::debug('matchAll(): Found ' . $fundservCandidates->count() . ' candidates for VieFund ID ' . $vieFundTrx->id);

                    // Evaluate and score each candidate
                    $scoredMatches = [];
                    foreach ($fundservCandidates as $fundservTrx) {
                        $criteriaResults = $this->evaluateCriteria($vieFundTrx, $fundservTrx);
                        $confidence = $this->calculateConfidence($criteriaResults);

                        if ($confidence > 0) {
                            $scoredMatches[] = [
                                'fundserv' => $fundservTrx,
                                'criteria' => $criteriaResults,
                                'confidence' => $confidence,
                            ];
                        }
                    }

                    // Only take the best match if confidence is above minimum threshold
                    if (!empty($scoredMatches)) {
                        usort($scoredMatches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                        $bestMatch = $scoredMatches[0];

                        if ($bestMatch['confidence'] > 0) {
                            $batch[] = [
                                'left_type' => 'viefund',
                                'left_id' => $vieFundTrx->id,
                                'right_type' => 'fundserv',
                                'right_id' => $bestMatch['fundserv']->id,
                                'match_rule' => self::RULE_VIEFUND_FUNDSERV,
                                'confidence' => $bestMatch['confidence'],
                                'matched_amount' => $vieFundTrx->fund_trx_amount,
                                'status' => 'matched',
                                'metadata' => json_encode([
                                    'viefund_trx_id' => $vieFundTrx->id,
                                    'fundserv_trx_id' => $bestMatch['fundserv']->id,
                                ]),
                                'match_criteria_met' => json_encode($bestMatch['criteria']),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            if (count($batch) >= $batchSize) {
                                Log::info('matchAll(): Inserting batch of ' . count($batch) . ' matches');
                                if (!$dryRun) {
                                    ReconciliationMatch::insert($batch);
                                }
                                $inserted += count($batch);
                                $batch = [];
                            }
                        }
                    }
                }
            });

            if (count($batch) > 0) {
                Log::info('matchAll(): Inserting final batch of ' . count($batch) . ' matches');
                if (!$dryRun) {
                    ReconciliationMatch::insert($batch);
                }
                $inserted += count($batch);
            }

            Log::info('matchAll(): Completed successfully. Inserted ' . $inserted . ' matches');
        } catch (\Throwable $e) {
            Log::error('matchAll(): Exception caught: ' . $e->getMessage());
            Log::error('matchAll(): File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('matchAll(): Trace: ' . $e->getTraceAsString());
            throw $e;
        }

        return $inserted;
    }

    /**
     * Match all VieFund transactions using multi-pass approach.
     * Pass 1: Match on Order ID (highest confidence)
     * Pass 2-5: Validate existing matches against other criteria
     */
    public function matchAllWithProgressTracking(MatchingSession $session): int
    {
        Log::info('matchAllWithProgressTracking(): Starting multi-pass matching for session ' . $session->id);
        $lastUpdateTime = now();

        try {
            $totalCount = VieFundTransaction::count();
            $session->update(['total_records' => $totalCount]);
            Log::info('matchAllWithProgressTracking(): Total VieFund transactions: ' . $totalCount);
            
            if ($totalCount === 0) {
                $session->update(['status' => 'completed', 'completed_at' => now()]);
                return 0;
            }

            // PASS 1: Match on Order ID (0-20% of progress bar)
            Log::info('matchAllWithProgressTracking(): PASS 1 - Matching on Order ID');
            
            // Count only unique VieFund order IDs (fund_wo_number) - the actual items we're processing
            $pass1TotalCount = VieFundTransaction::whereNotNull('fund_wo_number')->distinct('fund_wo_number')->count();
            Log::info('matchAllWithProgressTracking(): PASS 1 setup - total_records_in_pass: ' . $pass1TotalCount . ', starting progress: 0');
            $session->update([
                'processed_records' => 0,
                'matched_count' => 0,
                'current_pass' => 'Pass 1 of 5: Order ID Matching',
                'current_pass_number' => 1,
                'total_records' => $pass1TotalCount,  // Unique VieFund orders
                'total_records_in_pass' => $pass1TotalCount,
                'progress_percentage' => 0,
            ]);
            $inserted = $this->passMatchOnOrderId($session, $lastUpdateTime, $pass1TotalCount);
            
            // PASS 2: Validate settlement dates on matched transactions (20-40%)
            Log::info('matchAllWithProgressTracking(): PASS 2 - Validating settlement dates');
            Log::info('matchAllWithProgressTracking(): PASS 2 setup - total_records_in_pass: ' . $inserted . ', starting progress: 20.0');
            $session->update([
                'processed_records' => 0,
                'current_pass' => 'Pass 2 of 5: Validate Settlement Dates',
                'current_pass_number' => 2,
                'total_records_in_pass' => $inserted,
                'progress_percentage' => 20.0,
            ]);
            $this->passValidateSettlementDate($session, $lastUpdateTime, $inserted);
            
            // PASS 3: Validate amount and type on matched transactions (40-60%)
            Log::info('matchAllWithProgressTracking(): PASS 3 - Validating amount and type');
            $session->update([
                'processed_records' => 0,
                'current_pass' => 'Pass 3 of 5: Validate Amount & Type',
                'current_pass_number' => 3,
                'total_records_in_pass' => $inserted,
                'progress_percentage' => 40.0,
            ]);
            $this->passValidateAmountAndType($session, $lastUpdateTime, $inserted);
            
            // PASS 4: Validate fund code on matched transactions (60-80%)
            Log::info('matchAllWithProgressTracking(): PASS 4 - Validating fund code');
            $session->update([
                'processed_records' => 0,
                'current_pass' => 'Pass 4 of 5: Validate Fund Code',
                'current_pass_number' => 4,
                'total_records_in_pass' => $inserted,
                'progress_percentage' => 60.0,
            ]);
            $this->passValidateFundCode($session, $lastUpdateTime, $inserted);
            
            // PASS 5: Validate source identifier on matched transactions (80-100%)
            Log::info('matchAllWithProgressTracking(): PASS 5 - Validating source identifier');
            $session->update([
                'processed_records' => 0,
                'current_pass' => 'Pass 5 of 5: Validate Source ID',
                'current_pass_number' => 5,
                'total_records_in_pass' => $inserted,
                'progress_percentage' => 80.0,
            ]);
            $this->passValidateSourceId($session, $lastUpdateTime, $inserted);
            
            // Final step: Recalculate confidence for all matches based on actual criteria
            Log::info('matchAllWithProgressTracking(): Recalculating confidence for all matches');
            $this->recalculateAllConfidences();

            // Get final count of matches
            $finalCount = DB::table('reconciliation_matches')
                ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
                ->count();

            $session->update([
                'processed_records' => $totalCount,
                'matched_count' => $finalCount,
                'status' => 'completed',
                'completed_at' => now(),
                'current_pass' => 'Completed',
                'current_pass_number' => 6,
            ]);
            
            Log::info('matchAllWithProgressTracking(): Completed successfully. Total matches: ' . $finalCount);
            return $finalCount;
        } catch (\Throwable $e) {
            Log::error('matchAllWithProgressTracking(): Exception: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Pass 1: Match VieFund to Fundserv based on Order ID
     */
    private function passMatchOnOrderId(MatchingSession $session, &$lastUpdateTime, int $totalRecordsInPass): int
    {
        Log::info('passMatchOnOrderId(): Starting');
        $session->refresh(); // Refresh from DB to get latest current_pass_number
        Log::info('passMatchOnOrderId(): Current pass number: ' . $session->current_pass_number);
        $lastUpdateTime = now(); // Reset throttle timer for Pass 1
        $inserted = 0;
        $batch = [];
        $batchSize = 1000;  // Batch size for match inserts
        $totalProcessed = 0;
        $matchedFundservIds = []; // Track which Fundserv already matched in this pass
        $processedFundWoNumbers = []; // Track unique fund_wo_number values we've processed

        // Build index: order_id => Fundserv ID (much faster than iterating all Fundserv for each VieFund)
        Log::info('passMatchOnOrderId(): Building Fundserv order_id index');
        $fundservIndex = FundservTransaction::whereNotNull('order_id')
            ->pluck('id', 'order_id')
            ->toArray();
        
        Log::info('passMatchOnOrderId(): Built index with ' . count($fundservIndex) . ' order IDs');
        $session->update(['processed_records' => 0, 'matched_count' => 0, 'progress_percentage' => 0]);

        // Get Fundserv IDs already matched (to avoid duplicates)
        $alreadyMatchedFundservIds = DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('right_type', 'fundserv')
            ->pluck('right_id')
            ->toArray();
        
        Log::info('passMatchOnOrderId(): ' . count($alreadyMatchedFundservIds) . ' Fundserv already matched');

        // Process VieFund transactions
        VieFundTransaction::whereNotNull('fund_wo_number')->chunk(100, function ($chunk) use (&$inserted, &$batch, $batchSize, &$lastUpdateTime, $fundservIndex, $session, &$totalProcessed, &$matchedFundservIds, $alreadyMatchedFundservIds, $totalRecordsInPass, &$processedFundWoNumbers) {
            foreach ($chunk as $vieFundTrx) {
                // Count unique fund_wo_number values processed (not total rows)
                if (!isset($processedFundWoNumbers[$vieFundTrx->fund_wo_number])) {
                    $processedFundWoNumbers[$vieFundTrx->fund_wo_number] = true;
                    $totalProcessed++;
                }
                
                // Look up Fundserv ID by order ID from index (O(1) instead of O(n))
                if (!isset($fundservIndex[$vieFundTrx->fund_wo_number])) {
                    continue; // No Fundserv with this order ID
                }

                $fundservId = $fundservIndex[$vieFundTrx->fund_wo_number];

                // Skip if this Fundserv is already matched (respects unique constraint)
                if (in_array($fundservId, $alreadyMatchedFundservIds) || isset($matchedFundservIds[$fundservId])) {
                    continue;
                }

                // Check if this VieFund is already matched
                $existing = DB::table('reconciliation_matches')
                    ->where('left_id', $vieFundTrx->id)
                    ->where('left_type', 'viefund')
                    ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $batch[] = [
                    'left_type' => 'viefund',
                    'left_id' => $vieFundTrx->id,
                    'right_type' => 'fundserv',
                    'right_id' => $fundservId,
                    'match_rule' => self::RULE_VIEFUND_FUNDSERV,
                    'confidence' => 0.20,  // Only order_id matched in Pass 1 (20%)
                    'matched_amount' => $vieFundTrx->fund_trx_amount,
                    'status' => 'matched',
                    'metadata' => json_encode(['match_type' => 'order_id']),
                    'match_criteria_met' => json_encode([
                        ['rule' => 'order_id', 'matched' => true],
                        ['rule' => 'settlement_date', 'matched' => false],
                        ['rule' => 'amount_type', 'matched' => false],
                        ['rule' => 'fund_code', 'matched' => false],
                        ['rule' => 'source_id', 'matched' => false],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Track this Fundserv as matched in current batch
                $matchedFundservIds[$fundservId] = true;

                if (count($batch) >= $batchSize) {
                    try {
                        ReconciliationMatch::insert($batch);
                        $inserted += count($batch);
                        
                        // Calculate overall progress using master method
                        $progressWithinPass = $totalProcessed / $totalRecordsInPass;
                        $overallProgress = $this->calculateMasterProgress($session->current_pass_number, $progressWithinPass);
                        
                        // Update progress on every batch
                        DB::table('matching_sessions')->where('id', $session->id)->update([
                            'processed_records' => $totalProcessed,
                            'matched_count' => $inserted,
                            'current_pass_number' => $session->current_pass_number,
                            'progress_percentage' => $overallProgress,
                        ]);
                        
                        // Log every 3 seconds to reduce excessive logging
                        if (now()->diffInSeconds($lastUpdateTime) >= 3) {
                            Log::info('passMatchOnOrderId(): Inserted batch of ' . count($batch) . ' matches. Total: ' . $inserted);
                            Log::info('passMatchOnOrderId() CHUNK UPDATE - pass: ' . $session->current_pass_number . ', processed: ' . $totalProcessed . ', totalInPass: ' . $totalRecordsInPass . ', progressWithin: ' . round($progressWithinPass, 3) . ', overall: ' . $overallProgress);
                            $lastUpdateTime = now();
                        }
                    } catch (\Exception $e) {
                        Log::error('passMatchOnOrderId(): Batch insert failed: ' . $e->getMessage());
                        Log::error('Batch size: ' . count($batch));
                        throw $e; // Re-throw to be caught by caller
                    }
                    $batch = [];
                }
            }
        });

        if (count($batch) > 0) {
            try {
                ReconciliationMatch::insert($batch);
                $inserted += count($batch);
                Log::info('passMatchOnOrderId(): Inserted final batch of ' . count($batch));
            } catch (\Exception $e) {
                Log::error('passMatchOnOrderId(): Final batch insert failed: ' . $e->getMessage());
                Log::error('Batch size: ' . count($batch));
                throw $e; // Re-throw to be caught by caller
            }
        }

        // Final update - Set to 20% (end of Pass 1)
        $session->update([
            'processed_records' => $totalProcessed,
            'matched_count' => $inserted,
            'current_pass_number' => $session->current_pass_number,
            'progress_percentage' => 20,
        ]);
        $session->refresh();
        Log::info('passMatchOnOrderId() FINAL - Stored current_pass_number: ' . $session->current_pass_number . ', Stored progress_percentage: ' . $session->progress_percentage);
        Log::info('passMatchOnOrderId(): Completed. Total processed: ' . $totalProcessed . ', matches: ' . $inserted);
        return $inserted;
    }

    /**
     * Pass 2: Validate settlement dates on existing matches
     */
    private function passValidateSettlementDate(MatchingSession $session, &$lastUpdateTime, int $totalRecordsInPass): void
    {
        Log::info('passValidateSettlementDate(): Starting, total records in pass: ' . $totalRecordsInPass);
        $session->refresh(); // Refresh from DB to get latest current_pass_number
        Log::info('passValidateSettlementDate(): Current pass number: ' . $session->current_pass_number);
        $validated = 0;

        DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('status', 'matched')
            ->orderBy('id')
            ->chunk(100, function($batch) use (&$validated, $session, &$lastUpdateTime, $totalRecordsInPass) {
                // Batch load all needed VieFund and Fundserv transactions
                $vieFundIds = $batch->pluck('left_id')->toArray();
                $fundservIds = $batch->pluck('right_id')->toArray();
                
                $vieFunds = VieFundTransaction::whereIn('id', $vieFundIds)->get()->keyBy('id');
                $fundservs = FundservTransaction::whereIn('id', $fundservIds)->get()->keyBy('id');

                foreach ($batch as $match) {
                    $vieFund = $vieFunds->get($match->left_id);
                    $fundserv = $fundservs->get($match->right_id);

                    if (!$vieFund || !$fundserv) {
                        continue;
                    }

                    $criteria = json_decode($match->match_criteria_met, true) ?? [];
                    // Update the settlement_date criteria in the array
                    $matched = $this->matchSettlementDate($vieFund, $fundserv);
                    foreach ($criteria as &$criterion) {
                        if ($criterion['rule'] === 'settlement_date') {
                            $criterion['matched'] = $matched;
                            break;
                        }
                    }
                    
                    DB::table('reconciliation_matches')
                        ->where('id', $match->id)
                        ->update(['match_criteria_met' => json_encode($criteria)]);

                    $validated++;
                }

                // Calculate overall progress using master method
                $progressWithinPass = $validated / $totalRecordsInPass;
                $overallProgress = $this->calculateMasterProgress($session->current_pass_number, $progressWithinPass);
                
                // Update progress using raw SQL to avoid transaction conflicts in chunk callback
                DB::table('matching_sessions')->where('id', $session->id)->update([
                    'processed_records' => $validated,
                    'current_pass_number' => $session->current_pass_number,
                    'progress_percentage' => $overallProgress,
                ]);
                
                // Log every 3 seconds to reduce excessive logging
                if (now()->diffInSeconds($lastUpdateTime) >= 3) {
                    Log::info('passValidateSettlementDate(): Validated ' . $validated . ' matches');
                    $lastUpdateTime = now();
                }
            });

        // Final update - Set to 40% (end of Pass 2)
        $session->update([
            'current_pass_number' => $session->current_pass_number,
            'progress_percentage' => 40,
        ]);
        $session->refresh();
        Log::info('passValidateSettlementDate() FINAL - Stored current_pass_number: ' . $session->current_pass_number . ', Stored progress_percentage: ' . $session->progress_percentage);
        Log::info('passValidateSettlementDate(): Completed. Validated ' . $validated . ' matches');
    }

    /**
     * Pass 3: Validate amount and type on existing matches
     */
    private function passValidateAmountAndType(MatchingSession $session, &$lastUpdateTime, int $totalRecordsInPass): void
    {
        Log::info('passValidateAmountAndType(): Starting, total records in pass: ' . $totalRecordsInPass);
        $session->refresh(); // Refresh from DB to get latest current_pass_number
        Log::info('passValidateAmountAndType(): Current pass number: ' . $session->current_pass_number);
        $validated = 0;

        DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('status', 'matched')
            ->orderBy('id')
            ->chunk(100, function($batch) use (&$validated, $session, &$lastUpdateTime, $totalRecordsInPass) {
                // Batch load all needed VieFund and Fundserv transactions
                $vieFundIds = $batch->pluck('left_id')->toArray();
                $fundservIds = $batch->pluck('right_id')->toArray();
                
                $vieFunds = VieFundTransaction::whereIn('id', $vieFundIds)->get()->keyBy('id');
                $fundservs = FundservTransaction::whereIn('id', $fundservIds)->get()->keyBy('id');

                foreach ($batch as $match) {
                    $vieFund = $vieFunds->get($match->left_id);
                    $fundserv = $fundservs->get($match->right_id);

                    if (!$vieFund || !$fundserv) {
                        continue;
                    }

                    $criteria = json_decode($match->match_criteria_met, true) ?? [];
                    // Update the amount_type criteria in the array
                    $matched = $this->matchAmountAndType($vieFund, $fundserv);
                    foreach ($criteria as &$criterion) {
                        if ($criterion['rule'] === 'amount_type') {
                            $criterion['matched'] = $matched;
                            break;
                        }
                    }
                    
                    DB::table('reconciliation_matches')
                        ->where('id', $match->id)
                        ->update(['match_criteria_met' => json_encode($criteria)]);

                    $validated++;
                }

                // Calculate overall progress using master method
                $progressWithinPass = $validated / $totalRecordsInPass;
                $overallProgress = $this->calculateMasterProgress($session->current_pass_number, $progressWithinPass);
                
                // Update progress using raw SQL to avoid transaction conflicts in chunk callback
                DB::table('matching_sessions')->where('id', $session->id)->update([
                    'processed_records' => $validated,
                    'current_pass_number' => $session->current_pass_number,
                    'progress_percentage' => $overallProgress,
                ]);
                
                // Log every 3 seconds to reduce excessive logging
                if (now()->diffInSeconds($lastUpdateTime) >= 3) {
                    Log::info('passValidateAmountAndType(): Validated ' . $validated . ' matches');
                    $lastUpdateTime = now();
                }
            });

        // Final update - Set to 60% (end of Pass 3)
        $session->update([
            'current_pass_number' => $session->current_pass_number,
            'progress_percentage' => 60,
        ]);
        $session->refresh();
        Log::info('passValidateAmountAndType() FINAL - Stored current_pass_number: ' . $session->current_pass_number . ', Stored progress_percentage: ' . $session->progress_percentage);
        Log::info('passValidateAmountAndType(): Completed. Validated ' . $validated . ' matches');
    }

    /**
     * Pass 4: Validate fund code on existing matches
     */
    private function passValidateFundCode(MatchingSession $session, &$lastUpdateTime, int $totalRecordsInPass): void
    {
        Log::info('passValidateFundCode(): Starting, total records in pass: ' . $totalRecordsInPass);
        $session->refresh(); // Refresh from DB to get latest current_pass_number
        Log::info('passValidateFundCode(): Current pass number: ' . $session->current_pass_number);
        $validated = 0;

        DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('status', 'matched')
            ->orderBy('id')
            ->chunk(100, function($batch) use (&$validated, $session, &$lastUpdateTime, $totalRecordsInPass) {
                // Batch load all needed VieFund and Fundserv transactions
                $vieFundIds = $batch->pluck('left_id')->toArray();
                $fundservIds = $batch->pluck('right_id')->toArray();
                
                $vieFunds = VieFundTransaction::whereIn('id', $vieFundIds)->get()->keyBy('id');
                $fundservs = FundservTransaction::whereIn('id', $fundservIds)->get()->keyBy('id');

                foreach ($batch as $match) {
                    $vieFund = $vieFunds->get($match->left_id);
                    $fundserv = $fundservs->get($match->right_id);

                    if (!$vieFund || !$fundserv) {
                        continue;
                    }

                    $criteria = json_decode($match->match_criteria_met, true) ?? [];
                    // Update the fund_code criteria in the array
                    $matched = $this->matchFundCodeAndFundId($vieFund, $fundserv);
                    foreach ($criteria as &$criterion) {
                        if ($criterion['rule'] === 'fund_code') {
                            $criterion['matched'] = $matched;
                            break;
                        }
                    }
                    
                    DB::table('reconciliation_matches')
                        ->where('id', $match->id)
                        ->update(['match_criteria_met' => json_encode($criteria)]);

                    $validated++;
                }

                // Calculate overall progress using master method
                $progressWithinPass = $validated / $totalRecordsInPass;
                $overallProgress = $this->calculateMasterProgress($session->current_pass_number, $progressWithinPass);
                
                // Update progress using raw SQL to avoid transaction conflicts in chunk callback
                DB::table('matching_sessions')->where('id', $session->id)->update([
                    'processed_records' => $validated,
                    'current_pass_number' => $session->current_pass_number,
                    'progress_percentage' => $overallProgress,
                ]);
                
                // Log every 3 seconds to reduce excessive logging
                if (now()->diffInSeconds($lastUpdateTime) >= 3) {
                    Log::info('passValidateFundCode(): Validated ' . $validated . ' matches');
                    $lastUpdateTime = now();
                }
            });

        // Final update - Set to 80% (end of Pass 4)
        $session->update([
            'current_pass_number' => $session->current_pass_number,
            'progress_percentage' => 80,
        ]);
        $session->refresh();
        Log::info('passValidateFundCode() FINAL - Stored current_pass_number: ' . $session->current_pass_number . ', Stored progress_percentage: ' . $session->progress_percentage);
        Log::info('passValidateFundCode(): Completed. Validated ' . $validated . ' matches');
    }

    /**
     * Pass 5: Validate source identifier on existing matches
     */
    private function passValidateSourceId(MatchingSession $session, &$lastUpdateTime, int $totalRecordsInPass): void
    {
        Log::info('passValidateSourceId(): Starting, total records in pass: ' . $totalRecordsInPass);
        $session->refresh(); // Refresh from DB to get latest current_pass_number
        Log::info('passValidateSourceId(): Current pass number: ' . $session->current_pass_number);
        $validated = 0;

        DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('status', 'matched')
            ->orderBy('id')
            ->chunk(100, function($batch) use (&$validated, $session, &$lastUpdateTime, $totalRecordsInPass) {
                // Batch load all needed VieFund and Fundserv transactions
                $vieFundIds = $batch->pluck('left_id')->toArray();
                $fundservIds = $batch->pluck('right_id')->toArray();
                
                $vieFunds = VieFundTransaction::whereIn('id', $vieFundIds)->get()->keyBy('id');
                $fundservs = FundservTransaction::whereIn('id', $fundservIds)->get()->keyBy('id');

                foreach ($batch as $match) {
                    $vieFund = $vieFunds->get($match->left_id);
                    $fundserv = $fundservs->get($match->right_id);

                    if (!$vieFund || !$fundserv) {
                        continue;
                    }

                    $criteria = json_decode($match->match_criteria_met, true) ?? [];
                    // Update the source_id criteria in the array
                    $matched = $this->matchSourceIdentifier($vieFund, $fundserv);
                    foreach ($criteria as &$criterion) {
                        if ($criterion['rule'] === 'source_id') {
                            $criterion['matched'] = $matched;
                            break;
                        }
                    }
                    
                    DB::table('reconciliation_matches')
                        ->where('id', $match->id)
                        ->update(['match_criteria_met' => json_encode($criteria)]);

                    $validated++;
                }

                // Calculate overall progress using master method
                $progressWithinPass = $validated / $totalRecordsInPass;
                $overallProgress = $this->calculateMasterProgress($session->current_pass_number, $progressWithinPass);
                
                // Update progress using raw SQL to avoid transaction conflicts in chunk callback
                DB::table('matching_sessions')->where('id', $session->id)->update([
                    'processed_records' => $validated,
                    'current_pass_number' => $session->current_pass_number,
                    'progress_percentage' => $overallProgress,
                ]);
                
                // Log every 3 seconds to reduce excessive logging
                if (now()->diffInSeconds($lastUpdateTime) >= 3) {
                    Log::info('passValidateSourceId(): Validated ' . $validated . ' matches');
                    $lastUpdateTime = now();
                }
            });

        // Final update - Set to 100% (end of Pass 5)
        $session->update([
            'current_pass_number' => $session->current_pass_number,
            'progress_percentage' => 100,
        ]);
        $session->refresh();
        Log::info('passValidateSourceId() FINAL - Stored current_pass_number: ' . $session->current_pass_number . ', Stored progress_percentage: ' . $session->progress_percentage);
        Log::info('passValidateSourceId(): Completed. Validated ' . $validated . ' matches');
    }

    /**
     * Calculate overall progress (0-100%) across all passes.
     * Formula: ((current_pass - 1) + progress_within_pass) * (100 / total_passes)
     * This ensures each pass occupies exactly equal percentage of the progress bar.
     * 
     * @param int $currentPassNumber The current pass number (1-N)
     * @param float $progressWithinPass Progress within this pass (0-1)
     * @param int $totalPasses Total number of passes (defaults to TOTAL_PASSES constant)
     * @return float Overall progress percentage (0-100)
     */
    private function calculateMasterProgress(int $currentPassNumber, float $progressWithinPass, int $totalPasses = self::TOTAL_PASSES): float
    {
        // Formula: ((current_pass - 1) + progress_within_pass) * (100 / total_passes)
        // This scales progress to equal percentage per pass
        $percentPerPass = 100 / $totalPasses;
        $result = round((($currentPassNumber - 1) + $progressWithinPass) * $percentPerPass, 1);
        return $result;
    }

    /**
     * Returns all unmatched Fundserv transactions within a settlement date window.
     */
    private function getCandidateFundservTransactions(VieFundTransaction $vieFundTrx)
    {
        // Start with simple match: same settlement date window
        // Limit to top 500 candidates to avoid huge result sets
        return FundservTransaction::where(function ($query) use ($vieFundTrx) {
            if ($vieFundTrx->settlement_date) {
                $query->whereBetween('settlement_date', [
                    $vieFundTrx->settlement_date->copy()->subDays(3),
                    $vieFundTrx->settlement_date->copy()->addDays(3),
                ]);
            }
        })
        ->leftJoin('reconciliation_matches as rm', function ($join) {
            $join->on('rm.right_id', '=', 'fundserv_transactions.id')
                ->where('rm.right_type', '=', 'fundserv')
                ->where('rm.match_rule', '=', self::RULE_VIEFUND_FUNDSERV);
        })
        ->whereNull('rm.id')
        ->select('fundserv_transactions.*')
        ->limit(500)  // Limit candidates to avoid processing too many
        ->get();
    }

    /**
     * Evaluate all criteria for a VieFund-Fundserv pair.
     * Returns array of criterion results with matched flag and match quality.
     */
    private function evaluateCriteria(VieFundTransaction $vieFund, FundservTransaction $fundserv): array
    {
        $results = [];

        // Criterion 1: Fund WO to Order ID
        $results[] = [
            'rule' => self::CRITERION_FUND_WO_ORDER_ID,
            'matched' => $this->matchFundWoToOrderId($vieFund, $fundserv),
        ];

        // Criterion 2: Settlement Date
        $results[] = [
            'rule' => self::CRITERION_SETTLEMENT_DATE,
            'matched' => $this->matchSettlementDate($vieFund, $fundserv),
        ];

        // Criterion 3: Amount and Type
        $results[] = [
            'rule' => self::CRITERION_AMOUNT_AND_TYPE,
            'matched' => $this->matchAmountAndType($vieFund, $fundserv),
        ];

        // Criterion 4: Fund Code and Fund ID
        $results[] = [
            'rule' => self::CRITERION_FUND_CODE_AND_FUND_ID,
            'matched' => $this->matchFundCodeAndFundId($vieFund, $fundserv),
        ];

        // Criterion 5: Source Identifier
        $results[] = [
            'rule' => self::CRITERION_SOURCE_IDENTIFIER,
            'matched' => $this->matchSourceIdentifier($vieFund, $fundserv),
        ];

        return $results;
    }

    /**
     * Criterion 1: Match VieFund Fund WO with Fundserv Order ID
     */
    private function matchFundWoToOrderId(VieFundTransaction $vieFund, FundservTransaction $fundserv): bool
    {
        if (!$vieFund->fund_wo_number || !$fundserv->order_id) {
            return false;
        }

        return (string)$vieFund->fund_wo_number === (string)$fundserv->order_id;
    }

    /**
     * Criterion 2: Match VieFund Settlement Date with Fundserv Settlement Date
     */
    private function matchSettlementDate(VieFundTransaction $vieFund, FundservTransaction $fundserv): bool
    {
        if (!$vieFund->settlement_date || !$fundserv->settlement_date) {
            return false;
        }

        return $vieFund->settlement_date->isSameDay($fundserv->settlement_date);
    }

    /**
     * Criterion 3: Match amounts with transaction type counter-logic.
     *
     * Purchase + Buy: VieFund amount should equal negative Fundserv amount
     * Redemption + Sell: VieFund amount should equal negative Fundserv amount
     * Fee + Fee: Amounts should match
     */
    private function matchAmountAndType(VieFundTransaction $vieFund, FundservTransaction $fundserv): bool
    {
        if (!$vieFund->fund_trx_amount || !$fundserv->actual_amount) {
            return false;
        }

        $vieFundAmount = (float)$vieFund->fund_trx_amount;
        $fundservAmount = (float)$fundserv->actual_amount;
        
        // Trim whitespace from types for more robust comparison
        $vieFundType = trim($vieFund->fund_trx_type ?? '');
        $fundservType = trim($fundserv->tx_type ?? '');

        // Map VieFund type to expected Fundserv type
        $expectedFundservType = self::VIEFUND_TO_FUNDSERV_TYPE_MAP[$vieFundType] ?? null;

        // Handle Automatic/Systematic and Fund TrxType - can match either Buy or Sell
        if ($expectedFundservType === null) {
            // For unknown types, match on amount alone
            return abs($vieFundAmount) === abs($fundservAmount);
        }

        // Check if Fundserv type matches expected (case-insensitive comparison)
        if (strtolower($fundservType) !== strtolower($expectedFundservType)) {
            return false;
        }

        // For all transaction types (Buy/Sell/Fee): VieFund amount should equal negative Fundserv amount
        return $vieFundAmount === -$fundservAmount;
    }

    /**
     * Criterion 4: Match VieFund Fund Code with Fundserv Code concatenated with Fund ID
     */
    private function matchFundCodeAndFundId(VieFundTransaction $vieFund, FundservTransaction $fundserv): bool
    {
        if (!$vieFund->fund_code || !$fundserv->code || !$fundserv->fund_id) {
            return false;
        }

        $concatenated = $fundserv->code . $fundserv->fund_id;
        return $vieFund->fund_code === $concatenated;
    }

    /**
     * Criterion 5: Match VieFund Source ID with Fundserv Source Identifier
     */
    private function matchSourceIdentifier(VieFundTransaction $vieFund, FundservTransaction $fundserv): bool
    {
        $vieFundSourceId = $vieFund->fund_source_id;
        $fundservSourceId = $fundserv->source_identifier;
        
        Log::debug('matchSourceIdentifier(): Comparing viefund_source_id=' . var_export($vieFundSourceId, true) . ' vs fundserv_source_identifier=' . var_export($fundservSourceId, true));
        
        if (!$vieFund->fund_source_id || !$fundserv->source_identifier) {
            Log::debug('matchSourceIdentifier(): One or both values are null/empty, returning false');
            return false;
        }

        $result = (string)$vieFund->fund_source_id === (string)$fundserv->source_identifier;
        Log::debug('matchSourceIdentifier(): String comparison result=' . ($result ? 'true' : 'false'));
        return $result;
    }

    /**
     * Calculate confidence based on matched criteria and their weights.
     *
     * Confidence = (sum of weights for matched criteria) / (sum of all weights)
     */
    private function calculateConfidence(array $criteriaResults): float
    {
        $totalWeight = 0;
        $matchedWeight = 0;

        // Get all match criteria with their weights
        $allCriteria = MatchCriteria::all()->keyBy('code');

        foreach ($criteriaResults as $result) {
            $criterion = $allCriteria->get($result['rule']);
            if ($criterion) {
                $weight = (float)$criterion->weight;
                $totalWeight += $weight;

                if ($result['matched']) {
                    $matchedWeight += $weight;
                }
            }
        }

        if ($totalWeight === 0) {
            return 0;
        }

        return round($matchedWeight / $totalWeight, 4);
    }

    /**
     * Recalculate confidence for all matches based on their actual criteria state.
     * This is called once at the end of all validation passes to ensure
     * final confidence scores reflect all 5 criteria evaluations.
     */
    private function recalculateAllConfidences(): void
    {
        Log::info('recalculateAllConfidences(): Starting');
        $updated = 0;

        DB::table('reconciliation_matches')
            ->where('match_rule', self::RULE_VIEFUND_FUNDSERV)
            ->where('status', 'matched')
            ->orderBy('id')
            ->chunk(500, function($batch) use (&$updated) {
                foreach ($batch as $match) {
                    $criteria = json_decode($match->match_criteria_met, true) ?? [];
                    
                    // Calculate confidence based on final criteria state
                    $newConfidence = $this->calculateConfidence($criteria);
                    
                    DB::table('reconciliation_matches')
                        ->where('id', $match->id)
                        ->update(['confidence' => $newConfidence]);
                    
                    $updated++;
                }
            });

        Log::info('recalculateAllConfidences(): Completed. Updated ' . $updated . ' match confidences');
    }
}
