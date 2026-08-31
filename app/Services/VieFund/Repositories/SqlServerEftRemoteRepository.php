<?php

namespace App\Services\VieFund\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SqlServerEftRemoteRepository
{
    private const CONNECTION = 'viefund_sqlsrv';

    public function types(): Collection
    {
        return $this->connection()
            ->table($this->table('UB_Def_EFTType'))
            ->select(['ID as id', 'NameEN as name', 'iOrder as sort_order'])
            ->orderBy('iOrder')
            ->orderBy('ID')
            ->get();
    }

    public function fileStatuses(): Collection
    {
        return $this->connection()
            ->table($this->table('UB_EFTFile'))
            ->whereNotNull('iStatus')
            ->selectRaw('iStatus as id, count(*) as row_count')
            ->groupBy('iStatus')
            ->orderBy('iStatus')
            ->get();
    }

    public function itemStatuses(): Collection
    {
        return $this->connection()
            ->table($this->table('UB_EFTItem'))
            ->whereNotNull('iStatus')
            ->selectRaw('iStatus as id, count(*) as row_count')
            ->groupBy('iStatus')
            ->orderBy('iStatus')
            ->get();
    }

    public function paginateFiles(
        array $filters,
        string $sort,
        string $direction,
        int $perPage = 50,
        string $pageName = 'file_page'
    ): LengthAwarePaginator {
        $sortColumns = [
            'created_at' => 'f.dtCreated',
            'effective_date' => 'f.dtEffective',
            'type' => 't.NameEN',
            'status' => 'f.iStatus',
            'total_amount' => 'total_amount',
            'item_count' => 'item_count',
            'sequence' => 'f.iSequenceNumber',
        ];

        $query = $this->fileQuery($filters)
            ->select([
                'f.ID as id',
                'f.dtCreated as created_at',
                'f.dtEffective as effective_date',
                'f.iType as type_id',
                't.NameEN as type_name',
                'f.iStatus as status_id',
                'f.iTrustBankAccountID as trust_bank_account_id',
                'f.iSequenceNumber as sequence_number',
                'f.iOption as option_id',
                'f.FileName as file_name',
                'f.Notes as notes',
            ])
            ->selectRaw('f.mTotalAmount as total_amount')
            ->selectRaw('(select count(*) from ' . $this->table('UB_EFTItem') . ' ei where ei.iProcessingID = f.ID) as item_count')
            ->selectRaw('(select sum(ei.mAmount) from ' . $this->table('UB_EFTItem') . ' ei where ei.iProcessingID = f.ID) as item_amount');

        $query->orderBy($sortColumns[$sort] ?? 'f.dtCreated', $direction)
            ->orderByDesc('f.ID');

        return $query->paginate($perPage, ['*'], $pageName)->withQueryString();
    }

    public function paginateItems(
        array $filters,
        string $sort,
        string $direction,
        int $perPage = 50,
        string $pageName = 'item_page',
        bool $missingCandidates = false,
        ?string $candidateDate = null
    ): LengthAwarePaginator {
        $sortColumns = [
            'created_at' => 'i.dtCreated',
            'effective_date' => 'i.dtEffective',
            'type' => 't.NameEN',
            'status' => 'i.iStatus',
            'amount' => 'amount',
            'holder' => 'i.HolderName',
            'file' => 'f.FileName',
        ];

        $query = $this->itemQuery($filters)
            ->select([
                'i.ID as id',
                'i.iProcessingID as processing_id',
                'i.dtCreated as created_at',
                'i.dtEffective as effective_date',
                'i.iLinkedType as type_id',
                't.NameEN as type_name',
                'i.iLinkedID as linked_id',
                'i.iStatus as status_id',
                'i.HolderName as holder_name',
                'i.HolderID as holder_id',
                'i.SourceCode as source_code',
                's.NameEN as source_name',
                'i.BankCode as bank_code',
                'i.BankTransit as bank_transit',
                'i.Notes as notes',
                'f.FileName as file_name',
                'tr.dtEffective as trade_date',
                'tr.dtSettlement as settlement_date',
            ])
            ->selectRaw('i.mAmount as amount')
            ->selectRaw("right(rtrim(coalesce(i.BankAccountNumber, '')), 4) as bank_account_last4");

        if ($missingCandidates) {
            $this->applyMissingCandidateFilter($query, $filters, $candidateDate);
        }

        if ($sort === 'signed_amount') {
            $query->orderByRaw("case when i.iLinkedType = 10 then i.mAmount else -i.mAmount end {$direction}");
        } else {
            $query->orderBy($sortColumns[$sort] ?? 'i.dtCreated', $direction);
        }
        $query->orderByDesc('i.ID');

        return $query->paginate($perPage, ['*'], $pageName)->withQueryString();
    }

