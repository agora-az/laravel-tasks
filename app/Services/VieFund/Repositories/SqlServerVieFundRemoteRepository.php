<?php

namespace App\Services\VieFund\Repositories;

use Carbon\CarbonInterface;
use App\Services\VieFund\Contracts\VieFundRemoteRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SqlServerVieFundRemoteRepository implements VieFundRemoteRepositoryInterface
{
    private const CONNECTION = 'viefund_sqlsrv';
    private const CASH_COMPLETED_STATUS_IDS = [5, 6];
    private const INTERNAL_AGORA_CUSTOMER_ID = 28;

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

    private function buildBaseQuery(string $schema, ?bool $hideZeroAmount = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_FundTrxLookup as l")
            ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
            ->join("{$schema}.UB_Plan as p", 'p.ID', '=', 'l.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->leftJoin("{$schema}.UB_Def_TrxType as tt", 'tt.ID', '=', 'l.iType')
            ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
            ->leftJoin("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
            ->leftJoin("{$schema}.UB_Def_TrxStatus as os", 'os.ID', '=', 'ct.iStatus')
            ->leftJoin("{$schema}.UB_Def_TrustTypeX as ctt", 'ctt.ID', '=', 'ct.Type')
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

        // Per-call override wins; otherwise the global default (config/viefund.php).
        if ($hideZeroAmount ?? config('viefund.hide_zero_amount', false)) {
            $query->whereNotNull('ct.mAmount')->where('ct.mAmount', '!=', 0);
        }

        return $query;
    }

    public function fetchTransactions(?string $search = null, array $filters = []): LengthAwarePaginator
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
        $validPerPage = [50, 100, 250];
        $perPage = in_array((int) request()->query('per_page', 250), $validPerPage)
            ? (int) request()->query('per_page', 250)
            : 50;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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

        if (config('app.debug')) {
            Log::debug('[VieFund fetchTransactions]', [
                'filters'           => $filters,
                'search'            => $search,
                'page'              => $page,
                'per_page'          => $perPage,
                'fund_units_count'  => $allFundUnits->count(),
                'trust_units_count' => $allTrustUnits->count(),
                'total'             => $total,
                'fund_sql'          => (clone $fundBase)->groupBy('l.iTrxID')->toSql(),
                'trust_sql'         => (clone $trustBase)->toSql(),
            ]);
        }

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

        $fundIds  = $pageUnits->where('source_type', 'fund')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();
        $trustIds = $pageUnits->where('source_type', 'trust')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();

        if (config('app.debug')) {
            Log::debug('[VieFund fetchTransactions] page units', [
                'page_units_count' => $pageUnits->count(),
                'fund_ids'         => $fundIds,
                'trust_ids'        => $trustIds,
            ]);
        }

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

        if (config('app.debug')) {
            Log::debug('[VieFund fetchTransactions] result', [
                'items_count' => $items->count(),
                'total'       => $total,
            ]);
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path'  => LengthAwarePaginator::resolveCurrentPath(),
            'query' => LengthAwarePaginator::resolveQueryString(),
        ]);
    }

    // ── Distinct type helpers ────────────────────────────────────────────────

    public function fetchDistinctTrxTypes(array $filters = []): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();

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

        $trustQuery = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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
        if (!empty($filters['status_group'])) {
            $statusIds = $this->resolveStatusGroupIds((array) $filters['status_group']);
            if (!empty($statusIds)) {
                $query->whereIn('ct.iStatus', $statusIds);
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
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
        $fundQuery  = $this->buildBaseQuery($schema);
        $trustQuery = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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

    /**
     * Fund date column for a report basis. UB_FundTrx/UB_FundTrxLookup/UB_CashTrx.
     */
    private function fundDateColumn(string $basis): string
    {
        return match ($basis) {
            'create_date' => 't.dtCreated',
            // Trade basis uses the CASH transaction's trade date (ct.dtTrade), not
            // the fund lookup's (l.dtTrade) — the two can differ by a day and the
            // client's report keys on ct.dtTrade. Settlement/processing already use ct.*.
            'trade_date' => 'ct.dtTrade',
            'processing_date' => 'ct.dtProcessing',
            'settlement_date' => 'ct.dtSettlement',
            default => throw new InvalidArgumentException('Invalid date column selected for report.'),
        };
    }

    /**
     * Trust date column for a report basis. UB_TrustTrx has dtEffective
     * (value/"trade" date) and dtSettlement (clears, ~T+1); dtCreated is the
     * entry timestamp. Trust has no distinct processing date, so it reuses
     * dtEffective there.
     */
    private function trustDateColumn(string $basis): string
    {
        return match ($basis) {
            'create_date' => 'tr.dtCreated',
            'trade_date', 'processing_date' => 'tr.dtEffective',
            'settlement_date' => 'tr.dtSettlement',
            default => 'tr.dtCreated',
        };
    }

    public function fetchDailyNetTotals(CarbonInterface $fromDate, CarbonInterface $toDate, array $filters = [], string $basis = 'settlement_date'): Collection
    {
        return $this->fetchDailyNetTotalsByDateColumn($fromDate, $toDate, $basis, $filters);
    }

    public function fetchDailyNetTotalsByDateColumn(CarbonInterface $fromDate, CarbonInterface $toDate, string $dateColumn, array $filters = []): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $from = $fromDate->copy()->startOfDay()->toDateTimeString();
        $to = $toDate->copy()->addDay()->startOfDay()->toDateTimeString();

        $fundDateColumn = $this->fundDateColumn($dateColumn);
        $trustDateColumn = $this->trustDateColumn($dateColumn);
        $cashStatusIds = $this->resolveFundStatusIds($filters);
        $trustStatusNames = $this->resolveTrustStatusNames($filters);
        $includeTrust = !empty($trustStatusNames);

        // Deduplicate by cash transaction. UB_FundTrxLookup can hold several
        // allocation rows (e.g. redemption-for-fee / advisor fee / tax) that all
        // link to the SAME UB_CashTrx row carrying the SAME ct.mAmount. Summing
        // per lookup row therefore double/triple-counts fee & tax cash movements,
        // so collapse to distinct ct.ID first (same guard as getCalculatedBalancesByPlan).
        $fundDistinct = $this->buildBaseQuery($schema)
            ->where($fundDateColumn, '>=', $from)
            ->where($fundDateColumn, '<', $to)
            ->whereNotNull($fundDateColumn)
            ->whereNotNull('ct.mAmount')
            ->when(!empty($cashStatusIds), function ($query) use ($cashStatusIds) {
                $query->whereIn('ct.iStatus', $cashStatusIds);
            })
            ->whereIn('ct.iType', [22, 45])
            ->when(!empty($filters['account_id']), function ($query) use ($filters) {
                $query->where('p.DealerAccountID', '=', $filters['account_id']);
            })
            ->when(!empty($filters['customer_id']), function ($query) use ($filters) {
                $query->where('c.ID', '=', $filters['customer_id']);
            })
            ->distinct()
            ->selectRaw("ct.ID AS cash_id, ct.mAmount AS cash_amount, CAST({$fundDateColumn} AS date) AS total_date, p.DealerAccountID AS plan_account_id, c.ID AS customer_id");

        $fundDailyTotals = DB::connection(self::CONNECTION)
            ->query()
            ->fromSub($fundDistinct, 'fund_distinct')
            ->selectRaw('total_date, plan_account_id, customer_id, COUNT(*) AS transaction_count, SUM(cash_amount) AS net_total')
            ->groupBy('total_date', 'plan_account_id', 'customer_id');

        $trustDailyTotals = $this->buildTrustBaseQuery($schema)
            ->where($trustDateColumn, '>=', $from)
            ->where($trustDateColumn, '<', $to)
            ->whereNotNull($trustDateColumn)
            ->whereNotNull('tr.mAmount')
            ->when(!empty($trustStatusNames), function ($query) use ($trustStatusNames) {
                $query->whereIn('ts.NameEN', $trustStatusNames);
            })
            ->when(!empty($filters['account_id']), function ($query) use ($filters) {
                $query->where('p.DealerAccountID', '=', $filters['account_id']);
            })
            ->when(!empty($filters['customer_id']), function ($query) use ($filters) {
                $query->where(function ($q) use ($filters) {
                    $q->where('tr.iClientID', '=', $filters['customer_id'])
                        ->orWhere('p.iClientID', '=', $filters['customer_id']);
                });
            })
            ->selectRaw("CAST({$trustDateColumn} AS date) AS total_date, p.DealerAccountID AS plan_account_id, ISNULL(NULLIF(tr.iClientID, 0), p.iClientID) AS customer_id, COUNT(*) AS transaction_count, SUM(tr.mAmount) AS net_total")
            ->groupByRaw("CAST({$trustDateColumn} AS date), p.DealerAccountID, ISNULL(NULLIF(tr.iClientID, 0), p.iClientID)");

        $combinedDailyTotals = $fundDailyTotals;
        if ($includeTrust) {
            $combinedDailyTotals = $combinedDailyTotals->unionAll($trustDailyTotals);
        }

        return DB::connection(self::CONNECTION)
            ->query()
            ->fromSub($combinedDailyTotals, 'daily_totals')
            ->selectRaw('total_date, plan_account_id, customer_id, SUM(transaction_count) AS transaction_count, SUM(net_total) AS net_total')
            ->groupBy('total_date', 'plan_account_id', 'customer_id')
            ->orderBy('total_date', 'asc')
            ->orderBy('plan_account_id', 'asc')
            ->orderBy('customer_id', 'asc')
            ->get();
    }

    public function fetchCustomerBalancesByDate(CarbonInterface $asOfDate, string $dateColumn, array $filters = []): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $to = $asOfDate->copy()->addDay()->startOfDay()->toDateTimeString();
        $cashAccountScope = $this->resolveBalanceReportCashAccountScope();

        $fundDateColumn = $this->fundDateColumn($dateColumn);
        $trustDateColumn = $this->trustDateColumn($dateColumn);
        $cashStatusIds = $this->resolveFundStatusIds($filters);
        $trustStatusNames = $this->resolveTrustStatusNames($filters);
        $includeTrust = !empty($trustStatusNames);

        $planAccounts = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan as p")
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->whereNotNull('p.DealerAccountID')
            ->where('p.DealerAccountID', '<>', '')
            ->where('p.iClientID', '<>', self::INTERNAL_AGORA_CUSTOMER_ID)
            ->when(!empty($cashAccountScope['excluded_plan_accounts']), function ($query) use ($cashAccountScope) {
                $query->whereNotIn('p.DealerAccountID', $cashAccountScope['excluded_plan_accounts']);
            })
            ->whereExists(function ($query) use ($schema, $cashAccountScope) {
                $query->selectRaw('1')
                    ->from("{$schema}.UB_CashAccount as ca")
                    ->whereColumn('ca.iPlanID', 'p.ID')
                    ->whereNotNull('ca.AccountID')
                    ->where('ca.AccountID', '<>', '');

                $this->applyBalanceReportCashAccountScope($query, $cashAccountScope, 'ca');
            })
            ->selectRaw(
                "p.ID AS plan_id, " .
                "p.DealerAccountID AS plan_account_id, " .
                "TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, ''))) AS client_name, " .
                "(" . $this->buildBalanceReportCashAccountSelectSubquery($schema, $cashAccountScope, 'AccountID') . ") AS account_id, " .
                "(" . $this->buildBalanceReportCashAccountSelectSubquery($schema, $cashAccountScope, 'AccountStatus') . ") AS account_status"
            )
            ->selectRaw("p.dtStartDate AS plan_start_date, p.dtEndDate AS plan_end_date");

        $fundDistinct = $this->buildBaseQuery($schema)
            ->where($fundDateColumn, '<', $to)
            ->whereNotNull($fundDateColumn)
            ->whereNotNull('ct.mAmount')
            ->when(!empty($cashStatusIds), function ($query) use ($cashStatusIds) {
                $query->whereIn('ct.iStatus', $cashStatusIds);
            })
            ->whereIn('ct.iType', [22, 45])
            ->selectRaw("\n                p.ID AS plan_id,\n                ct.ID AS cash_id,\n                MAX(ct.mAmount) AS cash_amount,\n                MAX(l.DealerRepCode) AS rep_code\n            ")
            ->groupBy('p.ID', 'ct.ID');

        $fundBalances = DB::connection(self::CONNECTION)
            ->query()
            ->fromSub($fundDistinct, 'fund_distinct')
            ->selectRaw('plan_id, COUNT(*) AS fund_transaction_count, SUM(cash_amount) AS fund_balance, MAX(rep_code) AS rep_code')
            ->groupBy('plan_id');

        $trustBalances = $this->buildTrustBaseQuery($schema)
            ->where($trustDateColumn, '<', $to)
            ->whereNotNull($trustDateColumn)
            ->whereNotNull('tr.mAmount')
            ->when(!empty($trustStatusNames), function ($query) use ($trustStatusNames) {
                $query->whereIn('ts.NameEN', $trustStatusNames);
            })
            ->selectRaw('p.ID AS plan_id, COUNT(*) AS trust_transaction_count, SUM(tr.mAmount) AS trust_balance')
            ->groupBy('p.ID');

        $query = DB::connection(self::CONNECTION)
            ->query()
            ->fromSub($planAccounts, 'plans')
            ->leftJoinSub($fundBalances, 'fund_balances', 'fund_balances.plan_id', '=', 'plans.plan_id');

        if ($includeTrust) {
            $query->leftJoinSub($trustBalances, 'trust_balances', 'trust_balances.plan_id', '=', 'plans.plan_id');
        }

        $query->selectRaw("\n            plans.client_name,\n            COALESCE(fund_balances.rep_code, '') AS rep_code,\n            plans.plan_account_id,\n            COALESCE(plans.account_id, plans.plan_account_id) AS account_id,\n            COALESCE(plans.account_status, '') AS account_status,\n            COALESCE(fund_balances.fund_transaction_count, 0) AS fund_transaction_count,\n            COALESCE(fund_balances.fund_balance, 0) AS fund_balance,\n            " . ($includeTrust
                ? "COALESCE(trust_balances.trust_transaction_count, 0)"
                : '0') . " AS trust_transaction_count,\n            " . ($includeTrust
                ? "COALESCE(trust_balances.trust_balance, 0)"
                : '0') . " AS trust_balance\n        ");

        return $query
            ->selectRaw("\n                COALESCE(fund_balances.fund_balance, 0) + " . ($includeTrust
                    ? 'COALESCE(trust_balances.trust_balance, 0)'
                    : '0') . " AS total_balance\n            ")
            ->orderBy('plans.plan_account_id')
            ->get();
    }

    public function fetchCustomerCashBalanceTotal(): float
    {
        return (float) $this->fetchCustomerCashBalancesSnapshot()
            ->sum(fn($row) => (float) ($row->cash_balance ?? 0));
    }

    public function fetchCustomerCashBalancesSnapshot(): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $cashAccountScope = $this->resolveBalanceReportCashAccountScope();
        $normalizedAccountId = "CASE WHEN LEFT(ca.AccountID, 1) = '#' THEN SUBSTRING(ca.AccountID, 2, LEN(ca.AccountID) - 1) ELSE ca.AccountID END";

        $rankedCashAccounts = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan as p")
            ->join("{$schema}.UB_CashAccount as ca", 'ca.iPlanID', '=', 'p.ID')
            ->whereNotNull('ca.AccountID')
            ->where('ca.AccountID', '<>', '')
            ->whereNotNull('p.DealerAccountID')
            ->where('p.DealerAccountID', '<>', '')
            ->where('p.iClientID', '<>', self::INTERNAL_AGORA_CUSTOMER_ID)
            ->when(!empty($cashAccountScope['excluded_plan_accounts']), function ($query) use ($cashAccountScope) {
                $query->whereNotIn('p.DealerAccountID', $cashAccountScope['excluded_plan_accounts']);
            })
            ->tap(function ($query) use ($cashAccountScope) {
                $this->applyBalanceReportCashAccountScope($query, $cashAccountScope, 'ca');
            })
            ->selectRaw(
                "p.DealerAccountID AS plan_account_id, " .
                "{$normalizedAccountId} AS account_id, " .
                "CAST(ISNULL(ca.mBalance, 0) AS decimal(38,2)) AS cash_balance, " .
                "ROW_NUMBER() OVER (PARTITION BY p.ID ORDER BY " .
                "CASE WHEN {$normalizedAccountId} = p.ThirdPartyAccount THEN 0 ELSE 1 END, " .
                "CASE WHEN {$normalizedAccountId} = p.DealerAccountID THEN 0 ELSE 1 END, " .
                "CASE WHEN ca.AccountStatus IN ('A', 'Active') THEN 0 WHEN ca.AccountStatus IN ('T', 'Terminated') THEN 1 ELSE 2 END, " .
                "ISNULL(ca.dtCreated, '1900-01-01') ASC, {$normalizedAccountId} ASC" .
                ") AS cash_rank"
            );

        return DB::connection(self::CONNECTION)
            ->query()
            ->fromSub($rankedCashAccounts, 'ranked_cash')
            ->where('cash_rank', '=', 1)
            ->selectRaw('plan_account_id, account_id, cash_balance')
            ->get();
    }

    private function resolveBalanceReportCashAccountScope(): array
    {
        $scope = config('viefund.balance_report_cash_account_scope', []);

        return [
            'currency_code' => !empty($scope['currency_code']) ? (string) $scope['currency_code'] : null,
            'opened_before' => !empty($scope['opened_before']) ? (string) $scope['opened_before'] : null,
            'excluded_plan_accounts' => array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                (array) ($scope['excluded_plan_accounts'] ?? [])
            ))),
        ];
    }

    private function applyBalanceReportCashAccountScope($query, array $scope, string $alias = 'ca'): void
    {
        if (!empty($scope['currency_code'])) {
            $query->where("{$alias}.CurrencyCode", '=', $scope['currency_code']);
        }

        if (!empty($scope['opened_before'])) {
            $query->whereNotNull("{$alias}.dtOpen")
                ->where("{$alias}.dtOpen", '<=', $scope['opened_before']);
        }
    }

    private function buildBalanceReportCashAccountSelectSubquery(string $schema, array $scope, string $column): string
    {
        $allowedColumns = ['AccountID', 'AccountStatus'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported cash-account column requested.');
        }

        $normalizedAccountId = "CASE WHEN LEFT(ca.AccountID, 1) = '#' THEN SUBSTRING(ca.AccountID, 2, LEN(ca.AccountID) - 1) ELSE ca.AccountID END";
        $selectColumn = $column === 'AccountID' ? $normalizedAccountId : "ca.{$column}";

        $parts = [
            "SELECT TOP 1 {$selectColumn}",
            "FROM {$schema}.UB_CashAccount ca",
            "WHERE ca.iPlanID = p.ID",
            "AND ca.AccountID IS NOT NULL",
            "AND ca.AccountID <> ''",
        ];

        if (!empty($scope['currency_code'])) {
            $currency = str_replace("'", "''", $scope['currency_code']);
            $parts[] = "AND ca.CurrencyCode = '{$currency}'";
        }

        if (!empty($scope['opened_before'])) {
            $openedBefore = str_replace("'", "''", $scope['opened_before']);
            $parts[] = 'AND ca.dtOpen IS NOT NULL';
            $parts[] = "AND ca.dtOpen <= '{$openedBefore}'";
        }

        $parts[] = "ORDER BY CASE WHEN {$normalizedAccountId} = p.ThirdPartyAccount THEN 0 ELSE 1 END, CASE WHEN {$normalizedAccountId} = p.DealerAccountID THEN 0 ELSE 1 END, CASE WHEN ca.AccountStatus IN ('A', 'Active') THEN 0 WHEN ca.AccountStatus IN ('T', 'Terminated') THEN 1 ELSE 2 END, ISNULL(ca.dtCreated, '1900-01-01') ASC, {$normalizedAccountId} ASC";

        return implode(' ', $parts);
    }

    public function fetchInceptionDateByDateColumn(string $dateColumn): ?string
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        $fundDateColumn = $this->fundDateColumn($dateColumn);
        $trustDateColumn = $this->trustDateColumn($dateColumn);
        $trustCompletedStatuses = $this->resolveTrustStatusGroupNames(['completed']);

        $fundRow = $this->buildBaseQuery($schema)
            ->whereNotNull($fundDateColumn)
            ->whereNotNull('ct.mAmount')
            ->whereIn('ct.iStatus', self::CASH_COMPLETED_STATUS_IDS)
            ->whereIn('ct.iType', [22, 45])
            ->selectRaw("MIN(CAST({$fundDateColumn} AS date)) AS inception_date")
            ->first();

        $trustRow = $this->buildTrustBaseQuery($schema)
            ->whereNotNull($trustDateColumn)
            ->whereNotNull('tr.mAmount')
            ->when(!empty($trustCompletedStatuses), function ($query) use ($trustCompletedStatuses) {
                $query->whereIn('ts.NameEN', $trustCompletedStatuses);
            })
            ->selectRaw("MIN(CAST({$trustDateColumn} AS date)) AS inception_date")
            ->first();

        $fundInception = $fundRow && !empty($fundRow->inception_date)
            ? \Carbon\Carbon::parse($fundRow->inception_date)->toDateString()
            : null;
        $trustInception = $trustRow && !empty($trustRow->inception_date)
            ? \Carbon\Carbon::parse($trustRow->inception_date)->toDateString()
            : null;

        if (!$fundInception && !$trustInception) {
            return null;
        }

        if (!$fundInception) {
            return $trustInception;
        }

        if (!$trustInception) {
            return $fundInception;
        }

        return $fundInception <= $trustInception ? $fundInception : $trustInception;
    }

    public function fetchDailySettlementFundTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $dayStart = $date->copy()->startOfDay()->toDateTimeString();
        $dayEnd = $date->copy()->addDay()->startOfDay()->toDateTimeString();

        $query = $this->buildBaseQuery($schema)
            ->where('ct.dtSettlement', '>=', $dayStart)
            ->where('ct.dtSettlement', '<', $dayEnd)
            ->whereNotNull('ct.dtSettlement')
            ->whereNotNull('ct.mAmount')
            ->where('ct.iStatus', '=', 6)
            ->whereIn('ct.iType', [22, 45])
            ->select($this->fundSelectColumns())
            ->orderBy('t.dtCreated', 'asc')
            ->orderBy('l.iTrxID', 'asc')
            ->orderBy('ct.ID', 'asc');

        return $query->paginate($perPage, ['*'], 'viefund_page', max(1, $page));
    }

    /**
     * Merged fund + trust transaction listing for a single settlement day,
     * filtered by explicit fund status IDs and trust status names. The row
     * granularity and filters mirror the COUNT(*)/SUM used by
     * fetchDailyNetTotalsByDateColumn (settlement-date basis), so the paginator
     * total and summed amount equal the cached daily-totals snapshot for the
     * same filters.
     *
     * @return array{items: LengthAwarePaginator, transaction_count: int, net_total: float}
     */
    public function fetchDailyTransactions(CarbonInterface $date, array $statusIds, array $trustStatusNames, int $perPage = 250, int $page = 1, string $basis = 'settlement_date', ?bool $hideZeroAmount = null): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $dayStart = $date->copy()->startOfDay()->toDateTimeString();
        $dayEnd = $date->copy()->addDay()->startOfDay()->toDateTimeString();
        $page = max(1, $page);
        $fundDateColumn = $this->fundDateColumn($basis);
        $trustDateColumn = $this->trustDateColumn($basis);

        $items = collect();

        if (!empty($statusIds)) {
            // One UB_CashTrx can appear on several UB_FundTrxLookup allocation
            // rows (all carrying the same amount); collapse to one row per cash
            // transaction so the listing matches the deduped daily total.
            $seenCash = [];
            $fundRows = $this->buildBaseQuery($schema, $hideZeroAmount)
                ->where($fundDateColumn, '>=', $dayStart)
                ->where($fundDateColumn, '<', $dayEnd)
                ->whereNotNull($fundDateColumn)
                ->whereNotNull('ct.mAmount')
                ->whereIn('ct.iStatus', $statusIds)
                ->whereIn('ct.iType', [22, 45])
                ->select($this->fundSelectColumns())
                ->get()
                ->filter(function ($row) use (&$seenCash) {
                    $key = $row->cash_trx_id;
                    if ($key === null || isset($seenCash[$key])) {
                        return false;
                    }
                    $seenCash[$key] = true;
                    return true;
                })
                ->values();
            $items = $items->concat($fundRows);
        }

        if (!empty($trustStatusNames)) {
            $items = $items->concat(
                $this->buildTrustBaseQuery($schema, $hideZeroAmount)
                    ->where($trustDateColumn, '>=', $dayStart)
                    ->where($trustDateColumn, '<', $dayEnd)
                    ->whereNotNull($trustDateColumn)
                    ->whereNotNull('tr.mAmount')
                    ->whereIn('ts.NameEN', $trustStatusNames)
                    ->select($this->trustSelectColumns())
                    ->get()
            );
        }

        $items = $items
            ->sortBy(fn($row) => sprintf(
                '%s|%s|%s',
                (string) ($row->plan_dealer_account_id ?? ''),
                (string) ($row->settlement_date ?? $row->created_date ?? '9999-12-31'),
                (string) $row->trx_id
            ))
            ->values();

        $transactionCount = $items->count();
        $netTotal = (float) $items->sum(fn($row) => (float) ($row->amount ?? 0));

        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $transactionCount,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'viefund_page',
                'query' => LengthAwarePaginator::resolveQueryString(),
            ]
        );

        return [
            'items' => $paginator,
            'transaction_count' => $transactionCount,
            'net_total' => $netTotal,
        ];
    }

    public function fetchDailySettlementTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $dayStart = $date->copy()->startOfDay()->toDateTimeString();
        $dayEnd = $date->copy()->addDay()->startOfDay()->toDateTimeString();

        $query = DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_CashTrx as ct")
            ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iCashTrxID', '=', 'ct.ID')
            ->leftJoin("{$schema}.UB_FundTrxLookup as l", 'l.iTrxID', '=', 'fc.iTrxID')
            ->leftJoin("{$schema}.UB_Plan as p", 'p.ID', '=', 'l.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->leftJoin("{$schema}.UB_Def_TrxType as tt", 'tt.ID', '=', 'ct.iType')
            ->select([
                DB::raw('ct.ID as cash_trx_id'),
                DB::raw('ct.dtSettlement as settlement_date'),
                DB::raw('ct.mAmount as amount'),
                DB::raw('ct.iType as type_id'),
                DB::raw('ct.iStatus as status_id'),
                DB::raw('ISNULL(MIN(tt.NameEN), CAST(ct.iType AS NVARCHAR)) as type_name'),
                DB::raw("CASE WHEN COUNT(DISTINCT c.ID) > 1 THEN 'Multiple' ELSE MIN(TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')))) END AS customer_name"),
            ])
            ->where('ct.dtSettlement', '>=', $dayStart)
            ->where('ct.dtSettlement', '<', $dayEnd)
            ->whereNotNull('ct.dtSettlement')
            ->whereNotNull('ct.mAmount')
            ->where('ct.iStatus', '=', 6)
            ->whereIn('ct.iType', [22, 45]);

        if (config('viefund.hide_zero_amount', false)) {
            $query->where('ct.mAmount', '<>', 0);
        }

        return $query
            ->orderBy('ct.dtSettlement')
            ->orderBy('ct.ID')
            ->groupBy('ct.ID', 'ct.dtSettlement', 'ct.mAmount', 'ct.iType', 'ct.iStatus')
            ->paginate($perPage, ['*'], 'viefund_page', max(1, $page));
    }

    public function countTransactions(): int
    {
        $schema     = env('VIEFUND_DB_SCHEMA', 'dbo');
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
        $fundCount  = (int) $this->buildBaseQuery($schema)->distinct()->count('l.iTrxID');
        $trustCount = (int) $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes)->count();

        return $fundCount + $trustCount;
    }

    public function getLatestBalance(array $filters = []): ?float
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
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
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();

        // Get all distinct iTrxIDs for this scope
        $fundBase = $this->buildBaseQuery($schema);
        $this->applyContextFilters($fundBase, $filters);
        $trxIds = $fundBase->distinct()->pluck('l.iTrxID')->map(fn($v) => (int) $v)->toArray();
        $fundSum = $this->sumFundAmountsForIds($schema, $trxIds, $filters);

        $trustBase = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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

        $prevFundIds  = $prevUnits->where('source_type', 'fund')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();
        $prevTrustIds = $prevUnits->where('source_type', 'trust')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();

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
            $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();

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

            $trustBase = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
            $this->applyTrustFiltersAndSearch($trustBase, null, $filters, $schema);
            $trustRows = (clone $trustBase)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(tr.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();

            $result = [];
            foreach ($fundRows  as $row) {
                $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total;
            }
            foreach ($trustRows as $row) {
                $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total;
            }
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
        $excludedStandaloneTrustTypes = $this->cashBalanceExcludedStandaloneTrustTypes();
        $fundBase  = $this->buildBaseQuery($schema);
        $trustBase = $this->buildTrustBaseQuery($schema, null, $excludedStandaloneTrustTypes);
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

        $prevFundIds  = $prevUnits->where('source_type', 'fund')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();
        $prevTrustIds = $prevUnits->where('source_type', 'trust')->pluck('group_key')->map(fn($v) => (int) $v)->toArray();

        $result = [];
        if (!empty($prevFundIds)) {
            $rows = (clone $fundBase)
                ->whereIn('l.iTrxID', $prevFundIds)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(ct.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();
            foreach ($rows as $row) {
                $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total;
            }
        }
        if (!empty($prevTrustIds)) {
            $rows = (clone $trustBase)
                ->whereIn('tr.ID', $prevTrustIds)
                ->select([DB::raw('p.DealerAccountID AS account_id'), DB::raw('SUM(tr.mAmount) AS total')])
                ->groupBy('p.DealerAccountID')
                ->get();
            foreach ($rows as $row) {
                $result[$row->account_id] = ($result[$row->account_id] ?? 0.0) + (float) $row->total;
            }
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

        // Fund-side plan accounts.
        $fundQuery = $this->buildBaseQuery($schema);
        $this->applyFiltersAndSearch($fundQuery, $search ?: null, $filters, $schema);
        $fundRows = $fundQuery
            ->select([
                DB::raw('p.DealerAccountID AS account_id'),
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName,''),' ',ISNULL(c.LastName,''))) AS customer_name"),
                DB::raw('COUNT(DISTINCT l.iTrxID) AS txn_count'),
            ])
            ->groupBy('p.DealerAccountID', 'c.FirstName', 'c.LastName')
            ->get();

        // Trust-side plan accounts. Plans that hold ONLY standalone trust activity
        // (e.g. transfer-only / terminated cash accounts) have no fund lookups, so
        // they'd otherwise be invisible here even though their transactions and
        // balances are available on drill-in.
        $trustQuery = $this->buildTrustBaseQuery($schema, null, $this->cashBalanceExcludedStandaloneTrustTypes());
        $this->applyTrustFiltersAndSearch($trustQuery, $search ?: null, $filters, $schema);
        $trustRows = $trustQuery
            ->select([
                DB::raw('p.DealerAccountID AS account_id'),
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName,''),' ',ISNULL(c.LastName,''))) AS customer_name"),
                DB::raw('COUNT(*) AS txn_count'),
            ])
            ->groupBy('p.DealerAccountID', 'c.FirstName', 'c.LastName')
            ->get();

        $byAccount = [];
        foreach ($fundRows->concat($trustRows) as $r) {
            $key = trim((string) $r->account_id);
            if ($key === '') {
                continue;
            }
            if (!isset($byAccount[$key])) {
                $byAccount[$key] = [
                    'account_id'    => $r->account_id,
                    'customer_name' => trim((string) $r->customer_name),
                    'txn_count'     => 0,
                ];
            }
            $byAccount[$key]['txn_count'] += (int) $r->txn_count;
            if ($byAccount[$key]['customer_name'] === '' && trim((string) $r->customer_name) !== '') {
                $byAccount[$key]['customer_name'] = trim((string) $r->customer_name);
            }
        }

        ksort($byAccount);

        return array_values($byAccount);
    }

    // ── Trust transaction helpers ────────────────────────────────────────────

    private function buildTrustBaseQuery(string $schema, ?bool $hideZeroAmount = null, array $excludedStandaloneTrustTypes = []): \Illuminate\Database\Query\Builder
    {
        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_TrustTrx as tr")
            ->leftJoin("{$schema}.UB_Plan as p", 'p.ID', '=', 'tr.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", function ($join) {
                $join->whereRaw('c.ID = ISNULL(NULLIF(tr.iClientID, 0), p.iClientID)');
            })
            ->leftJoin("{$schema}.UB_Def_TrustType as ttype", 'ttype.ID', '=', 'tr.iType')
            ->leftJoin("{$schema}.UB_Def_TrustStatus as ts", 'ts.ID', '=', 'tr.iStatus')
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
            ->when(!empty($excludedStandaloneTrustTypes), function ($query) use ($excludedStandaloneTrustTypes) {
                $query->where(function ($nested) use ($excludedStandaloneTrustTypes) {
                    $nested->whereNotIn('ttype.NameEN', $excludedStandaloneTrustTypes)
                        ->orWhereNull('ttype.NameEN');
                });
            })
            ->when($hideZeroAmount ?? config('viefund.hide_zero_amount', false), function ($q) {
                $q->whereNotNull('tr.mAmount')->where('tr.mAmount', '!=', 0);
            });
    }

    private function cashBalanceExcludedStandaloneTrustTypes(): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            (array) config('viefund.cash_balance_excluded_standalone_trust_types', [])
        )));
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
        if (!empty($filters['status_group'])) {
            $statusNames = $this->resolveTrustStatusGroupNames((array) $filters['status_group']);
            if (!empty($statusNames)) {
                $query->whereIn('ts.NameEN', $statusNames);
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

    private function resolveStatusGroupIds(array $groups): array
    {
        $map = [
            'not_completed' => [0, 1, 2],
            'open'          => [3, 4],
            'completed'     => [5, 6],
        ];

        $ids = [];
        foreach ($groups as $group) {
            if (!isset($map[$group])) {
                continue;
            }
            $ids = array_merge($ids, $map[$group]);
        }

        return array_values(array_unique($ids));
    }

    private function resolveTrustStatusGroupNames(array $groups): array
    {
        $map = [
            'not_completed' => ['Deleted'],
            'open'          => ['Unsettled'],
            'completed'     => ['Settled'],
        ];

        $names = [];
        foreach ($groups as $group) {
            if (!isset($map[$group])) {
                continue;
            }
            $names = array_merge($names, $map[$group]);
        }

        return array_values(array_unique($names));
    }

    private function resolveReportStatusGroups(array $filters): array
    {
        $allowed = ['not_completed', 'open', 'completed'];
        $statusGroups = array_values(array_intersect((array) ($filters['status_group'] ?? ['completed']), $allowed));

        if (empty($statusGroups)) {
            return ['completed'];
        }

        return $statusGroups;
    }

    private function resolveIncludeTrustFilter(array $filters): bool
    {
        if (!array_key_exists('include_trust', $filters)) {
            return true;
        }

        $value = $filters['include_trust'];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve the fund status IDs (UB_Def_TrxStatus: 0-6) to include.
     * Prefers explicit `status_ids`; falls back to the legacy `status_group`
     * names; defaults to Confirmed (6).
     */
    private function resolveFundStatusIds(array $filters): array
    {
        if (array_key_exists('status_ids', $filters)) {
            $ids = array_map('intval', (array) $filters['status_ids']);
            $ids = array_values(array_unique(array_filter($ids, fn($id) => $id >= 0 && $id <= 6)));
            return $ids;
        }

        if (!empty($filters['status_group'])) {
            return $this->resolveStatusGroupIds($this->resolveReportStatusGroups($filters));
        }

        return [6];
    }

    /**
     * Resolve the trust status names (UB_Def_TrustStatus NameEN) to include.
     * Prefers explicit `trust_status_names`; falls back to the legacy
     * `status_group` + `include_trust` filters; defaults to Settled. An empty
     * result means trust transactions are excluded entirely.
     */
    private function resolveTrustStatusNames(array $filters): array
    {
        $allowed = ['Deleted', 'Unsettled', 'Settled'];

        if (array_key_exists('trust_status_names', $filters)) {
            return array_values(array_intersect($allowed, (array) $filters['trust_status_names']));
        }

        if (!$this->resolveIncludeTrustFilter($filters)) {
            return [];
        }

        if (!empty($filters['status_group'])) {
            return $this->resolveTrustStatusGroupNames($this->resolveReportStatusGroups($filters));
        }

        return ['Settled'];
    }

    private function fundSelectColumns(): array
    {
        return [
            DB::raw('l.iTrxID AS trx_id'),
            DB::raw('l.iTrxID AS fund_trx_id'),
            DB::raw('t.SourceID AS source_id'),
            DB::raw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) AS client_name"),
            DB::raw('l.DealerRepCode AS rep_code'),
            DB::raw('p.DealerAccountID AS plan_dealer_account_id'),
            DB::raw('ISNULL(tt.NameEN, CAST(l.iType AS NVARCHAR)) AS trx_type'),
            DB::raw('ISNULL(ctt.NameEN, CAST(ct.Type AS NVARCHAR)) AS cash_trx_type'),
            DB::raw('l.OrderID AS fund_wo_number'),
            DB::raw('t.dtCreated AS created_date'),
            // Trade date = the CASH transaction's dtTrade (matches the reconciled
            // trade basis and the client), not the fund lookup's l.dtTrade which
            // can be a day later.
            DB::raw('ct.dtTrade AS trade_date'),
            DB::raw('ct.dtCreated AS cash_created_date'),
            DB::raw('ct.dtTrade AS cash_trade_date'),
            DB::raw('ct.dtProcessing AS processing_date'),
            DB::raw('ct.dtSettlement AS settlement_date'),
            DB::raw('ct.mAmount AS amount'),
            DB::raw('ct.mBalance AS balance'),
            DB::raw('ct.ID AS cash_trx_id'),
            DB::raw('NULL AS trust_trx_id'),
            DB::raw('trlink.ID AS linked_trust_trx_id'),
            DB::raw('trlink.mAmountUsed AS amount_used'),
            DB::raw('trlink.mAmountLeft AS amount_left'),
            DB::raw('trlink.Notes AS notes'),
            DB::raw('trlink.mAmountCredit AS amount_credit'),
            DB::raw('trlink.mAmountDebit AS amount_debit'),
            DB::raw('os.NameEN AS status'),
            DB::raw("'fund' AS row_source"),
        ];
    }

    private function trustSelectColumns(): array
    {
        return [
            DB::raw("CONCAT('T', CAST(tr.ID AS NVARCHAR)) AS trx_id"),
            DB::raw('NULL AS fund_trx_id'),
            DB::raw('NULL AS source_id'),
            DB::raw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) AS client_name"),
            DB::raw('NULL AS rep_code'),
            DB::raw('p.DealerAccountID AS plan_dealer_account_id'),
            DB::raw("ISNULL(ttype.NameEN, CAST(tr.iType AS NVARCHAR)) AS trx_type"),
            DB::raw("CASE WHEN ISNULL(tr.iDepositType, 0) > 0 THEN tdtype.NameEN ELSE NULL END AS cash_trx_type"),
            DB::raw('NULL AS fund_wo_number'),
            DB::raw('tr.dtCreated AS created_date'),
            DB::raw('tr.dtEffective AS trade_date'),
            DB::raw('NULL AS cash_created_date'),
            DB::raw('NULL AS cash_trade_date'),
            DB::raw('NULL AS processing_date'),
            DB::raw('tr.dtSettlement AS settlement_date'),
            DB::raw('tr.mAmount AS amount'),
            // For the first deposit in a plan, use the deposited amount as the balance
            // (mAmountLeft is a current snapshot value, not the historical balance at the time).
            DB::raw("CASE
                WHEN _first_trust.ID IS NOT NULL AND ISNULL(tr.mAmount, 0) > 0
                THEN tr.mAmount
                ELSE tr.mAmountLeft
            END AS balance"),
            DB::raw('tr.ID AS cash_trx_id'),
            DB::raw('tr.ID AS trust_trx_id'),
            DB::raw('NULL AS linked_trust_trx_id'),
            DB::raw('tr.mAmountUsed AS amount_used'),
            DB::raw('tr.mAmountLeft AS amount_left'),
            DB::raw('tr.Notes AS notes'),
            DB::raw('tr.mAmountCredit AS amount_credit'),
            DB::raw('tr.mAmountDebit AS amount_debit'),
            DB::raw('ts.NameEN AS status'),
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
