<?php

namespace App\Services\VieFund;

use App\Support\BoundedQueryCount;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VieFundTransactionMirrorQuery
{
    /**
     * @return list<string>
     */
    public function availableTransactionTypes(): array
    {
        return DB::table('viefund_transaction_mirror_rows')
            ->whereNotNull('transaction_type')
            ->where('transaction_type', '<>', '')
            ->distinct()
            ->orderBy('transaction_type')
            ->pluck('transaction_type')
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /** @return array<string, string|null> */
    public function inceptionDates(string $currencyCode): array
    {
        $row = DB::table('viefund_transaction_mirror_rows')
            ->where('currency_code', $currencyCode)
            ->selectRaw(
                'MIN(created_date) as create_date, MIN(trade_date) as trade_date, '
                . 'MIN(processing_date) as processing_date, MIN(settlement_date) as settlement_date'
            )
            ->first();

        return [
            'create_date' => $row?->create_date,
            'trade_date' => $row?->trade_date,
            'processing_date' => $row?->processing_date,
            'settlement_date' => $row?->settlement_date,
        ];
    }

    public function paginate(int $perPage, int $page, ?string $search, array $filters): Paginator
    {
        $query = $this->baseQuery($search, $filters);
        $dateColumn = $this->dateColumn($filters);
        $sortColumns = [
            'created_date' => 'created_date',
            'trade_date' => 'trade_date',
            'processing_date' => 'processing_date',
            'settlement_date' => 'settlement_date',
            'amount' => 'amount',
        ];
        $sort = $sortColumns[$filters['sort'] ?? ''] ?? $dateColumn;
        $direction = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $paginator = $query
            ->orderBy($sort, $direction)
            ->orderBy('cash_transaction_id', $direction)
            ->simplePaginate($perPage, ['payload', 'match_status', 'fund_id', 'fund_transaction_id', 'source_id', 'transaction_type'], 'page', $page);
        $paginator->setCollection($paginator->getCollection()->map(function (object $cachedRow) use ($filters): object {
            $row = json_decode((string) $cachedRow->payload, false, 512, JSON_THROW_ON_ERROR);
            $this->applyFundMetadata($row, $cachedRow, $filters);
            $row->cached_match_status = $cachedRow->match_status;
            $row->match_status = $cachedRow->match_status;

            return $row;
        }));

        return $paginator;
    }

    public function count(?string $search, array $filters, bool $bounded = false): int
    {
        $query = $this->baseQuery($search, $filters);

        return $bounded ? BoundedQueryCount::run($query) : $query->count();
    }

    public function dailyStats(?string $search, array $filters): Collection
    {
        $dateColumn = $this->dateColumn($filters);

        return $this->baseQuery($search, $filters)
            ->selectRaw("DATE({$dateColumn}) AS transaction_date, COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS net_amount")
            ->groupByRaw("DATE({$dateColumn})")
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * @return array{opening_balance: float, period_net: float, closing_balance: float, transaction_count: int}
     */
    public function periodSummary(array $filters): array
    {
        $dateColumn = $this->dateColumn($filters);
        $fromDate = (string) ($filters['date_from'] ?? '');
        $unboundedFilters = $filters;
        unset($unboundedFilters['date_from'], $unboundedFilters['date_to']);

        $openingBalance = (float) $this->baseQuery(null, $unboundedFilters)
            ->when($fromDate !== '', fn (Builder $query) => $query->where($dateColumn, '<', $fromDate))
            ->sum('amount');
        $period = $this->baseQuery(null, $filters)
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as period_net')
            ->first();
        $periodNet = (float) ($period->period_net ?? 0);

        return [
            'opening_balance' => $openingBalance,
            'period_net' => $periodNet,
            'closing_balance' => $openingBalance + $periodNet,
            'transaction_count' => (int) ($period->transaction_count ?? 0),
        ];
    }

    /** @return Collection<int, \stdClass> */
    public function summaryRowsAfter(?string $search, array $filters, int $cursor, int $limit): Collection
    {
        return $this->baseQuery($search, array_merge($filters, ['match_statuses' => null]))
            ->where('cash_transaction_id', '>', $cursor)
            ->orderBy('cash_transaction_id')
            ->limit($limit)
            ->get(['cash_transaction_id', 'amount', 'match_status', 'enrichment']);
    }

    /** @return array<string, array<string, int|float>> */
    public function matchStatusSummary(?string $search, array $filters): array
    {
        $accumulator = new VieFundMatchStatusSummaryAccumulator();
        $lastCashTransactionId = 0;
        do {
            $rows = $this->summaryRowsAfter($search, $filters, $lastCashTransactionId, 1000);

            foreach ($rows as $row) {
                $lastCashTransactionId = (int) $row->cash_transaction_id;
                $accumulator->add($row);
            }
        } while ($rows->count() === 1000);

        return $accumulator->result();
    }

    public function exportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
    {
        $direction = ($filters['output_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $comparison = $direction === 'asc' ? '>' : '<';
        $dateColumn = $this->dateColumn($filters);
        $query = $this->baseQuery($search, $filters)
            ->orderBy($dateColumn, $direction)
            ->orderBy('cash_transaction_id', $direction)
            ->limit(max(1, $limit));

        if ($cursor !== null) {
            $basisDate = (string) ($cursor['basis_date'] ?? '');
            $cashTransactionId = (int) ($cursor['sort_id'] ?? 0);
            $query->where(function (Builder $after) use (
                $comparison,
                $dateColumn,
                $basisDate,
                $cashTransactionId
            ): void {
                $after->where($dateColumn, $comparison, $basisDate)
                    ->orWhere(function (Builder $sameDate) use (
                        $comparison,
                        $dateColumn,
                        $basisDate,
                        $cashTransactionId
                    ): void {
                        $sameDate->where($dateColumn, $basisDate)
                            ->where('cash_transaction_id', $comparison, $cashTransactionId);
                    });
            });
        }

        return $query->get([
            'payload',
            'match_status',
            'enrichment',
            'fund_id',
            'fund_transaction_id',
            'source_id',
            'transaction_type',
            "{$dateColumn} as export_cursor_basis_date",
            'cash_transaction_id as export_cursor_cash_transaction_id',
        ])->map(function (object $cachedRow) use ($filters): object {
            $row = json_decode((string) $cachedRow->payload, false, 512, JSON_THROW_ON_ERROR);
            $this->applyFundMetadata($row, $cachedRow, $filters);
            $row->cached_match_status = $cachedRow->match_status;
            $row->match_status = $cachedRow->match_status;
            $row->export_cursor_basis_date = (string) $cachedRow->export_cursor_basis_date;
            $row->export_cursor_cash_transaction_id = (int) $cachedRow->export_cursor_cash_transaction_id;
            $row->cached_enrichment = json_decode(
                (string) ($cachedRow->enrichment ?? '{}'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            return $row;
        });
    }

    private function baseQuery(?string $search, array $filters): Builder
    {
        $dateColumn = $this->dateColumn($filters);
        $query = $this->fundFilteredQuery($filters)
            ->whereNotNull($dateColumn)
            ->when(!empty($filters['date_from']), fn(Builder $builder) => $builder
                ->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00'))
            ->when(!empty($filters['date_to']), fn(Builder $builder) => $builder
                ->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59'))
            ->when(!empty($filters['currency_code']), fn(Builder $builder) => $builder
                ->where('currency_code', (string) $filters['currency_code']))
            ->when(!empty($filters['status_ids']), fn(Builder $builder) => $builder
                ->whereIn('cash_status_id', (array) $filters['status_ids']));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function (Builder $searchQuery) use ($like): void {
                $searchQuery
                    ->where('transaction_id', 'like', $like)
                    ->orWhere('source_id', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('plan_account_id', 'like', $like)
                    ->orWhere('transaction_type', 'like', $like)
                    ->orWhere('payload', 'like', $like);
            });
        }
        $this->applyContains($query, 'customer_name', $filters['customer_name'] ?? null);
        $this->applyContains($query, 'plan_account_id', $filters['plan_account_id'] ?? null);
        $this->applyTransactionIds($query, $filters['trx_id'] ?? null);
        $this->applyContains($query, 'source_id', $filters['source_id'] ?? null);

        if (!empty($filters['trx_type'])) {
            $query->whereIn('transaction_type', (array) $filters['trx_type']);
        }
        foreach ([
            'has_reconciliation_match' => 'has_eft_match',
            'has_agra_fsp_match' => 'has_agra_fsp_match',
            'has_7960_fsp_match' => 'has_7960_fsp_match',
            'has_asm_match' => 'has_asm_match',
        ] as $filter => $column) {
            if (!empty($filters[$filter])) {
                $query->where($column, true);
            }
        }
        if (!empty($filters['match_statuses'])) {
            $query->whereIn('match_status', (array) $filters['match_statuses']);
        }

        return $query;
    }

    private function fundFilteredQuery(array $filters): Builder
    {
        $fundId = trim((string) ($filters['fund_id'] ?? ''));
        if ($fundId === '') {
            return DB::table('viefund_transaction_mirror_rows');
        }

        $funds = DB::table('viefund_transaction_mirror_funds')
            ->where('fund_id', 'like', '%'.$fundId.'%')
            ->groupBy('cash_transaction_id')
            ->selectRaw('cash_transaction_id AS fund_cash_id, MIN(fund_id) AS fund_id, MIN(fund_transaction_id) AS fund_transaction_id')
            ->selectRaw('COALESCE(MIN(source_id), MIN(cash_source_id)) AS source_id')
            ->selectRaw('COALESCE(MIN(transaction_type), MIN(cash_transaction_type)) AS transaction_type');
        $rows = DB::table('viefund_transaction_mirror_rows as cash')
            ->joinSub($funds, 'fund', 'fund.fund_cash_id', '=', 'cash.cash_transaction_id')
            ->select(array_map(fn(string $column): string => 'cash.'.$column, [
                'cash_transaction_id', 'transaction_id', 'trust_transaction_id', 'ledger_relationship',
                'customer_name', 'plan_account_id', 'cash_status_id', 'cash_status', 'trust_status',
                'notes', 'created_date', 'trade_date', 'processing_date', 'settlement_date', 'currency_code',
                'amount', 'match_status', 'has_eft_match', 'has_agra_fsp_match', 'has_7960_fsp_match',
                'has_asm_match', 'payload', 'enrichment',
            ]))
            ->selectRaw('fund.fund_id, fund.fund_transaction_id')
            ->selectRaw('fund.source_id, fund.transaction_type');

        return DB::query()->fromSub($rows, 'viefund_transaction_mirror_rows');
    }

    private function applyFundMetadata(object $row, object $cachedRow, array $filters): void
    {
        if (trim((string) ($filters['fund_id'] ?? '')) === '') {
            return;
        }
        foreach (['fund_id', 'fund_transaction_id', 'source_id', 'transaction_type'] as $column) {
            $row->{$column} = $cachedRow->{$column};
        }
    }

    private function dateColumn(array $filters): string
    {
        return match ($filters['date_basis'] ?? 'settlement_date') {
            'create_date' => 'created_date',
            'trade_date' => 'trade_date',
            'processing_date' => 'processing_date',
            default => 'settlement_date',
        };
    }

    private function applyTransactionIds(Builder $query, mixed $value): void
    {
        $rawValue = trim((string) $value);
        $idsByPrefix = ['C' => [], 'F' => [], 'T' => []];
        foreach (explode(',', $rawValue) as $rawId) {
            if (!preg_match('/^([CFT])?-?(\d+)$/i', trim($rawId), $matches)) {
                continue;
            }

            $id = (int) $matches[2];
            foreach (!empty($matches[1]) ? [strtoupper($matches[1])] : ['C', 'F', 'T'] as $prefix) {
                $idsByPrefix[$prefix][] = $id;
            }
        }
        if (array_filter($idsByPrefix) === []) {
            if ($rawValue !== '') {
                $query->whereRaw('1 = 0');
            }
            return;
        }

        $query->where(function (Builder $ids) use ($idsByPrefix): void {
            if ($idsByPrefix['C'] !== []) {
                $ids->orWhereIn('cash_transaction_id', array_unique($idsByPrefix['C']));
            }
            if ($idsByPrefix['F'] !== []) {
                $ids->orWhereIn('fund_transaction_id', array_unique($idsByPrefix['F']));
            }
            if ($idsByPrefix['T'] !== []) {
                $ids->orWhereIn('trust_transaction_id', array_unique($idsByPrefix['T']));
            }
        });
    }

    private function applyContains(Builder $query, string $column, mixed $value): void
    {
        $value = trim((string) $value);
        if ($value !== '') {
            $query->where($column, 'like', '%' . $value . '%');
        }
    }
}
