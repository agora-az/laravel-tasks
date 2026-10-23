<?php

namespace App\Jobs;

use App\Models\VieFundTransactionMirrorSync;
use App\Services\Reconciliation\AllTransactionMatchStatusResolver;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncVieFundTransactionMirror implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('viefund-transaction-mirror-sync'))
            ->releaseAfter(10)
            ->expireAfter(300)];
    }

    public function handle(
        VieFundAvailabilityWindow $availability,
        VieFundRemoteService $remoteService,
        AllTransactionMatchStatusResolver $matchStatusResolver,
        VieFundTransactionMirrorManager $manager
    ): void {
        $sync = VieFundTransactionMirrorSync::singleton()->fresh();
        if (
            !in_array($sync->state, ['queued', 'running', 'maintenance_wait', 'catching_up', 'verifying'], true)
            || !$sync->sync_generation
        ) {
            return;
        }
        if ($availability->isBlocked()) {
            $sync->forceFill(['state' => 'maintenance_wait'])->save();
            $manager->dispatch($availability->nextAvailableAt());
            return;
        }
        if ($sync->phase === 'verification') {
            $sync->forceFill(['state' => 'verifying', 'last_error' => null])->save();
            $this->verifyOrContinue($sync, $remoteService, $manager);
            return;
        }

        $sync->forceFill([
            'state' => 'running',
            'last_batch_started_at' => now(),
            'last_error' => null,
        ])->save();
        $batchStartedAt = microtime(true);

        try {
            if ($sync->expected_rows === null) {
                $expected = $this->countRemoteRows($sync, $remoteService);
                $sync->forceFill(['expected_rows' => $expected])->save();
                $manager->dispatch();
                return;
            }

            $rows = $remoteService->fetchAllTransactionMirrorRowsAfter(
                $this->filters($sync, (string) $sync->current_currency),
                $sync->cursor,
                max(100, min(5000, (int) config('viefund.mirror.batch_size', 1000)))
            );
        } catch (Throwable $exception) {
            if ($availability->isBlocked()) {
                $sync->forceFill(['state' => 'maintenance_wait'])->save();
                $manager->dispatch($availability->nextAvailableAt());
                return;
            }
            throw $exception;
        }

        if ($rows->isEmpty()) {
            $this->advanceOrComplete($sync, $manager);
            return;
        }

        $matches = $matchStatusResolver->resolve($rows);
        $now = now();
        $generation = (string) $sync->sync_generation;
        $mapped = $rows->map(function (object $row) use ($matches, $now, $generation): array {
            $cashId = (int) $row->cash_transaction_id;
            $match = $matches->get((string) $cashId, [
                'match_status' => 'Unknown',
                'has_eft_match' => false,
                'has_agra_fsp_match' => false,
                'has_7960_fsp_match' => false,
                'has_asm_match' => false,
                'enrichment' => [],
            ]);
            $payload = (array) $row;

            return [
                'cash_transaction_id' => $cashId,
                'transaction_id' => (string) $row->transaction_id,
                'fund_transaction_id' => $row->fund_transaction_id ? (int) $row->fund_transaction_id : null,
                'trust_transaction_id' => $row->trust_transaction_id ? (int) $row->trust_transaction_id : null,
                'ledger_relationship' => $this->nullableString($row->ledger_relationship ?? null),
                'source_id' => $this->nullableString($row->source_id ?? null),
                'fund_id' => $this->nullableString($row->fund_id ?? null),
                'customer_name' => $this->nullableString($row->customer_name ?? null),
                'plan_account_id' => $this->nullableString($row->plan_account_id ?? null),
                'transaction_type' => $this->nullableString($row->transaction_type ?? null),
                'cash_status_id' => isset($row->cash_status_id) ? (int) $row->cash_status_id : null,
                'cash_status' => $this->nullableString($row->status ?? null),
                'trust_status' => $this->nullableString($row->trust_status ?? null),
                'notes' => $this->nullableString($row->notes ?? null),
                'created_date' => $row->created_date ?? null,
                'trade_date' => $row->trade_date ?? null,
                'processing_date' => $row->processing_date ?? null,
                'settlement_date' => $row->settlement_date ?? null,
                'currency_code' => $this->nullableString($row->currency_code ?? null),
                'amount' => $row->amount ?? null,
                'match_status' => (string) $match['match_status'],
                'has_eft_match' => (bool) $match['has_eft_match'],
                'has_agra_fsp_match' => (bool) $match['has_agra_fsp_match'],
                'has_7960_fsp_match' => (bool) $match['has_7960_fsp_match'],
                'has_asm_match' => (bool) $match['has_asm_match'],
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'enrichment' => json_encode($match['enrichment'] ?? [], JSON_THROW_ON_ERROR),
                'sync_generation' => $generation,
                'source_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'source_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
        $last = $rows->last();
        $cursor = [
            'basis_date' => (string) $last->basis_date,
            'sort_id' => (int) $last->sort_id,
            'transaction_id' => (string) $last->transaction_id,
            'plan_account_id' => (string) $last->plan_account_id,
        ];
        $duration = max(0.001, microtime(true) - $batchStartedAt);

        DB::transaction(function () use ($sync, $mapped, $cursor, $duration): void {
            $locked = VieFundTransactionMirrorSync::query()->lockForUpdate()->findOrFail($sync->id);
            if ($locked->state === 'paused' || $locked->sync_generation !== $sync->sync_generation) {
                return;
            }
            $mapped->chunk(500)->each(fn(Collection $chunk) => DB::table('viefund_transaction_mirror_rows')->upsert(
                $chunk->all(),
                ['cash_transaction_id'],
                array_values(array_diff(array_keys($chunk->first()), ['cash_transaction_id', 'created_at']))
            ));
            $metrics = array_slice(array_merge((array) $locked->batch_metrics, [[
                'rows' => $mapped->count(),
                'seconds' => round($duration, 3),
                'completed_at' => now()->toIso8601String(),
            ]]), -10);
            $locked->forceFill([
                'cursor' => $cursor,
                'rows_processed' => (int) $locked->rows_processed + $mapped->count(),
                'active_seconds' => (float) $locked->active_seconds + $duration,
                'batch_metrics' => $metrics,
                'last_batch_completed_at' => now(),
            ])->save();
        });

        $manager->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        VieFundTransactionMirrorSync::query()
            ->whereKey(VieFundTransactionMirrorSync::SINGLETON_ID)
            ->whereNotIn('state', ['paused', 'ready'])
            ->update([
                'state' => 'failed',
                'last_error' => $exception?->getMessage() ?: 'VieFund transaction mirror synchronization failed.',
                'updated_at' => now(),
            ]);
    }

    private function advanceOrComplete(
        VieFundTransactionMirrorSync $sync,
        VieFundTransactionMirrorManager $manager
    ): void {
        $currencies = array_values((array) $sync->currencies);
        $index = array_search($sync->current_currency, $currencies, true);
        $nextCurrency = $index !== false ? ($currencies[$index + 1] ?? null) : null;
        if ($nextCurrency !== null) {
            $sync->forceFill(['current_currency' => $nextCurrency, 'cursor' => null])->save();
            $manager->dispatch();
            return;
        }

        $sync->forceFill(['state' => 'verifying', 'phase' => 'verification'])->save();
        $manager->dispatch();
    }

    private function verifyOrContinue(
        VieFundTransactionMirrorSync $sync,
        VieFundRemoteService $remoteService,
        VieFundTransactionMirrorManager $manager
    ): void {
        $currencies = array_values((array) $sync->currencies);
        $generation = (string) $sync->sync_generation;
        $verificationScope = fn() => DB::table('viefund_transaction_mirror_rows')
            ->whereBetween('created_date', [
                $sync->target_from_date->copy()->startOfDay(),
                $sync->target_through_date->copy()->endOfDay(),
            ])
            ->whereIn('currency_code', (array) $sync->currencies)
            ->whereIn('cash_status_id', (array) $sync->status_ids);
        $staleIds = $verificationScope()
            ->where('sync_generation', '<>', $generation)
            ->limit(max(100, min(5000, (int) config('viefund.mirror.batch_size', 1000))))
            ->pluck('cash_transaction_id');

        if ($staleIds->isNotEmpty()) {
            DB::table('viefund_transaction_mirror_rows')
                ->whereIn('cash_transaction_id', $staleIds)
                ->where('sync_generation', '<>', $generation)
                ->delete();
            $manager->dispatch();
            return;
        }

        $verifiedCount = $verificationScope()
            ->where('sync_generation', $generation)
            ->count();
        $expectedCount = $this->countRemoteRows($sync, $remoteService);

        if ($expectedCount !== (int) $sync->expected_rows) {
            $sync->forceFill(['expected_rows' => $expectedCount])->save();
        }

        if ($verifiedCount !== $expectedCount) {
            throw new \RuntimeException(sprintf(
                'Mirror verification failed: VieFund currently reports %d rows but MySQL contains %d for the synchronized range.',
                $expectedCount,
                $verifiedCount
            ));
        }

        if ($sync->mode === 'initial') {
            $from = now()
                ->subDays(max(1, (int) config('viefund.mirror.incremental_lookback_days', 90)) - 1)
                ->toDateString();
            $sync->forceFill([
                'mode' => 'catchup',
                'state' => 'catching_up',
                'phase' => 'post_backfill_catchup',
                'target_from_date' => $from,
                'target_through_date' => now()->toDateString(),
                'current_currency' => $currencies[0],
                'cursor' => null,
                'sync_generation' => (string) \Illuminate\Support\Str::uuid(),
                'rows_processed' => 0,
                'expected_rows' => null,
                'active_seconds' => 0,
                'batch_metrics' => [],
                'verified_at' => now(),
            ])->save();
            $manager->dispatch();
            return;
        }

        $sync->forceFill([
            'state' => 'ready',
            'phase' => 'ready',
            'cursor' => null,
            'current_currency' => null,
            'ready_at' => now(),
            'verified_at' => now(),
            'synced_through_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function countRemoteRows(
        VieFundTransactionMirrorSync $sync,
        VieFundRemoteService $remoteService
    ): int {
        $expected = 0;
        foreach ((array) $sync->currencies as $currency) {
            $expected += $remoteService->countAllTransactions(
                null,
                $this->filters($sync, (string) $currency)
            );
        }

        return $expected;
    }

    private function filters(VieFundTransactionMirrorSync $sync, string $currency): array
    {
        return [
            'date_basis' => 'create_date',
            'date_from' => $sync->target_from_date->toDateString(),
            'date_to' => $sync->target_through_date->toDateString(),
            'currency_code' => $currency,
            'status_ids' => (array) $sync->status_ids,
            'output_order' => 'asc',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
