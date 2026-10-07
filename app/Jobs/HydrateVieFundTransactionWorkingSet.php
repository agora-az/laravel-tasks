<?php

namespace App\Jobs;

use App\Models\VieFundTransactionWorkingSet;
use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class HydrateVieFundTransactionWorkingSet implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 110;

    public function __construct(
        public readonly int $workingSetId,
        public readonly string $generation,
        public readonly ?array $cursor = null
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("viefund-working-set:{$this->workingSetId}:{$this->generation}"))
            ->releaseAfter(5)
            ->expireAfter(150)];
    }

    public function handle(
        VieFundRemoteService $remoteService,
        AllTransactionMatchStatusResolver $matchStatusResolver
    ): void {
        $workingSet = VieFundTransactionWorkingSet::query()->find($this->workingSetId);
        if (!$workingSet || $workingSet->build_generation !== $this->generation) {
            return;
        }

        $filters = [
            'date_basis' => $workingSet->date_basis,
            'date_from' => $workingSet->date_from->toDateString(),
            'date_to' => $workingSet->date_to->toDateString(),
            'currency_code' => $workingSet->currency_code,
            'status_ids' => $workingSet->status_ids,
            'output_order' => 'asc',
        ];
        $batchSize = max(100, min(5000, (int) config('viefund.all_transactions_working_set.batch_size', 1000)));
        $rows = $remoteService->fetchAllTransactionExportRowsAfter(null, $filters, $this->cursor, $batchSize);

        if ($rows->isEmpty()) {
            $this->complete($workingSet);
            return;
        }

        $matchStatuses = $matchStatusResolver->resolve($rows);
        $now = now();
        $cachedRows = $rows->map(function ($row) use ($matchStatuses, $now): array {
            $cashId = (int) $row->cash_transaction_id;
            $match = $matchStatuses->get((string) $cashId, [
                'match_status' => 'Unknown',
                'has_eft_match' => false,
                'has_agra_fsp_match' => false,
                'has_7960_fsp_match' => false,
            ]);

            return [
                'working_set_id' => $this->workingSetId,
                'generation' => $this->generation,
                'cash_transaction_id' => $cashId,
                'trust_transaction_id' => $row->trust_transaction_id ? (int) $row->trust_transaction_id : null,
                'fund_transaction_id' => !empty($row->fund_transaction_id) ? (int) $row->fund_transaction_id : null,
                'transaction_id' => (string) $row->transaction_id,
                'source_id' => $this->nullableString($row->source_id ?? null),
                'customer_name' => $this->nullableString($row->customer_name ?? null),
                'plan_account_id' => $this->nullableString($row->plan_account_id ?? null),
                'transaction_type' => $this->nullableString($row->transaction_type ?? null),
                'cash_status' => $this->nullableString($row->status ?? null),
                'trust_status' => $this->nullableString($row->trust_status ?? null),
                'created_date' => $row->created_date ?? null,
                'trade_date' => $row->trade_date ?? null,
                'processing_date' => $row->processing_date ?? null,
                'settlement_date' => $row->settlement_date ?? null,
                'basis_date' => $row->basis_date ?? null,
                'currency_code' => $this->nullableString($row->currency_code ?? null),
                'amount' => $row->amount ?? null,
                'match_status' => (string) $match['match_status'],
                'has_eft_match' => (bool) $match['has_eft_match'],
                'has_agra_fsp_match' => (bool) $match['has_agra_fsp_match'],
                'has_7960_fsp_match' => (bool) $match['has_7960_fsp_match'],
                'payload' => json_encode((array) $row, JSON_THROW_ON_ERROR),
                'enrichment' => json_encode($match['enrichment'] ?? [], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

        DB::transaction(function () use ($cachedRows): void {
            $cachedRows->chunk(500)->each(fn(Collection $chunk) => DB::table('viefund_transaction_working_set_rows')->upsert(
                $chunk->all(),
                ['working_set_id', 'generation', 'cash_transaction_id'],
                [
                    'trust_transaction_id', 'fund_transaction_id', 'transaction_id', 'source_id',
                    'customer_name', 'plan_account_id', 'transaction_type', 'cash_status', 'trust_status',
                    'created_date', 'trade_date', 'processing_date', 'settlement_date', 'basis_date',
                    'currency_code', 'amount', 'match_status', 'has_eft_match', 'has_agra_fsp_match',
                    'has_7960_fsp_match', 'payload', 'enrichment', 'updated_at',
                ]
            ));

            $rowsCached = DB::table('viefund_transaction_working_set_rows')
                ->where('working_set_id', $this->workingSetId)
                ->where('generation', $this->generation)
                ->count();
            VieFundTransactionWorkingSet::query()
                ->whereKey($this->workingSetId)
                ->where('build_generation', $this->generation)
                ->update(['rows_cached' => $rowsCached]);
        });

        $last = $rows->last();
        $nextCursor = [
            'basis_date' => (string) $last->basis_date,
            'sort_id' => (int) $last->sort_id,
            'transaction_id' => (string) $last->transaction_id,
            'plan_account_id' => (string) $last->plan_account_id,
        ];
        self::dispatch($this->workingSetId, $this->generation, $nextCursor)
            ->onConnection('database');
    }

    public function failed(?Throwable $exception): void
    {
        VieFundTransactionWorkingSet::query()
            ->whereKey($this->workingSetId)
            ->where('build_generation', $this->generation)
            ->update([
                'state' => 'failed',
                'last_error' => $exception?->getMessage() ?: 'Working-set hydration failed.',
                'updated_at' => now(),
            ]);
    }

    private function complete(VieFundTransactionWorkingSet $workingSet): void
    {
        DB::transaction(function () use ($workingSet): void {
            $locked = VieFundTransactionWorkingSet::query()->lockForUpdate()->find($workingSet->id);
            if (!$locked || $locked->build_generation !== $this->generation) {
                return;
            }

            $totalRows = DB::table('viefund_transaction_working_set_rows')
                ->where('working_set_id', $this->workingSetId)
                ->where('generation', $this->generation)
                ->count();
            DB::table('viefund_transaction_working_set_rows')
                ->where('working_set_id', $this->workingSetId)
                ->where('generation', '<>', $this->generation)
                ->delete();
            $locked->forceFill([
                'state' => 'ready',
                'active_generation' => $this->generation,
                'build_generation' => null,
                'rows_cached' => $totalRows,
                'total_rows' => $totalRows,
                'ready_at' => now(),
                'refreshed_at' => now(),
                'expires_at' => now()->addMinutes(max(15, (int) config('viefund.all_transactions_working_set.ttl_minutes', 1440))),
                'last_error' => null,
            ])->save();
        });
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
