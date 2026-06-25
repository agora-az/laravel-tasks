<?php

namespace App\Services\VieFund\Repositories;

use App\Services\VieFund\Contracts\VieFundRemoteRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SqlServerVieFundRemoteRepository implements VieFundRemoteRepositoryInterface
{
    private const CONNECTION = 'viefund_sqlsrv';

    public function ping(): bool
    {
        DB::connection(self::CONNECTION)->select('SELECT 1 AS ok');

        return true;
    }

    public function listTables(?string $schema = null): Collection
    {
        $schemaName = $schema ?: env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table('INFORMATION_SCHEMA.TABLES')
            ->select(['TABLE_SCHEMA', 'TABLE_NAME'])
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->where('TABLE_SCHEMA', $schemaName)
            ->orderBy('TABLE_NAME')
            ->get();
    }

    public function fetchRows(string $table, int $limit = 100, ?string $schema = null): Collection
    {
        $safeTable = $this->validateIdentifier($table);
        $safeSchema = $this->validateIdentifier($schema ?: env('VIEFUND_DB_SCHEMA', 'dbo'));

        return DB::connection(self::CONNECTION)
            ->table($safeSchema . '.' . $safeTable)
            ->limit(max(1, min($limit, 5000)))
            ->get();
    }

    private function buildBaseQuery(string $schema): \Illuminate\Database\Query\Builder
    {
        $query = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_FundTrxLookup as l")
            ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
            ->join("{$schema}.UB_Plan as p", 'p.ID', '=', 'l.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->leftJoin("{$schema}.UB_Def_TrxType as tt", 'tt.ID', '=', 'l.iType')
            ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
            ->leftJoin("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
            ->leftJoin("{$schema}.UB_Def_TrxType as ctt", 'ctt.ID', '=', 'ct.iType')
            // Trust-link: when a UB_TrustTrx row has iTrxID pointing to this fund
            // transaction, pull its extra columns (Notes, mAmountUsed, mAmountLeft, etc.)
            // so they merge into this fund row instead of appearing as a separate row.
            ->leftJoin("{$schema}.UB_TrustTrx as trlink", 'trlink.iTrxID', '=', 'l.iTrxID');

        // Exclusions from config/viefund.php — each key maps to a SQL column alias
        $columnMap = [
            'trx_type'      => 'tt.NameEN',
            'cash_trx_type' => 'ctt.NameEN',
        ];
        foreach (config('viefund.exclusions', []) as $key => $values) {
            $values = array_values(array_filter((array) $values));
            if (!empty($values) && isset($columnMap[$key])) {
                $col = $columnMap[$key];
                $query->where(function ($q) use ($col, $values) {
                    $q->whereNotIn($col, $values)->orWhereNull($col);
                });
            }
        }

        if (config('viefund.hide_zero_amount', false)) {
            $query->whereNotNull('ct.mAmount')->where('ct.mAmount', '!=', 0);
        }

        return $query;
    }

    public function fetchTransactions(?string $search = null, array $filters = []): LengthAwarePaginator
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $validPerPage = [50, 100, 250];
        $perPage = in_array((int) request()->query('per_page', 250), $validPerPage)
            ? (int) request()->query('per_page', 250)
            : 50;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema);
        $this->applyFiltersAndSearch($fundBase, $search, $filters, $schema);
        $this->applyTrustFiltersAndSearch($trustBase, $search, $filters, $schema);

        $offset = ($page - 1) * $perPage;

        // Fetch fund and trust group keys with sort info separately, then merge and
        // paginate in PHP. This avoids a raw UNION ALL SQL string whose parameter
        // binding order can be mishandled by certain pdo_sqlsrv driver versions,
        // which caused the remote server to return far fewer rows than expected.
        $allFundUnits = (clone $fundBase)
            ->select([
                DB::raw('l.iTrxID AS group_key'),
                DB::raw("'fund' AS source_type"),
                DB::raw('MIN(t.dtCreated) AS sort_date'),
                DB::raw('MAX(p.DealerAccountID) AS plan_account_id'),
            ])
            ->groupBy('l.iTrxID')
            ->get();

        $allTrustUnits = (clone $trustBase)
            ->select([
                DB::raw('tr.ID AS group_key'),
                DB::raw("'trust' AS source_type"),
                DB::raw('tr.dtCreated AS sort_date'),
                DB::raw('p.DealerAccountID AS plan_account_id'),
            ])
            ->get();

        $total = $allFundUnits->count() + $allTrustUnits->count();

        if ($total === 0) {
            return new LengthAwarePaginator(collect(), 0, $perPage, $page, [
                'path'  => LengthAwarePaginator::resolveCurrentPath(),
                'query' => LengthAwarePaginator::resolveQueryString(),
            ]);
        }

        $pageUnits = $allFundUnits->concat($allTrustUnits)
            ->sortBy(fn($u) => [
                (string) ($u->plan_account_id ?? ''),
                (string) ($u->sort_date ?? '9999-12-31'),
                (int) $u->group_key,
            ])
            ->values()
            ->skip($offset)
            ->take($perPage);

        $fundIds  = $pageUnits->where('source_type', 'fund')->pluck('group_key')->map('intval')->toArray();
        $trustIds = $pageUnits->where('source_type', 'trust')->pluck('group_key')->map('intval')->toArray();

        $items = collect();

        if (!empty($fundIds)) {
            $items = $items->concat(
                (clone $fundBase)
                    ->whereIn('l.iTrxID', $fundIds)
                    ->select($this->fundSelectColumns())
                    ->orderBy('t.dtCreated', 'asc')
                    ->orderBy('l.OrderID', 'asc')
                    ->orderBy('t.SourceID', 'asc')
                    ->orderBy('ct.ID', 'asc')
                    ->get()
            );
        }

        if (!empty($trustIds)) {
            $items = $items->concat(
                (clone $trustBase)
                    ->whereIn('tr.ID', $trustIds)
                    ->select($this->trustSelectColumns())
                    ->orderBy('tr.dtCreated', 'asc')
                    ->orderBy('tr.ID', 'asc')
                    ->get()
            );
        }

        // Deduplicate: the fund query can produce multiple rows per (trx_id, cash_trx_id)
        // when UB_FundTrxLookup has multiple allocation rows for the same fund transaction
        // (e.g. one row per fund within the same plan). Keep only the first occurrence.
        $seenFundCash = [];
        $items = $items->filter(function ($item) use (&$seenFundCash) {
            if ($item->row_source !== 'fund') return true;
            $key = $item->trx_id . '-' . ($item->cash_trx_id ?? '');
            if (isset($seenFundCash[$key])) return false;
            $seenFundCash[$key] = true;
            return true;
        })->values();

        $items = $items->sortBy([['plan_dealer_account_id', 'asc'], ['created_date', 'asc'], ['trx_id', 'asc']])->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path'  => LengthAwarePaginator::resolveCurrentPath(),
            'query' => LengthAwarePaginator::resolveQueryString(),
        ]);
    }

    // ── Distinct type helpers ────────────────────────────────────────────────

    public function fetchDistinctTrxTypes(array $filters = []): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        $fundTypes = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_FundTrxLookup as l")
            ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
            ->join("{$schema}.UB_Plan as p", 'p.ID', '=', 'l.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->leftJoin("{$schema}.UB_Def_TrxType as tt", 'tt.ID', '=', 'l.iType')
            ->tap(fn($q) => $this->applyContextFilters($q, $filters))
            ->whereNotNull('tt.NameEN')
            ->select('tt.NameEN')
            ->distinct()
            ->pluck('tt.NameEN');

        $trustQuery = $this->buildTrustBaseQuery($schema);
        $this->applyTrustFiltersAndSearch($trustQuery, null, $filters, $schema);

        $trustTypeNames = (clone $trustQuery)
            ->whereNotNull('ttype.NameEN')
            ->selectRaw('ttype.NameEN AS type_name')
            ->distinct()
            ->pluck('type_name');

        $trustDepositTypeNames = (clone $trustQuery)
            ->whereRaw('ISNULL(tr.iDepositType, 0) > 0')
            ->whereNotNull('tdtype.NameEN')
            ->selectRaw('tdtype.NameEN AS type_name')
            ->distinct()
            ->pluck('type_name');

        $trustTypes = $trustTypeNames->concat($trustDepositTypeNames)->filter();

        return $fundTypes->concat($trustTypes)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    private function applyContextFilters($q, array $filters): void
    {
        if (!empty($filters['account_id'])) {
            $q->where('p.DealerAccountID', '=', $filters['account_id']);
        }
        if (!empty($filters['customer_id'])) {
            $q->where('c.ID', '=', $filters['customer_id']);
        }
        if (!empty($filters['created_from'])) {
            $q->where('t.dtCreated', '>=', $filters['created_from'] . ' 00:00:00');
        }
        if (!empty($filters['created_to'])) {
            $q->where('t.dtCreated', '<=', $filters['created_to'] . ' 23:59:59');
        }
    }

    public function getCustomerForPlanAccount(string $accountId): ?array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        $row = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan as p")
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->where('p.DealerAccountID', '=', $accountId)
            ->select(
                'c.ID as customer_id',
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, ''))) as customer_name")
            )
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'customer_id'   => $row->customer_id,
            'customer_name' => trim((string) $row->customer_name),
        ];
    }

    public function searchPlanAccounts(string $search): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan as p")
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->where('p.DealerAccountID', 'like', '%' . $search . '%')
            ->select(
                'p.DealerAccountID as account_id',
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, ''))) as customer_name")
            )
            ->distinct()
            ->orderBy('p.DealerAccountID')
            ->limit(15)
            ->get()
            ->map(fn($r) => ['account_id' => $r->account_id, 'customer_name' => trim((string) $r->customer_name)])
            ->toArray();
    }

    public function searchCustomers(string $search): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Customer as c")
            ->where(function ($q) use ($search) {
                $q->where('c.FirstName', 'like', '%' . $search . '%')
                    ->orWhere('c.LastName', 'like', '%' . $search . '%')
                    ->orWhereRaw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) LIKE ?", ['%' . $search . '%']);
            })
            ->select(
                'c.ID as id',
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, ''))) as name")
            )
            ->orderBy('c.LastName')
            ->orderBy('c.FirstName')
            ->limit(15)
            ->get()
            ->map(fn($r) => ['id' => $r->id, 'name' => trim((string) $r->name)])
            ->toArray();
    }

    private function applyFiltersAndSearch(\Illuminate\Database\Query\Builder $query, ?string $search, array $filters, string $schema): void
    {
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) LIKE ?", ["%{$search}%"])
                    ->orWhere('l.DealerRepCode', 'like', "%{$search}%")
                    ->orWhere('p.DealerAccountID', 'like', "%{$search}%")
                    ->orWhere('l.OrderID', 'like', "%{$search}%")
                    ->orWhere('tt.NameEN', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['trx_id'])) {
            $trxIds = array_values(array_filter(array_map('trim', explode(',', $filters['trx_id']))));
            if (count($trxIds) === 1) {
                $query->whereRaw("CAST(l.iTrxID AS NVARCHAR) LIKE ?", ['%' . $trxIds[0] . '%']);
            } else {
                $query->whereIn('l.iTrxID', array_map('intval', $trxIds));
            }
        }
        if (!empty($filters['source_id'])) {
            $sourceIds = array_values(array_filter(array_map('trim', explode(',', $filters['source_id']))));
            $query->where(function ($q) use ($sourceIds) {
                foreach ($sourceIds as $sid) {
                    $q->orWhere('t.SourceID', 'like', '%' . $sid . '%');
                }
            });
        }
        if (!empty($filters['plan_account_id'])) {
            $query->where('p.DealerAccountID', 'like', '%' . $filters['plan_account_id'] . '%');
        }
        if (!empty($filters['trx_type'])) {
            $query->whereIn('tt.NameEN', (array) $filters['trx_type']);
        }
        if (!empty($filters['direction'])) {
            $directions = array_intersect((array) $filters['direction'], ['debit', 'credit']);
            if (count($directions) === 1) {
                $isDebit = reset($directions) === 'debit';
                $query->whereIn('l.iTrxID', function ($sub) use ($schema, $isDebit) {
                    $sub->select('l2.iTrxID')
                        ->from("{$schema}.UB_FundTrxLookup as l2")
                        ->join("{$schema}.UB_FundTrxCash as fc2", 'fc2.iTrxID', '=', 'l2.iTrxID')
                        ->join("{$schema}.UB_CashTrx as ct2", 'ct2.ID', '=', 'fc2.iCashTrxID');
                    if ($isDebit) {
                        $sub->where('ct2.mAmount', '<', 0);
                    } else {
                        $sub->where('ct2.mAmount', '>=', 0);
                    }
                });
            }
        }
        if (!empty($filters['created_from'])) {
            $query->where('t.dtCreated', '>=', $filters['created_from'] . ' 00:00:00');
        }
        if (!empty($filters['account_id'])) {
            $query->where('p.DealerAccountID', '=', $filters['account_id']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('c.ID', '=', $filters['customer_id']);
        }
        if (!empty($filters['created_to'])) {
            $query->where('t.dtCreated', '<=', $filters['created_to'] . ' 23:59:59');
        }
    }

    public function exportTransactions(?string $search = null, array $filters = []): Collection
    {
        $schema     = env('VIEFUND_DB_SCHEMA', 'dbo');
        $fundQuery  = $this->buildBaseQuery($schema);
        $trustQuery = $this->buildTrustBaseQuery($schema);
        $this->applyFiltersAndSearch($fundQuery, $search, $filters, $schema);
        $this->applyTrustFiltersAndSearch($trustQuery, $search, $filters, $schema);

        $fundRows = $fundQuery
            ->select($this->fundSelectColumns())
            ->orderBy('t.dtCreated', 'asc')
            ->orderBy('l.iTrxID', 'asc')
            ->orderBy('ct.ID', 'asc')
            ->get();

        $trustRows = $trustQuery
            ->select($this->trustSelectColumns())
            ->orderBy('tr.dtCreated', 'asc')
            ->orderBy('tr.ID', 'asc')
            ->get();

        return $fundRows->concat($trustRows)
            ->sortBy([['created_date', 'asc'], ['trx_id', 'asc']])
            ->values();
    }

    public function countTransactions(): int
    {
        $schema     = env('VIEFUND_DB_SCHEMA', 'dbo');
        $fundCount  = (int) $this->buildBaseQuery($schema)->distinct()->count('l.iTrxID');
        $trustCount = (int) $this->buildTrustBaseQuery($schema)->count();

        return $fundCount + $trustCount;
    }

    public function getLatestBalance(array $filters = []): ?float
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $query  = $this->buildBaseQuery($schema);
        $this->applyFiltersAndSearch($query, null, $filters, $schema);

        $row = $query->select([
            DB::raw('ct.mBalance AS balance'),
            DB::raw('ct.ID AS cash_trx_id'),
        ])
            ->orderBy('ct.ID', 'desc')
            ->first();

        return $row ? (float) $row->balance : null;
    }

    /**
     * Sum fund cash amounts for the given iTrxID set, deduplicating so each
     * (plan, cash_trx) pair is counted exactly once regardless of how many
     * UB_FundTrxLookup rows share the same iTrxID.
     */
    private function sumFundAmountsForIds(string $schema, array $trxIds, array $filters = []): float
    {
        if (empty($trxIds)) return 0.0;

        $placeholders = implode(',', array_fill(0, count($trxIds), '?'));
        $filterSql = '';
        $bindings  = $trxIds;

        if (!empty($filters['account_id'])) {
            $filterSql .= ' AND p.DealerAccountID = ?';
            $bindings[] = $filters['account_id'];
        }
        if (!empty($filters['customer_id'])) {
            $filterSql .= ' AND c.ID = ?';
            $bindings[] = $filters['customer_id'];
        }

        $sql = "
            SELECT SUM(cash_amount) AS total
            FROM (
                SELECT DISTINCT p.DealerAccountID, ct.ID AS cash_id, ct.mAmount AS cash_amount
                FROM {$schema}.UB_FundTrxLookup l
                JOIN {$schema}.UB_FundTrx t   ON t.ID  = l.iTrxID
                JOIN {$schema}.UB_Plan p       ON p.ID  = l.iPlanID
                LEFT JOIN {$schema}.UB_Customer c ON c.ID = p.iClientID
                LEFT JOIN {$schema}.UB_FundTrxCash fc ON fc.iTrxID = l.iTrxID
                LEFT JOIN {$schema}.UB_CashTrx ct     ON ct.ID = fc.iCashTrxID
                WHERE l.iTrxID IN ({$placeholders})
                  AND ct.mAmount IS NOT NULL
                  {$filterSql}
            ) AS deduped
        ";

        $row = DB::connection(self::CONNECTION)->selectOne($sql, $bindings);
        return $row ? (float) $row->total : 0.0;
    }

    public function getCalculatedBalance(array $filters = []): ?float
    {
        if (empty($filters['customer_id']) && empty($filters['account_id'])) {
            return null;
        }

        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        // Get all distinct iTrxIDs for this scope
        $fundBase = $this->buildBaseQuery($schema);
        $this->applyContextFilters($fundBase, $filters);
        $trxIds = $fundBase->distinct()->pluck('l.iTrxID')->map('intval')->toArray();
        $fundSum = $this->sumFundAmountsForIds($schema, $trxIds, $filters);

        $trustBase = $this->buildTrustBaseQuery($schema);
        $this->applyTrustFiltersAndSearch($trustBase, null, $filters, $schema);
        $trustSum = (float) $trustBase->sum('tr.mAmount');

        return $fundSum + $trustSum;
    }

    public function getPageStartBalance(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): float
    {
        if ($page <= 1 || (empty($filters['customer_id']) && empty($filters['account_id']))) {
            return 0.0;
        }

        $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema);
        $this->applyFiltersAndSearch($fundBase, $search, $filters, $schema);
        $this->applyTrustFiltersAndSearch($trustBase, $search, $filters, $schema);

        $prevCount = ($page - 1) * $perPage;

        // Same PHP-level merge approach as fetchTransactions to avoid raw UNION SQL
        // driver compatibility issues.
        $allFundUnits = (clone $fundBase)
            ->select([
                DB::raw('l.iTrxID AS group_key'),
                DB::raw("'fund' AS source_type"),
                DB::raw('MIN(t.dtCreated) AS sort_date'),
                DB::raw('MAX(p.DealerAccountID) AS plan_account_id'),
            ])
            ->groupBy('l.iTrxID')
            ->get();

        $allTrustUnits = (clone $trustBase)
            ->select([
                DB::raw('tr.ID AS group_key'),
                DB::raw("'trust' AS source_type"),
                DB::raw('tr.dtCreated AS sort_date'),
                DB::raw('p.DealerAccountID AS plan_account_id'),
            ])
            ->get();

        $prevUnits = $allFundUnits->concat($allTrustUnits)
            ->sortBy(fn($u) => [
                (string) ($u->plan_account_id ?? ''),
                (string) ($u->sort_date ?? '9999-12-31'),
                (int) $u->group_key,
            ])
            ->values()
            ->take($prevCount);

        $prevFundIds  = $prevUnits->where('source_type', 'fund')->pluck('group_key')->map('intval')->toArray();
        $prevTrustIds = $prevUnits->where('source_type', 'trust')->pluck('group_key')->map('intval')->toArray();

        $sum = 0.0;
        if (!empty($prevFundIds)) {
            $sum += $this->sumFundAmountsForIds($schema, $prevFundIds);
        }
        if (!empty($prevTrustIds)) {
            $sum += (float) (clone $trustBase)->whereIn('tr.ID', $prevTrustIds)->sum('tr.mAmount');
        }

        return $sum;
    }

    public function getCalculatedBalancesByPlan(array $filters = []): array
    {
        if (empty($filters['customer_id']) && empty($filters['account_id'])) {
            return [];
        }

        // Build a deterministic cache key from the filter scope
        $cacheKey = 'viefund_calc_balances_' . md5(
            ($filters['account_id'] ?? '') . '|' . ($filters['customer_id'] ?? '')
        );

        $result = \Illuminate\Support\Facades\Cache::rememberForever($cacheKey, function () use ($filters) {
            $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

            // Fund balance: use deduplicated subquery to avoid overcounting when
            // UB_FundTrxLookup has multiple rows per iTrxID (one per allocation type).
            $filterSql = '';
            $bindings  = [];
            if (!empty($filters['account_id'])) {
                $filterSql .= ' AND p.DealerAccountID = ?';
                $bindings[] = $filters['account_id'];
            }
            if (!empty($filters['customer_id'])) {
                $filterSql .= ' AND c.ID = ?';
                $bindings[] = $filters['customer_id'];
            }

            $fundSql = "
                SELECT account_id, SUM(cash_amount) AS total
                FROM (
                    SELECT DISTINCT p.DealerAccountID AS account_id, ct.ID AS cash_id, ct.mAmount AS cash_amount
                    FROM {$schema}.UB_FundTrxLookup l
                    JOIN {$schema}.UB_FundTrx t   ON t.ID  = l.iTrxID
                    JOIN {$schema}.UB_Plan p       ON p.ID  = l.iPlanID
                    LEFT JOIN {$schema}.UB_Customer c ON c.ID = p.iClientID
                    LEFT JOIN {$schema}.UB_FundTrxCash fc ON fc.iTrxID = l.iTrxID
                    LEFT JOIN {$schema}.UB_CashTrx ct     ON ct.ID = fc.iCashTrxID
                    WHERE ct.mAmount IS NOT NULL {$filterSql}
                ) AS deduped
                GROUP BY account_id
            ";
            $fundRows = DB::connection(self::CONNECTION)->select($fundSql, $bindings);

            $trustBase = $this->buildTrustBaseQuery($schema);
            $this->applyTrustFiltersAndSearch($trustBase, null, $filters, $schema);
            $trustRows = (clone $trustBase)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(tr.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();

            $result = [];
            foreach ($fundRows  as $row) { $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total; }
            foreach ($trustRows as $row) { $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total; }
            return $result;
        });

        // Track the key in a registry so the sync command can bust all balance caches at once
        $registry = \Illuminate\Support\Facades\Cache::get('viefund_calc_balance_keys', []);
        if (!in_array($cacheKey, $registry, true)) {
            $registry[] = $cacheKey;
            \Illuminate\Support\Facades\Cache::forever('viefund_calc_balance_keys', $registry);
        }

        return $result;
    }

    public function getPageStartBalancesByPlan(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): array
    {
        if ($page <= 1 || (empty($filters['customer_id']) && empty($filters['account_id']))) {
            return [];
        }

        $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema);
        $this->applyFiltersAndSearch($fundBase, $search, $filters, $schema);
        $this->applyTrustFiltersAndSearch($trustBase, $search, $filters, $schema);

        $prevCount = ($page - 1) * $perPage;

        // Same PHP-level merge approach as fetchTransactions to avoid raw UNION SQL
        // driver compatibility issues.
        $allFundUnits = (clone $fundBase)
            ->select([
                DB::raw('l.iTrxID AS group_key'),
                DB::raw("'fund' AS source_type"),
                DB::raw('MIN(t.dtCreated) AS sort_date'),
                DB::raw('MAX(p.DealerAccountID) AS plan_account_id'),
            ])
            ->groupBy('l.iTrxID')
            ->get();

        $allTrustUnits = (clone $trustBase)
            ->select([
                DB::raw('tr.ID AS group_key'),
                DB::raw("'trust' AS source_type"),
                DB::raw('tr.dtCreated AS sort_date'),
                DB::raw('p.DealerAccountID AS plan_account_id'),
            ])
            ->get();

        $prevUnits = $allFundUnits->concat($allTrustUnits)
            ->sortBy(fn($u) => [
                (string) ($u->plan_account_id ?? ''),
                (string) ($u->sort_date ?? '9999-12-31'),
                (int) $u->group_key,
            ])
            ->values()
            ->take($prevCount);

        $prevFundIds  = $prevUnits->where('source_type', 'fund')->pluck('group_key')->map('intval')->toArray();
        $prevTrustIds = $prevUnits->where('source_type', 'trust')->pluck('group_key')->map('intval')->toArray();

        $result = [];
        if (!empty($prevFundIds)) {
            $rows = (clone $fundBase)
                ->whereIn('l.iTrxID', $prevFundIds)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(ct.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();
            foreach ($rows as $row) { $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total; }
        }
        if (!empty($prevTrustIds)) {
            $rows = (clone $trustBase)
                ->whereIn('tr.ID', $prevTrustIds)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(tr.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();
            foreach ($rows as $row) { $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total; }
        }
        return $result;
    }

    public function getPlanAccountSnapshot(string $accountId): ?object
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_CashAccount as ca")
            ->join("{$schema}.UB_Plan as p", 'p.ID', '=', 'ca.iPlanID')
            ->where('p.DealerAccountID', '=', $accountId)
            ->select('ca.*', DB::raw('p.DealerAccountID as plan_account_id'))
            ->first();
    }

    public function getDashboardStats(): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        $fundCount  = (int) $this->buildBaseQuery($schema)->distinct()->count('l.iTrxID');
        $trustCount = (int) $this->buildTrustBaseQuery($schema)->count();

        $customerCount = (int) DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Customer")
            ->count();

        $planCount = (int) DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan")
            ->count();

        // Top transaction types (fund)
        $topTypes = $this->buildBaseQuery($schema)
            ->select([DB::raw('tt.NameEN AS trx_type'), DB::raw('COUNT(DISTINCT l.iTrxID) AS cnt')])
            ->whereNotNull('tt.NameEN')
            ->groupBy('tt.NameEN')
            ->orderByDesc('cnt')
            ->limit(6)
            ->get()
            ->map(fn($r) => ['label' => $r->trx_type, 'count' => (int) $r->cnt])
            ->toArray();

        // Recent transactions (fund)
        $recent = $this->buildBaseQuery($schema)
            ->select($this->fundSelectColumns())
            ->orderByDesc('ct.ID')
            ->limit(5)
            ->get();

        return [
            'fund_count'     => $fundCount,
            'trust_count'    => $trustCount,
            'total_count'    => $fundCount + $trustCount,
            'customer_count' => $customerCount,
            'plan_count'     => $planCount,
            'top_types'      => $topTypes,
            'recent'         => $recent,
        ];
    }

    public function fetchMatchingPlanAccounts(?string $search, array $filters): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        $query = $this->buildBaseQuery($schema);
        $this->applyFiltersAndSearch($query, $search ?: null, $filters, $schema);

        return $query
            ->select([
                DB::raw('p.DealerAccountID AS account_id'),
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName,''),' ',ISNULL(c.LastName,''))) AS customer_name"),
                DB::raw('COUNT(DISTINCT l.iTrxID) AS txn_count'),
            ])
            ->groupBy('p.DealerAccountID', 'c.FirstName', 'c.LastName')
            ->orderBy('p.DealerAccountID')
            ->get()
            ->map(fn($r) => [
                'account_id'    => $r->account_id,
                'customer_name' => trim((string) $r->customer_name),
                'txn_count'     => (int) $r->txn_count,
            ])
            ->toArray();
    }

    // ── Trust transaction helpers ────────────────────────────────────────────

    private function buildTrustBaseQuery(string $schema): \Illuminate\Database\Query\Builder
    {
        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_TrustTrx as tr")
            ->leftJoin("{$schema}.UB_Plan as p", 'p.ID', '=', 'tr.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", function ($join) {
                $join->whereRaw('c.ID = ISNULL(NULLIF(tr.iClientID, 0), p.iClientID)');
            })
            ->leftJoin("{$schema}.UB_Def_TrustType as ttype", 'ttype.ID', '=', 'tr.iType')
            ->leftJoin("{$schema}.UB_Def_TrustDepositType as tdtype", function ($join) {
                $join->on('tdtype.ID', '=', 'tr.iDepositType')
                     ->whereRaw('ISNULL(tr.iDepositType, 0) > 0');
            })
            // Identify the chronologically first standalone trust row per plan so we
            // can use the deposit amount as the balance instead of the snapshot mAmountLeft.
            ->leftJoin(
                DB::raw("(
                    SELECT iPlanID, ID
                    FROM (
                        SELECT iPlanID, ID,
                               ROW_NUMBER() OVER (
                                   PARTITION BY iPlanID
                                   ORDER BY ISNULL(dtCreated, '9999-12-31') ASC, ID ASC
                               ) AS rn
                        FROM {$schema}.UB_TrustTrx
                        WHERE ISNULL(iTrxID, 0) = 0
                    ) AS _t_ranked
                    WHERE rn = 1
                ) AS _first_trust"),
                function ($join) {
                    $join->on('_first_trust.iPlanID', '=', 'tr.iPlanID')
                         ->on('_first_trust.ID', '=', 'tr.ID');
                }
            )
            // Only include standalone trust rows (iTrxID = 0).
            // When iTrxID > 0 the trust row is linked to an existing UB_FundTrx record
            // and will appear with merged data via buildBaseQuery instead.
            ->whereRaw('ISNULL(tr.iTrxID, 0) = 0')
            ->when(config('viefund.hide_zero_amount', false), function ($q) {
                $q->whereNotNull('tr.mAmount')->where('tr.mAmount', '!=', 0);
            });
    }

    private function applyTrustFiltersAndSearch(\Illuminate\Database\Query\Builder $query, ?string $search, array $filters, string $schema): void
    {
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) LIKE ?", ["%{$search}%"])
                  ->orWhere('tr.Notes', 'like', "%{$search}%")
                  ->orWhere('ttype.NameEN', 'like', "%{$search}%")
                  ->orWhere('tdtype.NameEN', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['trx_id'])) {
            $trxIds = array_values(array_filter(array_map('trim', explode(',', $filters['trx_id']))));
            if (count($trxIds) === 1) {
                $query->whereRaw('CAST(tr.ID AS NVARCHAR) LIKE ?', ['%' . ltrim($trxIds[0], 'Tt') . '%']);
            } else {
                $query->whereIn('tr.ID', array_map(fn($id) => (int) ltrim((string) $id, 'Tt'), $trxIds));
            }
        }
        if (!empty($filters['trx_type'])) {
            $types = (array) $filters['trx_type'];
            $query->where(function ($q) use ($types) {
                $q->whereIn('ttype.NameEN', $types)->orWhereIn('tdtype.NameEN', $types);
            });
        }
        if (!empty($filters['direction'])) {
            $directions = array_intersect((array) $filters['direction'], ['debit', 'credit']);
            if (count($directions) === 1) {
                $isDebit = reset($directions) === 'debit';
                $query->where('tr.mAmount', $isDebit ? '<' : '>=', 0);
            }
        }
        if (!empty($filters['created_from'])) {
            $query->where('tr.dtCreated', '>=', $filters['created_from'] . ' 00:00:00');
        }
        if (!empty($filters['created_to'])) {
            $query->where('tr.dtCreated', '<=', $filters['created_to'] . ' 23:59:59');
        }
        if (!empty($filters['customer_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('tr.iClientID', '=', $filters['customer_id'])
                  ->orWhere('p.iClientID', '=', $filters['customer_id']);
            });
        }
        if (!empty($filters['account_id'])) {
            $query->where('p.DealerAccountID', '=', $filters['account_id']);
        }
    }

    private function fundSelectColumns(): array
    {
        return [
            DB::raw('l.iTrxID AS trx_id'),
            DB::raw('t.SourceID AS source_id'),
            DB::raw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) AS client_name"),
            DB::raw('l.DealerRepCode AS rep_code'),
            DB::raw('p.DealerAccountID AS plan_dealer_account_id'),
            DB::raw('ISNULL(tt.NameEN, CAST(l.iType AS NVARCHAR)) AS trx_type'),
            DB::raw('ISNULL(ctt.NameEN, CAST(ct.iType AS NVARCHAR)) AS cash_trx_type'),
            DB::raw('l.OrderID AS fund_wo_number'),
            DB::raw('t.dtCreated AS created_date'),
            DB::raw('l.dtTrade AS trade_date'),
            DB::raw('ct.dtProcessing AS processing_date'),
            DB::raw('ct.dtSettlement AS settlement_date'),
            DB::raw('ct.mAmount AS amount'),
            DB::raw('ct.mBalance AS balance'),
            DB::raw('ct.ID AS cash_trx_id'),
            DB::raw('trlink.mAmountUsed AS amount_used'),
            DB::raw('trlink.mAmountLeft AS amount_left'),
            DB::raw('trlink.Notes AS notes'),
            DB::raw('trlink.mAmountCredit AS amount_credit'),
            DB::raw('trlink.mAmountDebit AS amount_debit'),
            DB::raw("'fund' AS row_source"),
        ];
    }

    private function trustSelectColumns(): array
    {
        return [
            DB::raw("CONCAT('T', CAST(tr.ID AS NVARCHAR)) AS trx_id"),
            DB::raw('NULL AS source_id'),
            DB::raw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) AS client_name"),
            DB::raw('NULL AS rep_code'),
            DB::raw('p.DealerAccountID AS plan_dealer_account_id'),
            DB::raw("ISNULL(ttype.NameEN, CAST(tr.iType AS NVARCHAR)) AS trx_type"),
            DB::raw("CASE WHEN ISNULL(tr.iDepositType, 0) > 0 THEN tdtype.NameEN ELSE NULL END AS cash_trx_type"),
            DB::raw('NULL AS fund_wo_number'),
            DB::raw('tr.dtCreated AS created_date'),
            DB::raw('NULL AS trade_date'),
            DB::raw('NULL AS processing_date'),
            DB::raw('NULL AS settlement_date'),
            DB::raw('tr.mAmount AS amount'),
            // For the first deposit in a plan, use the deposited amount as the balance
            // (mAmountLeft is a current snapshot value, not the historical balance at the time).
            DB::raw("CASE
                WHEN _first_trust.ID IS NOT NULL AND ISNULL(tr.mAmount, 0) > 0
                THEN tr.mAmount
                ELSE tr.mAmountLeft
            END AS balance"),
            DB::raw('tr.ID AS cash_trx_id'),
            DB::raw('tr.mAmountUsed AS amount_used'),
            DB::raw('tr.mAmountLeft AS amount_left'),
            DB::raw('tr.Notes AS notes'),
            DB::raw('tr.mAmountCredit AS amount_credit'),
            DB::raw('tr.mAmountDebit AS amount_debit'),
            DB::raw("'trust' AS row_source"),
        ];
    }

    private function validateIdentifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException('Invalid SQL identifier provided.');
        }

        return $value;
    }
}
