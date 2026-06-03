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
            ->leftJoin("{$schema}.UB_Def_TrxType as ctt", 'ctt.ID', '=', 'ct.iType');

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

        return $query;
    }

    public function fetchTransactions(?string $search = null, array $filters = []): LengthAwarePaginator
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $validPerPage = [50, 100, 250];
        $perPage = in_array((int) request()->query('per_page', 50), $validPerPage)
            ? (int) request()->query('per_page', 50)
            : 50;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $baseQuery = $this->buildBaseQuery($schema);

        if ($search) {
            $baseQuery->where(function ($q) use ($search) {
                $q->whereRaw("CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, '')) LIKE ?", ["%{$search}%"])
                  ->orWhere('l.DealerRepCode', 'like', "%{$search}%")
                  ->orWhere('p.DealerAccountID', 'like', "%{$search}%")
                  ->orWhere('l.OrderID', 'like', "%{$search}%")
                  ->orWhere('tt.NameEN', 'like', "%{$search}%");
            });
        }

        // Column filters
        if (!empty($filters['trx_id'])) {
            $trxIds = array_values(array_filter(array_map('trim', explode(',', $filters['trx_id']))));
            if (count($trxIds) === 1) {
                $baseQuery->whereRaw("CAST(l.iTrxID AS NVARCHAR) LIKE ?", ['%' . $trxIds[0] . '%']);
            } else {
                $baseQuery->whereIn('l.iTrxID', array_map('intval', $trxIds));
            }
        }
        if (!empty($filters['source_id'])) {
            $sourceIds = array_values(array_filter(array_map('trim', explode(',', $filters['source_id']))));
            $baseQuery->where(function ($q) use ($sourceIds) {
                foreach ($sourceIds as $sid) {
                    $q->orWhere('t.SourceID', 'like', '%' . $sid . '%');
                }
            });
        }
        if (!empty($filters['plan_account_id'])) {
            $baseQuery->where('p.DealerAccountID', 'like', '%' . $filters['plan_account_id'] . '%');
        }
        if (!empty($filters['trx_type'])) {
            $types = (array) $filters['trx_type'];
            $baseQuery->whereIn('tt.NameEN', $types);
        }
        if (!empty($filters['direction'])) {
            $directions = array_intersect((array) $filters['direction'], ['debit', 'credit']);
            if (count($directions) === 1) {
                $isDebit = reset($directions) === 'debit';
                $baseQuery->whereIn('l.iTrxID', function ($sub) use ($schema, $isDebit) {
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
            // Both selected → no restriction (OR of all possibilities)
        }
        if (!empty($filters['created_from'])) {
            $baseQuery->where('t.dtCreated', '>=', $filters['created_from'] . ' 00:00:00');
        }
        if (!empty($filters['account_id'])) {
            $baseQuery->where('p.DealerAccountID', '=', $filters['account_id']);
        }
        if (!empty($filters['customer_id'])) {
            $baseQuery->where('c.ID', '=', $filters['customer_id']);
        }
        if (!empty($filters['created_to'])) {
            $baseQuery->where('t.dtCreated', '<=', $filters['created_to'] . ' 23:59:59');
        }

        // Count distinct trx_id groups (not individual rows) so pages never split a group
        $total = (clone $baseQuery)->distinct()->count('l.iTrxID');

        // Fetch the trx_ids for this page, ordered by first occurrence date
        $pageIds = (clone $baseQuery)
            ->select([DB::raw('l.iTrxID AS trx_id')])
            ->groupBy('l.iTrxID')
            ->orderByRaw('MIN(t.dtCreated) ASC')
            ->orderBy('l.iTrxID', 'asc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->pluck('trx_id');

        if ($pageIds->isEmpty()) {
            return new LengthAwarePaginator(collect(), $total, $perPage, $page, [
                'path'  => LengthAwarePaginator::resolveCurrentPath(),
                'query' => LengthAwarePaginator::resolveQueryString(),
            ]);
        }

        $items = (clone $baseQuery)
            ->whereIn('l.iTrxID', $pageIds->toArray())
            ->select([
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
                DB::raw('ct.mAmount AS amount'),
                DB::raw('ct.mBalance AS balance'),
                DB::raw('ct.ID AS cash_trx_id'),
            ])
            ->orderBy('t.dtCreated', 'asc')
            ->orderBy('l.OrderID', 'asc')
            ->orderBy('t.SourceID', 'asc')
            ->orderBy('ct.ID', 'asc')
            ->get();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => LengthAwarePaginator::resolveQueryString(),
        ]);
    }

    // ── Distinct type helpers ────────────────────────────────────────────────

    public function fetchDistinctTrxTypes(array $filters = []): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_FundTrxLookup as l")
            ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
            ->join("{$schema}.UB_Plan as p", 'p.ID', '=', 'l.iPlanID')
            ->leftJoin("{$schema}.UB_Customer as c", 'c.ID', '=', 'p.iClientID')
            ->leftJoin("{$schema}.UB_Def_TrxType as tt", 'tt.ID', '=', 'l.iType')
            ->tap(fn ($q) => $this->applyContextFilters($q, $filters))
            ->whereNotNull('tt.NameEN')
            ->select('tt.NameEN')
            ->distinct()
            ->orderBy('tt.NameEN')
            ->pluck('tt.NameEN')
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
            ->map(fn ($r) => ['account_id' => $r->account_id, 'customer_name' => trim((string) $r->customer_name)])
            ->toArray();
    }

    public function searchCustomers(string $search): array
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Customer as c")
            ->where(function ($q) use ($search) {
                $q->where('c.FirstName', 'like', '%' . $search . '%')
                  ->orWhere('c.LastName', 'like', '%' . $search . '%');
            })
            ->select(
                'c.ID as id',
                DB::raw("TRIM(CONCAT(ISNULL(c.FirstName, ''), ' ', ISNULL(c.LastName, ''))) as name")
            )
            ->orderBy('c.LastName')
            ->orderBy('c.FirstName')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => trim((string) $r->name)])
            ->toArray();
    }

    public function countTransactions(): int
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');

        return (int) $this->buildBaseQuery($schema)->distinct()->count('l.iTrxID');
    }

    private function validateIdentifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException('Invalid SQL identifier provided.');
        }

        return $value;
    }
}