    public function missingCandidates(array $filters, ?string $candidateDate = null): Collection
    {
        $query = $this->itemRowsQuery($filters);
        $this->applyMissingCandidateFilter($query, $filters, $candidateDate);

        return $query
            ->orderByDesc('i.dtEffective')
            ->orderByDesc('i.dtCreated')
            ->orderByDesc('i.ID')
            ->get();
    }

    public function excludedCandidates(array $filters, ?string $candidateDate = null): Collection
    {
        [$from, $to] = $this->candidateDateBounds($filters, $candidateDate);
        if (!$from && !$to) {
            return collect();
        }

        $otherDaysFilters = array_merge($filters, [
            'file_id' => '',
            'sequences' => '',
            'date_from' => '',
            'date_to' => '',
        ]);
        $query = $this->itemRowsQuery($otherDaysFilters)
            ->whereNotNull('i.dtEffective')
            ->whereNotNull('i.dtCreated');

        if ($from) {
            $query->where('i.dtEffective', '>=', $from);
        }
        if ($to) {
            $query->where('i.dtEffective', '<', $to);
        }

        $query->where(function (Builder $otherDay) use ($from, $to): void {
            if ($from && $to) {
                $otherDay->where('i.dtCreated', '<', $from)
                    ->orWhere('i.dtCreated', '>=', $to);
            } elseif ($from) {
                $otherDay->where('i.dtCreated', '<', $from);
            } elseif ($to) {
                $otherDay->where('i.dtCreated', '>=', $to);
            }
        });

        return $query
            ->orderByDesc('i.dtEffective')
            ->orderByDesc('i.dtCreated')
            ->orderByDesc('i.ID')
            ->get();
    }

    private function itemRowsQuery(array $filters): Builder
    {
        return $this->itemQuery($filters)
            ->select([
                'i.ID as id',
                'i.iProcessingID as processing_id',
                'i.dtCreated as created_at',
                'i.dtEffective as effective_date',
                'i.iLinkedType as type_id',
                't.NameEN as type_name',
                'i.iLinkedID as linked_id',
                'i.iStatus as status_id',
                'i.HolderName as holder_name',
                'i.HolderID as holder_id',
                'i.SourceCode as source_code',
                's.NameEN as source_name',
                'i.BankCode as bank_code',
                'i.BankTransit as bank_transit',
                'i.Notes as notes',
                'f.FileName as file_name',
                'tr.dtEffective as trade_date',
                'tr.dtSettlement as settlement_date',
            ])
            ->selectRaw('i.mAmount as amount')
            ->selectRaw("right(rtrim(coalesce(i.BankAccountNumber, '')), 4) as bank_account_last4");
    }

