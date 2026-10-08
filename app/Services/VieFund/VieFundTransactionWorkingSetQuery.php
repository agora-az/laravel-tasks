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

    /** @return array<string, array<string, int|float>> */
    public function matchStatusSummary(
        VieFundTransactionWorkingSet $workingSet,
        ?string $search,
        array $filters
    ): array {
        $statuses = ['Complete', 'Verify', 'Possible', 'Unknown'];
        $summary = [];
        $seen = [];
        foreach ($statuses as $status) {
            $summary[$status] = [
                'viefund_count' => 0,
                'viefund_total' => 0.0,
                'eft_count' => 0,
                'eft_total' => 0.0,
                'fsp_count' => 0,
                'fsp_total' => 0.0,
                'bank_count' => 0,
                'bank_total' => 0.0,
                'variance' => 0.0,
            ];
            $seen[$status] = ['eft' => [], 'eft_total' => [], 'fsp' => [], 'fsp_total' => [], 'bank' => []];
        }

        $summaryFilters = array_merge($filters, ['match_statuses' => null]);
        $lastCashTransactionId = 0;
        do {
            $rows = $this->baseQuery($workingSet, $search, $summaryFilters)
                ->where('cash_transaction_id', '>', $lastCashTransactionId)
                ->orderBy('cash_transaction_id')
                ->limit(1000)
                ->get(['cash_transaction_id', 'amount', 'match_status', 'enrichment']);

            foreach ($rows as $row) {
                $lastCashTransactionId = (int) $row->cash_transaction_id;
                $status = in_array($row->match_status, $statuses, true) ? $row->match_status : 'Unknown';
                ++$summary[$status]['viefund_count'];
                $summary[$status]['viefund_total'] += (float) ($row->amount ?? 0);

                if ($status === 'Unknown') {
                    continue;
                }

                $enrichment = json_decode((string) ($row->enrichment ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
                foreach ((array) ($enrichment['eft_items'] ?? []) as $record) {
                    $this->addSummaryRecord($summary[$status], $seen[$status]['eft'], 'eft', $record, fn(): float => 0.0);
                    $hasFileTotal = array_key_exists('file_total', $record) && $record['file_total'] !== null;
                    $amount = (float) ($hasFileTotal ? $record['file_total'] : ($record['amount'] ?? 0));
                    $signedAmount = (int) ($record['type_id'] ?? 0) === 10 ? $amount : -$amount;
                    $totalKey = $hasFileTotal
                        ? 'file:' . (string) ($record['file_id'] ?? $record['sequence_number'] ?? $record['id'] ?? '')
                        : 'item:' . (string) ($record['id'] ?? sha1(json_encode($record, JSON_THROW_ON_ERROR)));
                    $this->addSummaryTotal(
                        $summary[$status],
                        $seen[$status]['eft_total'],
                        'eft_total',
                        $totalKey,
                        $signedAmount
                    );
                }
                foreach ((array) ($enrichment['fsp_items'] ?? []) as $record) {
                    $this->addSummaryRecord($summary[$status], $seen[$status]['fsp'], 'fsp', $record, fn(): float => 0.0);
                }
                $fspBankEntries = (array) ($enrichment['fsp_bank_entries'] ?? []);
                foreach ($fspBankEntries as $record) {
                    $recordKey = (string) ($record['id'] ?? sha1(json_encode($record, JSON_THROW_ON_ERROR)));
                    $this->addSummaryTotal(
                        $summary[$status],
                        $seen[$status]['fsp_total'],
                        'fsp_total',
                        $recordKey,
                        (float) ($record['fsp_net_total'] ?? 0)
                    );
                }
                foreach (array_merge((array) ($enrichment['eft_bank_entries'] ?? []), $fspBankEntries) as $record) {
                    $this->addSummaryRecord($summary[$status], $seen[$status]['bank'], 'bank', $record, function (array $entry): float {
                        $amount = (float) ($entry['amount'] ?? 0);

                        return strtoupper(trim((string) ($entry['credit_debit_indicator'] ?? ''))) === 'DBIT'
                            ? -$amount
                            : $amount;
                    });
                }
            }
        } while ($rows->count() === 1000);

        foreach ($statuses as $status) {
            $summary[$status]['variance'] = $summary[$status]['bank_total']
                - $summary[$status]['eft_total']
                - $summary[$status]['fsp_total'];
            foreach (['viefund_total', 'eft_total', 'fsp_total', 'bank_total', 'variance'] as $field) {
                $summary[$status][$field] = round((float) $summary[$status][$field], 2);
                if (abs($summary[$status][$field]) < 0.005) {
                    $summary[$status][$field] = 0.0;
                }
            }
        }

        return $summary;
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

        return $query->get(['payload', 'match_status', 'enrichment'])->map(function ($cachedRow) {
            $row = json_decode((string) $cachedRow->payload, false, 512, JSON_THROW_ON_ERROR);
            $row->cached_match_status = $cachedRow->match_status;
            $row->cached_enrichment = json_decode(
                (string) ($cachedRow->enrichment ?? '{}'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

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

    /** @param array<string, int|float> $summary @param array<string, true> $seen */
    private function addSummaryRecord(
        array &$summary,
        array &$seen,
        string $type,
        array $record,
        callable $amount
    ): void {
        $recordKey = isset($record['id'])
            ? (string) $record['id']
            : sha1(json_encode($record, JSON_THROW_ON_ERROR));
        if (isset($seen[$recordKey])) {
            return;
        }

        $seen[$recordKey] = true;
        ++$summary[$type . '_count'];
        $summary[$type . '_total'] += $amount($record);
    }

    /** @param array<string, int|float> $summary @param array<string, true> $seen */
    private function addSummaryTotal(
        array &$summary,
        array &$seen,
        string $field,
        string $recordKey,
        float $amount
    ): void {
        if (isset($seen[$recordKey])) {
            return;
        }

        $seen[$recordKey] = true;
        $summary[$field] += $amount;
    }
}
