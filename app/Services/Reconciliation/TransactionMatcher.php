<?php

namespace App\Services\Reconciliation;

use App\Models\FundservTransaction;
use App\Models\ReconciliationMatch;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionMatcher
{
    public const RULE_ORDER_ID_TO_FUND_WO = 'fundserv_order_id_to_viefund_fund_wo_number';
    public const RULE_BANK_TO_FUNDSERV = 'bank_to_fundserv_amount_date';
    public const RULE_BANK_TO_VIEFUND = 'bank_to_viefund_amount_date';

    /**
     * Convert a rule key to a human-readable label.
     */
    public static function getRuleLabel(string $ruleKey): string
    {
        return match ($ruleKey) {
            self::RULE_ORDER_ID_TO_FUND_WO => 'Fundserv to VieFund',
            self::RULE_BANK_TO_FUNDSERV => 'Bank to Fundserv',
            self::RULE_BANK_TO_VIEFUND => 'Bank to VieFund',
            default => str_replace('_', ' ', ucwords($ruleKey, '_')),
        };
    }

    public function matchFundservOrderIdToVieFundWoNumber(bool $dryRun = false): int
    {
        $rule = self::RULE_ORDER_ID_TO_FUND_WO;

        $query = DB::table('fundserv_transactions as f')
            ->join('viefund_transactions as v', function ($join) {
                $join->on('v.fund_wo_number', '=', 'f.order_id')
                    ->whereNotNull('v.fund_wo_number');
            })
            ->leftJoin('reconciliation_matches as rm', function ($join) use ($rule) {
                $join->on('rm.left_id', '=', 'f.id')
                    ->on('rm.right_id', '=', 'v.id')
                    ->where('rm.left_type', '=', 'fundserv')
                    ->where('rm.right_type', '=', 'viefund')
                    ->where('rm.match_rule', '=', $rule);
            })
            ->whereNotNull('f.order_id')
            ->whereNull('rm.id')
            ->select([
                'f.id as fundserv_id',
                'v.id as viefund_id',
                'f.order_id',
                'v.fund_wo_number',
                'f.settlement_amt',
                'v.fund_trx_amount',
            ]);

        if ($dryRun) {
            return (int) $query->count();
        }

        $inserted = 0;
        $batch = [];
        $batchSize = 500;

        $query->orderBy('f.id')->chunk(500, function ($rows) use (&$inserted, &$batch, $batchSize, $rule) {
            foreach ($rows as $row) {
                $batch[] = [
                    'left_type' => 'fundserv',
                    'left_id' => $row->fundserv_id,
                    'right_type' => 'viefund',
                    'right_id' => $row->viefund_id,
                    'match_rule' => $rule,
                    'confidence' => 1,
                    'matched_amount' => $row->fund_trx_amount ?? $row->settlement_amt,
                    'status' => 'matched',
                    'metadata' => json_encode([
                        'order_id' => $row->order_id,
                        'fund_wo_number' => $row->fund_wo_number,
                        'fundserv_amount' => $row->settlement_amt,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= $batchSize) {
                    ReconciliationMatch::insert($batch);
                    $inserted += count($batch);
                    $batch = [];
                }
            }
        });

        if (count($batch) > 0) {
            ReconciliationMatch::insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    public function matchBankToFundservAmountDate(bool $dryRun = false): int
    {
        $rule = self::RULE_BANK_TO_FUNDSERV;

        $query = DB::table('bank_transactions as b')
            ->join('fundserv_transactions as f', function ($join) {
                $join->whereRaw('ABS(f.settlement_amt) = ABS(b.amount)')
                    ->where(function ($dateJoin) {
                        $dateJoin->whereRaw('f.settlement_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)')
                            ->orWhereRaw('f.trade_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)');
                    });
            })
            ->leftJoin('reconciliation_matches as rm', function ($join) use ($rule) {
                $join->on('rm.left_id', '=', 'b.id')
                    ->on('rm.right_id', '=', 'f.id')
                    ->where('rm.left_type', '=', 'bank')
                    ->where('rm.right_type', '=', 'fundserv')
                    ->where('rm.match_rule', '=', $rule);
            })
            ->leftJoin('reconciliation_matches as rr', function ($join) use ($rule) {
                $join->on('rr.right_id', '=', 'f.id')
                    ->where('rr.right_type', '=', 'fundserv')
                    ->where('rr.match_rule', '=', $rule);
            })
            ->whereNull('rm.id')
            ->whereNull('rr.id')
            ->whereRaw('LOWER(b.description) LIKE ?', ['%fundserv%'])
            ->select([
                'b.id as bank_id',
                'f.id as fundserv_id',
                'b.amount',
                'b.txn_date',
                'b.description',
                'f.settlement_date',
                'f.trade_date',
            ]);

        if ($dryRun) {
            return (int) $query->count();
        }

        return $this->insertBankFundservMatches($query, $rule, false, $dryRun);
    }

    public function matchBankToFundservViaOrderId(bool $dryRun = false): int
    {
        $rule = self::RULE_BANK_TO_FUNDSERV;
        $orderRule = self::RULE_ORDER_ID_TO_FUND_WO;

        $query = DB::table('bank_transactions as b')
            ->join('fundserv_transactions as f', function ($join) {
                $join->whereRaw('ABS(f.settlement_amt) = ABS(b.amount)')
                    ->where(function ($dateJoin) {
                        $dateJoin->whereRaw('f.settlement_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)')
                            ->orWhereRaw('f.trade_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)');
                    });
            })
            ->join('reconciliation_matches as rv', function ($join) use ($orderRule) {
                $join->on('rv.left_id', '=', 'f.id')
                    ->where('rv.left_type', '=', 'fundserv')
                    ->where('rv.right_type', '=', 'viefund')
                    ->where('rv.match_rule', '=', $orderRule);
            })
            ->leftJoin('reconciliation_matches as rm', function ($join) use ($rule) {
                $join->on('rm.left_id', '=', 'b.id')
                    ->on('rm.right_id', '=', 'f.id')
                    ->where('rm.left_type', '=', 'bank')
                    ->where('rm.right_type', '=', 'fundserv')
                    ->where('rm.match_rule', '=', $rule);
            })
            ->leftJoin('reconciliation_matches as rr', function ($join) use ($rule) {
                $join->on('rr.right_id', '=', 'f.id')
                    ->where('rr.right_type', '=', 'fundserv')
                    ->where('rr.match_rule', '=', $rule);
            })
            ->whereNull('rm.id')
            ->whereNull('rr.id')
            ->whereRaw('LOWER(b.description) LIKE ?', ['%fundserv%'])
            ->select([
                'b.id as bank_id',
                'f.id as fundserv_id',
                'rv.right_id as viefund_id',
                'b.amount',
                'b.txn_date',
                'b.description',
                'f.settlement_date',
                'f.trade_date',
            ]);

        if ($dryRun) {
            return (int) $query->count();
        }

        return $this->insertBankFundservMatches($query, $rule, true, $dryRun);
    }

    public function matchBankToVieFundAmountDate(bool $dryRun = false): int
    {
        $rule = self::RULE_BANK_TO_VIEFUND;
        $bankFundservRule = self::RULE_BANK_TO_FUNDSERV;

        $query = DB::table('bank_transactions as b')
            ->join('viefund_transactions as v', function ($join) {
                $join->whereRaw('ABS(v.fund_trx_amount) = ABS(b.amount)')
                    ->where(function ($dateJoin) {
                        $dateJoin->whereRaw('v.settlement_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)')
                            ->orWhereRaw('v.trade_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)')
                            ->orWhereRaw('v.processing_date BETWEEN DATE_SUB(b.txn_date, INTERVAL 1 DAY) AND DATE_ADD(b.txn_date, INTERVAL 1 DAY)');
                    });
            })
            ->leftJoin('reconciliation_matches as rb', function ($join) use ($bankFundservRule) {
                $join->on('rb.left_id', '=', 'b.id')
                    ->where('rb.left_type', '=', 'bank')
                    ->where('rb.match_rule', '=', $bankFundservRule);
            })
            ->leftJoin('reconciliation_matches as rm', function ($join) use ($rule) {
                $join->on('rm.left_id', '=', 'b.id')
                    ->on('rm.right_id', '=', 'v.id')
                    ->where('rm.left_type', '=', 'bank')
                    ->where('rm.right_type', '=', 'viefund')
                    ->where('rm.match_rule', '=', $rule);
            })
            ->leftJoin('reconciliation_matches as rr', function ($join) use ($rule) {
                $join->on('rr.right_id', '=', 'v.id')
                    ->where('rr.right_type', '=', 'viefund')
                    ->where('rr.match_rule', '=', $rule);
            })
            ->whereNull('rb.id')
            ->whereNull('rm.id')
            ->whereNull('rr.id')
            ->whereRaw('LOWER(b.description) LIKE ?', ['%fundserv%'])
            ->select([
                'b.id as bank_id',
                'v.id as viefund_id',
                'b.amount',
                'b.txn_date',
                'b.description',
                'v.settlement_date',
                'v.trade_date',
                'v.processing_date',
            ]);

        if ($dryRun) {
            return (int) $query->count();
        }

        return $this->insertBankVieFundMatches($query, $rule, $dryRun);
    }

    public function matchBankChained(bool $dryRun = false): int
    {
        $inserted = 0;
        $inserted += $this->matchBankToFundservViaOrderId($dryRun);
        $inserted += $this->matchBankToFundservAmountDate($dryRun);
        $inserted += $this->matchBankToVieFundAmountDate($dryRun);

        return $inserted;
    }

    private function insertBankFundservMatches($query, string $rule, bool $includeVieFund, bool $dryRun = false): int
    {
        $inserted = 0;
        $batch = [];
        $batchSize = 500;

        $currentBankId = null;
        $currentRows = [];

        $processGroup = function (array $rows) use (&$inserted, &$batch, $batchSize, $rule, $dryRun, $includeVieFund) {
            if (count($rows) === 0) {
                return;
            }

            $scored = [];
            $sumScores = 0;

            foreach ($rows as $row) {
                [$score, $matchType] = $this->scoreBankFundservMatch($row->txn_date, $row->settlement_date, $row->trade_date);
                $scored[] = [$row, $score, $matchType];
                $sumScores += $score;
            }

            foreach ($scored as [$row, $score, $matchType]) {
                $confidence = $sumScores > 0 ? $score / $sumScores : 0;

                $metadata = [
                    'txn_date' => $row->txn_date,
                    'settlement_date' => $row->settlement_date,
                    'trade_date' => $row->trade_date,
                    'description' => $row->description,
                    'match_type' => $matchType,
                ];

                if ($includeVieFund && isset($row->viefund_id)) {
                    $metadata['viefund_id'] = $row->viefund_id;
                }

                $batch[] = [
                    'left_type' => 'bank',
                    'left_id' => $row->bank_id,
                    'right_type' => 'fundserv',
                    'right_id' => $row->fundserv_id,
                    'match_rule' => $rule,
                    'confidence' => $confidence,
                    'matched_amount' => $row->amount,
                    'status' => 'matched',
                    'metadata' => json_encode($metadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= $batchSize) {
                    if (!$dryRun) {
                        ReconciliationMatch::insert($batch);
                    }
                    $inserted += count($batch);
                    $batch = [];
                }
            }
        };

        $query->orderBy('b.id')->chunk(1000, function ($rows) use (&$currentBankId, &$currentRows, $processGroup) {
            foreach ($rows as $row) {
                if ($currentBankId !== null && $currentBankId !== $row->bank_id) {
                    $processGroup($currentRows);
                    $currentRows = [];
                }

                $currentBankId = $row->bank_id;
                $currentRows[] = $row;
            }
        });

        $processGroup($currentRows);

        if (count($batch) > 0) {
            if (!$dryRun) {
                ReconciliationMatch::insert($batch);
            }
            $inserted += count($batch);
        }

        return $inserted;
    }

    private function insertBankVieFundMatches($query, string $rule, bool $dryRun = false): int
    {
        $inserted = 0;
        $batch = [];
        $batchSize = 500;

        $currentBankId = null;
        $currentRows = [];

        $processGroup = function (array $rows) use (&$inserted, &$batch, $batchSize, $rule, $dryRun) {
            if (count($rows) === 0) {
                return;
            }

            $scored = [];
            $sumScores = 0;

            foreach ($rows as $row) {
                [$score, $matchType] = $this->scoreBankVieFundMatch($row->txn_date, $row->settlement_date, $row->trade_date, $row->processing_date);
                $scored[] = [$row, $score, $matchType];
                $sumScores += $score;
            }

            foreach ($scored as [$row, $score, $matchType]) {
                $confidence = $sumScores > 0 ? $score / $sumScores : 0;

                $batch[] = [
                    'left_type' => 'bank',
                    'left_id' => $row->bank_id,
                    'right_type' => 'viefund',
                    'right_id' => $row->viefund_id,
                    'match_rule' => $rule,
                    'confidence' => $confidence,
                    'matched_amount' => $row->amount,
                    'status' => 'matched',
                    'metadata' => json_encode([
                        'txn_date' => $row->txn_date,
                        'settlement_date' => $row->settlement_date,
                        'trade_date' => $row->trade_date,
                        'processing_date' => $row->processing_date,
                        'description' => $row->description,
                        'match_type' => $matchType,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= $batchSize) {
                    if (!$dryRun) {
                        ReconciliationMatch::insert($batch);
                    }
                    $inserted += count($batch);
                    $batch = [];
                }
            }
        };

        $query->orderBy('b.id')->chunk(1000, function ($rows) use (&$currentBankId, &$currentRows, $processGroup) {
            foreach ($rows as $row) {
                if ($currentBankId !== null && $currentBankId !== $row->bank_id) {
                    $processGroup($currentRows);
                    $currentRows = [];
                }

                $currentBankId = $row->bank_id;
                $currentRows[] = $row;
            }
        });

        $processGroup($currentRows);

        if (count($batch) > 0) {
            if (!$dryRun) {
                ReconciliationMatch::insert($batch);
            }
            $inserted += count($batch);
        }

        return $inserted;
    }

    private function scoreBankFundservMatch(?string $bankDate, ?string $settlementDate, ?string $tradeDate): array
    {
        $bank = $bankDate ? Carbon::parse($bankDate) : null;
        $settlement = $settlementDate ? Carbon::parse($settlementDate) : null;
        $trade = $tradeDate ? Carbon::parse($tradeDate) : null;

        if ($bank && $settlement && $bank->isSameDay($settlement)) {
            return [1.0, 'settlement_exact'];
        }

        if ($bank && $trade && $bank->isSameDay($trade)) {
            return [0.9, 'trade_exact'];
        }

        if ($bank && $settlement && $bank->diffInDays($settlement) <= 1) {
            return [0.7, 'settlement_near'];
        }

        if ($bank && $trade && $bank->diffInDays($trade) <= 1) {
            return [0.6, 'trade_near'];
        }

        return [0.4, 'amount_only'];
    }

    private function scoreBankVieFundMatch(?string $bankDate, ?string $settlementDate, ?string $tradeDate, ?string $processingDate): array
    {
        $bank = $bankDate ? Carbon::parse($bankDate) : null;
        $settlement = $settlementDate ? Carbon::parse($settlementDate) : null;
        $trade = $tradeDate ? Carbon::parse($tradeDate) : null;
        $processing = $processingDate ? Carbon::parse($processingDate) : null;

        if ($bank && $settlement && $bank->isSameDay($settlement)) {
            return [1.0, 'settlement_exact'];
        }

        if ($bank && $trade && $bank->isSameDay($trade)) {
            return [0.9, 'trade_exact'];
        }

        if ($bank && $processing && $bank->isSameDay($processing)) {
            return [0.8, 'processing_exact'];
        }

        if ($bank && $settlement && $bank->diffInDays($settlement) <= 1) {
            return [0.7, 'settlement_near'];
        }

        if ($bank && $trade && $bank->diffInDays($trade) <= 1) {
            return [0.6, 'trade_near'];
        }

        if ($bank && $processing && $bank->diffInDays($processing) <= 1) {
            return [0.5, 'processing_near'];
        }

        return [0.4, 'amount_only'];
    }
}
