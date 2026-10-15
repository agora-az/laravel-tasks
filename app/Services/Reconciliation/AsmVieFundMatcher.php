<?php

namespace App\Services\Reconciliation;

use App\Models\AsmSettlementTransaction;
use App\Models\AsmVieFundMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AsmVieFundMatcher
{
    public const EXACT = 'exact_cash_match';

    public const ACCOUNT_ONLY = 'account_only';

    public const ACCOUNT_UNRESOLVED = 'account_unresolved';

    /** @return array<string, int> */
    public function rebuild(): array
    {
        $previousCashTransactionIds = AsmVieFundMatch::query()
            ->whereNotNull('viefund_cash_transaction_id')
            ->pluck('viefund_cash_transaction_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
        $transactions = AsmSettlementTransaction::query()
            ->orderBy('id')
            ->get();
        $accounts = $transactions
            ->map(fn(AsmSettlementTransaction $transaction) => $this->agraAccount($transaction))
            ->filter()
            ->unique()
            ->values();

        $remote = DB::connection('viefund_sqlsrv');
        $plans = $accounts
            ->chunk(1000)
            ->flatMap(fn(Collection $chunk) => $remote
                ->table('dbo.UB_Plan')
                ->whereIn('DealerAccountID', $chunk->all())
                ->get(['ID', 'DealerAccountID', 'Currency']))
            ->groupBy(fn(object $plan) => trim((string) $plan->DealerAccountID));
        $planIds = $plans
            ->flatten(1)
            ->pluck('ID')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
        $dateFrom = $transactions->min('settlement_date');
        $dateTo = $transactions->max('settlement_date');
        $cashTransactions = collect();

        if ($planIds->isNotEmpty() && $dateFrom && $dateTo) {
            $cashTransactions = $planIds
                ->chunk(1000)
                ->flatMap(fn(Collection $chunk) => $remote
                    ->table('dbo.UB_CashTrx')
                    ->whereIn('iPlanID', $chunk->all())
                    ->where('iStatus', 6)
                    ->whereBetween('dtSettlement', [
                        Carbon::parse($dateFrom)->startOfDay(),
                        Carbon::parse($dateTo)->endOfDay(),
                    ])
                    ->get([
                        'ID',
                        'iPlanID',
                        'iCashAccountID',
                        'iType',
                        'Type',
                        'TypeDetail',
                        'iStatus',
                        'dtTrade',
                        'dtProcessing',
                        'dtSettlement',
                        'mAmount',
                        'SourceID',
                        'dtCreated',
                    ]))
                ->groupBy(fn(object $cash) => (string) (int) $cash->iPlanID);
        }

        $now = now();
        $rows = [];
        $counts = [
            self::EXACT => 0,
            self::ACCOUNT_ONLY => 0,
            self::ACCOUNT_UNRESOLVED => 0,
        ];

        foreach ($transactions as $transaction) {
            $account = $this->agraAccount($transaction);
            $matchingPlans = $account ? collect($plans->get($account, collect())) : collect();
            $matchingPlans = $this->plansForCurrency($matchingPlans, $transaction->currency);

            if ($matchingPlans->isEmpty()) {
                $rows[] = $this->mappingRow(
                    $transaction,
                    $account,
                    null,
                    null,
                    self::ACCOUNT_UNRESOLVED,
                    'agra_account_not_found',
                    0,
                    [],
                    $now
                );
                ++$counts[self::ACCOUNT_UNRESOLVED];
                continue;
            }

            $exactMatches = $matchingPlans->flatMap(function (object $plan) use ($cashTransactions, $transaction) {
                return collect($cashTransactions->get((string) (int) $plan->ID, collect()))
                    ->filter(fn(object $cash) => $this->isExactCashMatch($transaction, $cash))
                    ->map(fn(object $cash) => ['plan' => $plan, 'cash' => $cash]);
            })->values();

            if ($exactMatches->isNotEmpty()) {
                foreach ($exactMatches as $match) {
                    $cash = $match['cash'];
                    $rows[] = $this->mappingRow(
                        $transaction,
                        $account,
                        (int) $match['plan']->ID,
                        (int) $cash->ID,
                        self::EXACT,
                        'account_settlement_date_currency_signed_amount_confirmed',
                        1,
                        [
                            'cash_status' => (int) $cash->iStatus,
                            'cash_type_id' => (int) $cash->iType,
                            'cash_type' => trim((string) $cash->Type),
                            'cash_type_detail' => trim((string) $cash->TypeDetail),
                            'cash_source_id' => trim((string) $cash->SourceID),
                        ],
                        $now
                    );
                }
                ++$counts[self::EXACT];
                continue;
            }

            foreach ($matchingPlans as $plan) {
                $rows[] = $this->mappingRow(
                    $transaction,
                    $account,
                    (int) $plan->ID,
                    null,
                    self::ACCOUNT_ONLY,
                    'agra_account_currency',
                    0.5,
                    [],
                    $now
                );
            }
            ++$counts[self::ACCOUNT_ONLY];
        }

        DB::transaction(function () use ($rows): void {
            AsmVieFundMatch::query()->delete();
            collect($rows)->chunk(500)->each(
                fn(Collection $chunk) => DB::table('asm_viefund_matches')->insert($chunk->all())
            );
        });

        $currentCashTransactionIds = collect($rows)
            ->pluck('viefund_cash_transaction_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
        $this->refreshWorkingSetRows(
            $previousCashTransactionIds->merge($currentCashTransactionIds)->unique()->values(),
            $currentCashTransactionIds
        );

        return $counts + ['total' => $transactions->count()];
    }

    /** @return Collection<string, Collection<int, array<string, mixed>>> */
    public function recordsForCashTransactionIds(iterable $cashTransactionIds): Collection
    {
        $ids = collect($cashTransactionIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return AsmVieFundMatch::query()
            ->with('transaction')
            ->where('match_status', self::EXACT)
            ->whereIn('viefund_cash_transaction_id', $ids)
            ->get()
            ->map(function (AsmVieFundMatch $match): array {
                $transaction = $match->transaction;

                return [
                    'id' => $transaction->id,
                    'match_status' => $match->match_status,
                    'match_rule' => $match->match_rule,
                    'confidence' => (float) $match->confidence,
                    'payment_id' => $transaction->payment_id,
                    'payment_type' => $transaction->payment_type,
                    'payment_type_description' => $transaction->payment_type_description,
                    'settlement_date' => optional($transaction->settlement_date)->toDateString(),
                    'currency' => $transaction->currency,
                    'settlement_amount' => (float) $transaction->settlement_amount,
                    'agra_role' => $transaction->sender_company_code === 'AGRA' ? 'Sender' : 'Receiver',
                    'agra_account' => $match->dealer_account_id,
                    'sender_company_code' => $transaction->sender_company_code,
                    'receiver_company_code' => $transaction->receiver_company_code,
                    'source_file' => $transaction->source_file,
                    'url' => route('asm-settlements.index', ['search' => $transaction->payment_id]),
                    '_cash_transaction_id' => (int) $match->viefund_cash_transaction_id,
                ];
            })
            ->groupBy(fn(array $record) => (string) $record['_cash_transaction_id'])
            ->map(fn(Collection $records) => $records
                ->map(function (array $record): array {
                    unset($record['_cash_transaction_id']);

                    return $record;
                })
                ->values());
    }

    private function agraAccount(AsmSettlementTransaction $transaction): ?string
    {
        $value = null;
        if ($transaction->sender_company_code === 'AGRA') {
            $value = $transaction->sender_account_number;
        } elseif ($transaction->receiver_company_code === 'AGRA') {
            $value = $transaction->receiver_dealer_account_number
                ?: ($transaction->receiver_fund_company_account_number
                    ?: $transaction->receiver_intermediary_account_number);
        }

        $value = trim((string) $value);

        return $value !== '' && strtoupper($value) !== 'AGRA' ? $value : null;
    }

    private function plansForCurrency(Collection $plans, ?string $currency): Collection
    {
        $currencyCode = match (strtoupper(trim((string) $currency))) {
            'CAD' => '00',
            'USD' => '01',
            default => null,
        };
        if ($currencyCode === null) {
            return $plans->values();
        }

        $matching = $plans->filter(
            fn(object $plan) => trim((string) $plan->Currency) === $currencyCode
        )->values();

        return $matching->isNotEmpty() ? $matching : $plans->values();
    }

    private function isExactCashMatch(AsmSettlementTransaction $transaction, object $cash): bool
    {
        return $transaction->settlement_date
            && Carbon::parse($cash->dtSettlement)->isSameDay($transaction->settlement_date)
            && abs((float) $cash->mAmount - (float) $transaction->settlement_amount) < 0.005;
    }

    private function mappingRow(
        AsmSettlementTransaction $transaction,
        ?string $account,
        ?int $planId,
        ?int $cashTransactionId,
        string $status,
        string $rule,
        float $confidence,
        array $metadata,
        Carbon $now
    ): array {
        return [
            'asm_settlement_transaction_id' => $transaction->id,
            'viefund_plan_id' => $planId,
            'viefund_cash_transaction_id' => $cashTransactionId,
            'dealer_account_id' => $account,
            'match_status' => $status,
            'match_rule' => $rule,
            'confidence' => $confidence,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function refreshWorkingSetRows(
        Collection $affectedCashTransactionIds,
        Collection $currentCashTransactionIds
    ): void {
        if ($affectedCashTransactionIds->isEmpty()) {
            return;
        }

        $recordsByCash = $this->recordsForCashTransactionIds($currentCashTransactionIds);
        $affectedCashTransactionIds->chunk(500)->each(function (Collection $cashIds) use ($recordsByCash): void {
            DB::table('viefund_transaction_working_set_rows')
                ->whereIn('cash_transaction_id', $cashIds->all())
                ->get(['working_set_id', 'generation', 'cash_transaction_id', 'match_status', 'enrichment'])
                ->each(function (object $row) use ($recordsByCash): void {
                    $cashId = (string) (int) $row->cash_transaction_id;
                    $records = collect($recordsByCash->get($cashId, collect()))->values()->all();
                    $enrichment = json_decode(
                        (string) ($row->enrichment ?? '{}'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $enrichment['asm_items'] = $records;
                    $matchStatus = $row->match_status;
                    if ($records !== [] && $matchStatus === EftBankMatchStatusService::UNKNOWN) {
                        $matchStatus = AllTransactionMatchStatusResolver::ASM_ONLY;
                    } elseif ($records === [] && $matchStatus === AllTransactionMatchStatusResolver::ASM_ONLY) {
                        $matchStatus = EftBankMatchStatusService::UNKNOWN;
                    }

                    DB::table('viefund_transaction_working_set_rows')
                        ->where('working_set_id', $row->working_set_id)
                        ->where('generation', $row->generation)
                        ->where('cash_transaction_id', $row->cash_transaction_id)
                        ->update([
                            'match_status' => $matchStatus,
                            'has_asm_match' => $records !== [],
                            'enrichment' => json_encode($enrichment, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                });
        });
    }
}
