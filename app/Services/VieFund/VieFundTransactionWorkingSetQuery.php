<?php

namespace App\Services\VieFund;

use App\Models\VieFundTransactionWorkingSet;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VieFundTransactionWorkingSetQuery
{
    public function paginate(
        VieFundTransactionWorkingSet $workingSet,
        int $perPage,
        int $page,
        ?string $search,
        array $filters
    ): LengthAwarePaginator {
        $query = $this->baseQuery($workingSet, $search, $filters);

        $sortColumns = [
            'created_date' => 'created_date',
            'trade_date' => 'trade_date',
            'processing_date' => 'processing_date',
            'settlement_date' => 'settlement_date',
            'amount' => 'amount',
        ];
        $sort = $sortColumns[$filters['sort'] ?? ''] ?? 'basis_date';
        $direction = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $paginator = $query
            ->orderBy($sort, $direction)
            ->orderBy('cash_transaction_id', $direction)
            ->paginate($perPage, ['payload', 'match_status'], 'page', $page);
        $paginator->setCollection($paginator->getCollection()->map(function ($cachedRow) {
            $row = json_decode((string) $cachedRow->payload, false, 512, JSON_THROW_ON_ERROR);
            $row->cached_match_status = $cachedRow->match_status;

            return $row;
        }));

        return $paginator;
    }

    public function count(VieFundTransactionWorkingSet $workingSet, ?string $search, array $filters): int
    {
        return $this->baseQuery($workingSet, $search, $filters)->count();
    }

    public function dailyStats(VieFundTransactionWorkingSet $workingSet, ?string $search, array $filters): Collection
    {
        return $this->baseQuery($workingSet, $search, $filters)
            ->selectRaw('DATE(basis_date) AS transaction_date, COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS net_amount')
            ->groupByRaw('DATE(basis_date)')
            ->orderBy('transaction_date')
            ->get();
    }

    public function exportRowsAfter(
        VieFundTransactionWorkingSet $workingSet,
        ?string $search,
        array $filters,
        ?array $cursor,
        int $limit
    ): Collection {
        $direction = ($filters['output_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $comparison = $direction === 'asc' ? '>' : '<';
        $query = $this->baseQuery($workingSet, $search, $filters)
            ->orderBy('basis_date', $direction)
            ->orderBy('cash_transaction_id', $direction)
            ->limit(max(1, $limit));

        if ($cursor !== null) {
            $basisDate = (string) ($cursor['basis_date'] ?? '');
            $cashTransactionId = (int) ($cursor['sort_id'] ?? 0);
            $query->where(function (Builder $after) use ($comparison, $basisDate, $cashTransactionId): void {
                $after->where('basis_date', $comparison, $basisDate)
                    ->orWhere(function (Builder $sameDate) use ($comparison, $basisDate, $cashTransactionId): void {
                        $sameDate->where('basis_date', $basisDate)
                            ->where('cash_transaction_id', $comparison, $cashTransactionId);
                    });
            });
        }

        return $query->get(['payload', 'match_status'])->map(function ($cachedRow) {
            $row = json_decode((string) $cachedRow->payload, false, 512, JSON_THROW_ON_ERROR);
            $row->cached_match_status = $cachedRow->match_status;

            return $row;
        });
    }

    private function baseQuery(
        VieFundTransactionWorkingSet $workingSet,
        ?string $search,
        array $filters
    ): Builder {
        $generation = $workingSet->readableGeneration();
        if ($generation === null) {
            throw new \LogicException('The VieFund transaction working set has no readable generation.');
        }

        $query = DB::table('viefund_transaction_working_set_rows')
            ->where('working_set_id', $workingSet->id)
            ->where('generation', $generation);

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
        if (!empty($filters['has_reconciliation_match'])) {
            $query->where('has_eft_match', true);
        }
        if (!empty($filters['has_agra_fsp_match'])) {
            $query->where('has_agra_fsp_match', true);
        }
        if (!empty($filters['has_7960_fsp_match'])) {
            $query->where('has_7960_fsp_match', true);
        }
        if (!empty($filters['match_statuses'])) {
            $query->whereIn('match_status', (array) $filters['match_statuses']);
        }

        return $query;
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
            $prefixes = !empty($matches[1]) ? [strtoupper($matches[1])] : ['C', 'F', 'T'];
            foreach ($prefixes as $prefix) {
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