    private function applyMissingCandidateFilter(Builder $query, array $filters, ?string $candidateDate): void
    {
        [$from, $to] = $this->candidateDateBounds($filters, $candidateDate);

        if (!$from && !$to) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $mismatch) use ($from, $to): void {
            foreach (['i.dtEffective', 'tr.dtSettlement'] as $dateColumn) {
                $mismatch->orWhere(function (Builder $dateMismatch) use ($dateColumn, $from, $to): void {
                    $dateMismatch->whereNotNull($dateColumn);

                    if ($from && $to) {
                        $dateMismatch->where(function (Builder $outside) use ($dateColumn, $from, $to): void {
                            $outside->where($dateColumn, '<', $from)
                                ->orWhere($dateColumn, '>=', $to);
                        });
                    } elseif ($from) {
                        $dateMismatch->where($dateColumn, '<', $from);
                    } elseif ($to) {
                        $dateMismatch->where($dateColumn, '>=', $to);
                    }
                });
            }
        });
    }

    private function candidateDateBounds(array $filters, ?string $candidateDate): array
    {
        if ($candidateDate !== null) {
            return [
                Carbon::parse($candidateDate)->startOfDay(),
                Carbon::parse($candidateDate)->addDay()->startOfDay(),
            ];
        }

        return [
            ($filters['date_from'] ?? '') !== ''
                ? Carbon::parse($filters['date_from'])->startOfDay()
                : null,
            ($filters['date_to'] ?? '') !== ''
                ? Carbon::parse($filters['date_to'])->addDay()->startOfDay()
                : null,
        ];
    }

    public function totals(array $filters): object
    {
        $fileTotals = $this->fileQuery($filters)
            ->selectRaw('count(*) as file_count')
            ->selectRaw('coalesce(sum(case when f.iType = 10 then f.mTotalAmount else -f.mTotalAmount end), 0) as total_amount')
            ->first();

        $itemTotals = $this->itemQuery($filters)
            ->selectRaw('count(*) as item_count')
            ->selectRaw('coalesce(sum(case when i.iLinkedType = 10 then i.mAmount else -i.mAmount end), 0) as item_amount')
            ->first();

        return (object) [
            'file_count' => (int) ($fileTotals->file_count ?? 0),
            'total_amount' => (float) ($fileTotals->total_amount ?? 0),
            'item_count' => (int) ($itemTotals->item_count ?? 0),
            'item_amount' => (float) ($itemTotals->item_amount ?? 0),
        ];
    }

    public function totalsByType(array $filters): Collection
    {
        return $this->fileQuery($filters)
            ->selectRaw("f.iType as type_id, coalesce(t.NameEN, 'Unknown') as type_name, count(*) as file_count")
            ->selectRaw('coalesce(sum(case when f.iType = 10 then f.mTotalAmount else -f.mTotalAmount end), 0) as total_amount')
            ->groupBy('f.iType', 't.NameEN', 't.iOrder')
            ->orderBy('t.iOrder')
            ->orderBy('f.iType')
            ->get();
    }

    public function exportFiles(array $filters): Collection
    {
        return $this->fileQuery($filters)
            ->select([
                'f.ID as id',
                'f.dtCreated as created_at',
                'f.dtEffective as effective_date',
                'f.iType as type_id',
                't.NameEN as type_name',
                'f.iStatus as status_id',
                'f.iTrustBankAccountID as trust_bank_account_id',
                'f.iSequenceNumber as sequence_number',
                'f.iOption as option_id',
                'f.FileName as file_name',
                'f.Notes as notes',
            ])
            ->selectRaw('f.mTotalAmount as total_amount')
            ->selectRaw('(select count(*) from ' . $this->table('UB_EFTItem') . ' ei where ei.iProcessingID = f.ID) as item_count')
            ->orderByDesc('f.dtCreated')
            ->orderByDesc('f.ID')
            ->get();
    }

    public function exportItems(array $filters): Collection
    {
        return $this->itemQuery($filters)
            ->select([
                'i.ID as id',
                'i.iProcessingID as processing_id',
                'i.dtCreated as created_at',
                'i.dtEffective as effective_date',
                'i.iLinkedType as type_id',
                't.NameEN as type_name',
                'i.iLinkedID as linked_id',
                'i.iStatus as status_id',
                'i.HolderName as holder_name',
                'i.HolderID as holder_id',
                'i.SourceCode as source_code',
                's.NameEN as source_name',
                'i.BankCode as bank_code',
                'i.BankTransit as bank_transit',
                'i.Notes as notes',
                'f.FileName as file_name',
                'tr.dtEffective as trade_date',
                'tr.dtSettlement as settlement_date',
            ])
            ->selectRaw('i.mAmount as amount')
            ->selectRaw("right(rtrim(coalesce(i.BankAccountNumber, '')), 4) as bank_account_last4")
            ->orderByDesc('i.dtCreated')
            ->orderByDesc('i.ID')
            ->get();
    }

    public function dailyTotals(string $dateFrom, string $dateTo, string $dateBasis): Collection
    {
        $dateColumn = in_array($dateBasis, ['created_date', 'processing_date'], true)
            ? 'i.dtCreated'
            : 'i.dtEffective';

        return $this->connection()
            ->table($this->table('UB_EFTItem') . ' as i')
            ->join($this->table('UB_EFTFile') . ' as f', 'f.ID', '=', 'i.iProcessingID')
            ->where($dateColumn, '>=', Carbon::parse($dateFrom)->startOfDay())
            ->where($dateColumn, '<', Carbon::parse($dateTo)->addDay()->startOfDay())
            ->selectRaw('cast(' . $dateColumn . ' as date) as total_date')
            ->selectRaw('count(*) as transaction_count')
            ->selectRaw('coalesce(sum(case when i.iLinkedType = 10 then i.mAmount else -i.mAmount end), 0) as net_total')
            ->groupByRaw('cast(' . $dateColumn . ' as date)')
            ->orderBy('total_date')
            ->get();
    }

    /**
     * Return EFT file item totals for the requested file sequence numbers.
     * No date predicate is applied because bank settlement dates can differ
     * from the EFT file's created and effective dates.
     */
    public function totalsBySequences(array $sequenceNumbers): Collection
    {
        $sequenceNumbers = collect($sequenceNumbers)
            ->map(fn($sequence) => trim((string) $sequence))
            ->filter(fn($sequence) => $sequence !== '' && ctype_digit($sequence))
            ->map(fn($sequence) => (int) $sequence)
            ->unique()
            ->values();

        if ($sequenceNumbers->isEmpty()) {
            return collect();
        }

        return $sequenceNumbers
            ->chunk(1000)
            ->flatMap(function (Collection $sequences) {
                return $this->connection()
                    ->table($this->table('UB_EFTFile') . ' as f')
                    ->leftJoin($this->table('UB_EFTItem') . ' as i', 'i.iProcessingID', '=', 'f.ID')
                    ->whereIn('f.iSequenceNumber', $sequences->all())
                    ->selectRaw('f.ID as file_id, f.iSequenceNumber as sequence_number, f.iType as type_id')
                    ->selectRaw('count(i.ID) as transaction_count')
                    ->selectRaw('case when f.iType = 10 then coalesce(sum(i.mAmount), 0) else -coalesce(sum(i.mAmount), 0) end as net_total')
                    ->groupBy('f.ID', 'f.iSequenceNumber', 'f.iType')
                    ->get();
            })
            ->values();
    }

    private function fileQuery(array $filters): Builder
    {
        $query = $this->connection()
            ->table($this->table('UB_EFTFile') . ' as f')
            ->leftJoin($this->table('UB_Def_EFTType') . ' as t', 't.ID', '=', 'f.iType');

        return $this->applyFileFilters($query, $filters);
    }

    private function itemQuery(array $filters): Builder
    {
        $query = $this->connection()
            ->table($this->table('UB_EFTItem') . ' as i')
            ->join($this->table('UB_EFTFile') . ' as f', 'f.ID', '=', 'i.iProcessingID')
            ->leftJoin($this->table('UB_Def_EFTType') . ' as t', 't.ID', '=', 'i.iLinkedType')
            ->leftJoin($this->table('UB_Def_EFTSource') . ' as s', 's.FSCode', '=', 'i.SourceCode')
            ->leftJoin($this->table('UB_TrustTrx') . ' as tr', 'tr.ID', '=', 'i.iLinkedID');

        $this->applyFileFilters($query, $filters, true);

        if (($filters['item_status'] ?? '') !== '') {
            $query->where('i.iStatus', (int) $filters['item_status']);
        }

        if (($filters['item_search'] ?? '') !== '') {
            $search = '%' . $filters['item_search'] . '%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('i.HolderName', 'like', $search)
                    ->orWhere('i.HolderID', 'like', $search)
                    ->orWhere('i.Notes', 'like', $search)
                    ->orWhere('f.FileName', 'like', $search);
            });
        }

        if (($filters['amount_contains'] ?? '') !== '') {
            $query->whereRaw(
                'CAST(ABS(COALESCE(i.mAmount, 0)) AS VARCHAR(50)) LIKE ?',
                ['%'.$filters['amount_contains'].'%']
            );
        }

        return $query;
    }

    private function applyFileFilters(Builder $query, array $filters, bool $itemQuery = false): Builder
    {
        if (($filters['file_id'] ?? '') !== '') {
            $query->where('f.ID', (int) $filters['file_id']);
        }

        $sequences = collect(preg_split('/[,\s]+/', (string) ($filters['sequences'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn($sequence) => ctype_digit((string) $sequence))
            ->map(fn($sequence) => (int) $sequence)
            ->unique()
            ->values();
        if ($sequences->isNotEmpty()) {
            $query->whereIn('f.iSequenceNumber', $sequences->all());
        }

        $dateColumn = ($filters['date_basis'] ?? 'created') === 'effective'
            ? ($itemQuery ? 'i.dtEffective' : 'f.dtEffective')
            : ($itemQuery ? 'i.dtCreated' : 'f.dtCreated');

        if (($filters['date_from'] ?? '') !== '') {
            $query->where($dateColumn, '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (($filters['date_to'] ?? '') !== '') {
            $query->where($dateColumn, '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        if (($filters['type'] ?? '') !== '') {
            $query->where('f.iType', (int) $filters['type']);
        }

        if (($filters['file_status'] ?? '') !== '') {
            $query->where('f.iStatus', (int) $filters['file_status']);
        }

        if (($filters['file_search'] ?? '') !== '') {
            $fileSearch = (string) $filters['file_search'];
            $search = '%' . $fileSearch . '%';
            $query->where(function (Builder $nested) use ($fileSearch, $search): void {
                $nested->where('f.FileName', 'like', $search)
                    ->orWhere('f.Notes', 'like', $search);

                if (ctype_digit($fileSearch)) {
                    $nested->orWhere('f.ID', (int) $fileSearch)
                        ->orWhere('f.iSequenceNumber', (int) $fileSearch);
                }
            });
        }

        return $query;
    }

    private function connection()
    {
        return DB::connection(self::CONNECTION);
    }

    private function table(string $table): string
    {
        return (env('VIEFUND_DB_SCHEMA', 'dbo') ?: 'dbo') . '.' . $table;
    }

}
