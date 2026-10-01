<?php

namespace App\Services\Reconciliation;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgraFspBankMatcher
{
    private const PARSER_VERSION = 'v2';

    public function __construct(
        private readonly FspMatchedRecordCollapser $fspRecordCollapser
    ) {}

    /**
     * Match the complete FSP settlement batch behind the supplied items to a
     * Fundserv-labelled bank entry. The relationship is batch-level: every
     * item sharing the settlement date and currency receives the same match.
     *
     * @return Collection<string, object> keyed by YYYY-MM-DD|CURRENCY
     */
    public function matchesForItems(Collection $items, string $sourceType = 'fundserv_agra'): Collection
    {
        $pairs = $items
            ->map(function ($item) use ($sourceType): ?array {
                $date = data_get($item, 'settlement_date');
                $currency = strtoupper(trim((string) data_get($item, 'currency')));
                $settlementSource = strtoupper(trim((string) data_get($item, 'settlement_source')));
                if (
                    !$date
                    || $currency === ''
                    || ($sourceType === 'fundserv_agra' && $settlementSource !== 'I')
                ) {
                    return null;
                }

                return [
                    'total_date' => Carbon::parse($date)->toDateString(),
                    'currency' => $currency,
                ];
            })
            ->filter()
            ->unique(fn(array $pair) => $this->key($pair['total_date'], $pair['currency']))
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $pairKeys = $pairs
            ->map(fn(array $pair) => $this->key($pair['total_date'], $pair['currency']))
            ->flip();
        $deduplicated = $this->fspRecordCollapser
            ->deduplicatedQuery($sourceType)
            ->when($sourceType === 'fundserv_agra', fn($query) => $query->where('settlement_source', 'I'))
            ->whereIn('settlement_date', $pairs->pluck('total_date')->unique()->all())
            ->whereIn('currency', $pairs->pluck('currency')->unique()->all());
        $groups = $this->groupsFromDeduplicatedQuery($deduplicated)
            ->filter(fn($group) => $pairKeys->has($this->key($group->total_date, $group->currency)))
            ->values();

        return $this->matchGroups($groups, $sourceType === 'fundserv_agra');
    }

    /**
     * Return reconciliation totals after collapsing exact FSP redeliveries.
     */
    public function groupsForDateRange(string $dateFrom, string $dateTo, string $sourceType): Collection
    {
        $deduplicated = $this->fspRecordCollapser
            ->deduplicatedQuery($sourceType)
            ->when($sourceType === 'fundserv_agra', fn($query) => $query->where('settlement_source', 'I'))
            ->whereBetween('settlement_date', [$dateFrom, $dateTo]);

        return $this->groupsFromDeduplicatedQuery($deduplicated);
    }

    private function groupsFromDeduplicatedQuery(Builder $deduplicated): Collection
    {
        return DB::query()
            ->fromSub($deduplicated, 'fsp')
            ->selectRaw('settlement_date as total_date')
            ->selectRaw('currency')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw("SUM(CASE WHEN side = 'SELL' THEN COALESCE(settlement_amount, 0) WHEN side = 'BUY' THEN -COALESCE(settlement_amount, 0) ELSE 0 END) as net_total")
            ->groupBy('settlement_date', 'currency')
            ->get();
    }

    /**
     * @param Collection<int, object|array> $groups
     * @return Collection<string, object> keyed by YYYY-MM-DD|CURRENCY
     */
    public function matchGroups(Collection $groups, bool $allowWireFeeMatch = true): Collection
    {
        $groups = $groups
            ->map(function ($group): object {
                return (object) [
                    'total_date' => Carbon::parse(data_get($group, 'total_date'))->toDateString(),
                    'currency' => strtoupper(trim((string) data_get($group, 'currency'))),
                    'item_count' => (int) data_get($group, 'item_count', 0),
                    'net_total' => (float) data_get($group, 'net_total', 0),
                ];
            })
            ->filter(fn(object $group) => $group->currency !== '')
            ->values();

        if ($groups->isEmpty()) {
            return collect();
        }

        $bankByKey = DB::table('bank_statement_entries as b')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'b.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereIn('b.value_date', $groups->pluck('total_date')->unique()->all())
            ->whereIn('b.currency', $groups->pluck('currency')->unique()->all())
            ->where('a.counterparty', 'like', 'fundserv%')
            ->select([
                'b.id',
                'b.source_file',
                'b.account_number',
                'b.booking_date',
                'b.value_date',
                'b.credit_debit_indicator',
                'b.currency',
                'b.amount',
                'b.additional_info',
                'a.memo_type',
                'a.settlement_number',
                'a.counterparty',
                'a.wire_payment_reference',
            ])
            ->get()
            ->groupBy(fn($entry) => $this->key($entry->value_date, $entry->currency));

        return $groups->mapWithKeys(function (object $group) use ($bankByKey, $allowWireFeeMatch): array {
            $key = $this->key($group->total_date, $group->currency);
            $bank = collect($bankByKey->get($key, collect()))
                ->sort(function ($left, $right) use ($group): int {
                    $leftDifference = abs($this->signedBankAmount($left) - $group->net_total);
                    $rightDifference = abs($this->signedBankAmount($right) - $group->net_total);

                    return ($leftDifference <=> $rightDifference)
                        ?: ((int) $left->id <=> (int) $right->id);
                })
                ->first();
            if (!$bank) {
                return [];
            }

            $bank->fsp_item_count = $group->item_count;
            $bank->fsp_net_total = $group->net_total;
            $bank->bank_net_total = $this->signedBankAmount($bank);
            $bank->variance = $bank->bank_net_total - $group->net_total;
            $bank->is_exact_match = abs($bank->variance) < .01;
            $bank->is_possible_wire_fee_match = $allowWireFeeMatch
                && abs(abs($bank->variance) - 15.0) < .01;
            $bank->is_match = $bank->is_exact_match || $bank->is_possible_wire_fee_match;
            $bank->reconciliation_status = $bank->is_exact_match
                ? 'Exact match'
                : ($bank->is_possible_wire_fee_match ? 'Possible wire-fee match' : 'Unresolved');
            $bank->reconciliation_note = $bank->is_possible_wire_fee_match
                ? 'Matched — possible missing $15 wire transfer fee.'
                : '';

            return [$key => $bank];
        });
    }

    public function key(mixed $date, mixed $currency): string
    {
        return Carbon::parse($date)->toDateString().'|'.strtoupper(trim((string) $currency));
    }

    private function signedBankAmount(object $entry): float
    {
        $amount = (float) ($entry->amount ?? 0);

        return strtoupper(trim((string) ($entry->credit_debit_indicator ?? ''))) === 'DBIT'
            ? -$amount
            : $amount;
    }
}
