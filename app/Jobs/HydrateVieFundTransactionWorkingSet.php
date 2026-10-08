<?php

namespace App\Jobs;

use App\Models\VieFundTransactionWorkingSet;
use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundWorkingSetBalanceService;
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
        AllTransactionMatchStatusResolver $matchStatusResolver,
        RuntimeSettings $runtimeSettings,
        VieFundWorkingSetBalanceService $balanceService
    ): void {
        $workingSet = VieFundTransactionWorkingSet::query()->find($this->workingSetId);
        if (!$workingSet
            || $workingSet->build_generation !== $this->generation
            || !in_array($workingSet->state, ['warming', 'refreshing'], true)) {
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
        if ($workingSet->total_rows === null) {
            $totalRows = $remoteService->countAllTransactions(null, $filters);
            $updated = VieFundTransactionWorkingSet::query()
                ->whereKey($this->workingSetId)
                ->where('build_generation', $this->generation)
                ->whereIn('state', ['warming', 'refreshing'])
                ->update(['total_rows' => $totalRows]);
            if ($updated === 0) {
                return;
            }
            self::dispatch($this->workingSetId, $this->generation, $this->cursor)
                ->onConnection('database');

            return;
        }
        $batchSize = $runtimeSettings->get('viefund.working_set.batch_size');
        $rows = $remoteService->fetchAllTransactionExportRowsAfter(null, $filters, $this->cursor, $batchSize);

        if ($rows->isEmpty()) {
            $this->complete($workingSet, $balanceService);
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

        $last = $rows->last();
        $nextCursor = [
            'basis_date' => (string) $last->basis_date,
            'sort_id' => (int) $last->sort_id,
            'transaction_id' => (string) $last->transaction_id,
            'plan_account_id' => (string) $last->plan_account_id,
        ];
        $committed = DB::transaction(function () use ($cachedRows, $nextCursor): bool {
            $locked = VieFundTransactionWorkingSet::query()->lockForUpdate()->find($this->workingSetId);
            if (!$locked
                || $locked->build_generation !== $this->generation
                || !in_array($locked->state, ['warming', 'refreshing'], true)) {
                return false;
            }

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
            $locked->forceFill([
                'rows_cached' => $rowsCached,
                'hydration_cursor' => $nextCursor,
            ])->save();

            return true;
        });

        if (!$committed) {
            return;
        }

        self::dispatch($this->workingSetId, $this->generation, $nextCursor)
            ->onConnection('database');
    }

    public function failed(?Throwable $exception): void
    {
        VieFundTransactionWorkingSet::query()
            ->whereKey($this->workingSetId)
            ->where('build_generation', $this->generation)
            ->whereIn('state', ['warming', 'refreshing'])
            ->update([
                'state' => 'failed',
                'last_error' => $exception?->getMessage() ?: 'Working-set hydration failed.',
                'updated_at' => now(),
            ]);
    }

    private function complete(
        VieFundTransactionWorkingSet $workingSet,
        VieFundWorkingSetBalanceService $balanceService
    ): void
    {
        $current = VieFundTransactionWorkingSet::query()->find($workingSet->id);
        if (!$current
            || $current->build_generation !== $this->generation
            || !in_array($current->state, ['warming', 'refreshing'], true)) {
            return;
        }

        $balanceReport = $balanceService->build($current);

        DB::transaction(function () use ($current, $balanceReport): void {
            $locked = VieFundTransactionWorkingSet::query()->lockForUpdate()->find($current->id);
            if (!$locked
                || $locked->build_generation !== $this->generation
                || !in_array($locked->state, ['warming', 'refreshing'], true)) {
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
                'hydration_cursor' => null,
                'balance_generation' => $this->generation,
                'balance_report' => $balanceReport,
                'balance_calculated_at' => now(),
                'rows_cached' => $totalRows,
                'total_rows' => $totalRows,
                'ready_at' => now(),
                'refreshed_at' => now(),
                'paused_at' => null,
                'expires_at' => now()->addMinutes(app(RuntimeSettings::class)->get('viefund.working_set.ttl_minutes')),
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
