<?php

namespace App\Services\VieFund;

use App\Jobs\BuildVieFundTransactionSummary;
use App\Models\VieFundTransactionMirrorSync;
use App\Models\VieFundTransactionSummary;
use App\Models\VieFundTransactionWorkingSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class VieFundTransactionSummaryService
{
    public const BATCH_SIZE = 1000;

    public function __construct(
        private readonly VieFundTransactionWorkingSetQuery $workingSetQuery,
        private readonly VieFundTransactionMirrorQuery $mirrorQuery
    ) {}

    public function forWorkingSet(
        VieFundTransactionWorkingSet $workingSet,
        ?string $search,
        array $filters,
        bool $retry = false
    ): VieFundTransactionSummary {
        if (!$workingSet->isReady()) {
            throw new RuntimeException('Complete the period cache before calculating its summary.');
        }
        $normalized = $this->normalize($filters);
        $scopeOnly = array_diff_key($normalized, array_flip([
            'date_basis', 'date_from', 'date_to', 'currency_code', 'status_ids',
        ])) === [];
        return $this->ensure([
            'source' => 'working_set', 'id' => $workingSet->id, 'generation' => $workingSet->active_generation,
        ], $search, $normalized, $retry,
            $scopeOnly && trim((string) $search) === '' && $workingSet->build_generation === null
                ? (int) $workingSet->rows_cached : null);
    }

    public function forMirror(
        VieFundTransactionMirrorSync $sync,
        ?string $search,
        array $filters,
        bool $retry = false
    ): VieFundTransactionSummary {
        if (!$sync->isReady()) {
            throw new RuntimeException('Wait for mirror synchronization before calculating its summary.');
        }
        return $this->ensure($this->mirrorSnapshot($sync), $search, $this->normalize($filters), $retry, null);
    }

    public function status(VieFundTransactionSummary $summary): array
    {
        $stalled = in_array($summary->state, ['queued', 'processing'], true)
            && $summary->updated_at->lt(now()->subMinutes(10));
        $processed = number_format($summary->processed_rows);
        $total = $summary->expected_rows === null ? '' : ' of '.number_format($summary->expected_rows);
        $message = match ($summary->state) {
            'complete' => 'Summary complete.',
            'failed' => 'The background summary failed. Use Retry summaries to continue from its saved progress.',
            'queued' => 'Summary queued for background calculation.',
            default => "Building summaries in the background: {$processed}{$total} transactions processed.",
        };
        if ($stalled) {
            $message = 'Summary progress has stopped. Check the cache worker, then use Retry summaries to continue.';
        }
        return [
            'state' => $stalled ? 'failed' : $summary->state,
            'message' => $message,
            'processed_transactions' => $summary->processed_rows,
            'total_transactions' => $summary->expected_rows,
            'retryable' => $stalled || $summary->state === 'failed',
        ];
    }

    public function advance(int $id, string $token, int $cursor): ?int
    {
        return DB::transaction(function () use ($id, $token, $cursor): ?int {
            $summary = VieFundTransactionSummary::query()->lockForUpdate()->find($id);
            if (!$summary || $summary->dispatch_token !== $token
                || !in_array($summary->state, ['queued', 'processing'], true)) {
                return null;
            }
            if ($summary->cursor !== $cursor) {
                return $summary->cursor;
            }
            $source = $summary->source_snapshot;
            if ($source['source'] === 'working_set') {
                $workingSet = VieFundTransactionWorkingSet::query()->sharedLock()->find($source['id']);
                if (!$workingSet?->isReady() || $workingSet->active_generation !== $source['generation']) {
                    throw new RuntimeException('The period cache changed. Refresh the page to calculate a current summary.');
                }
                $rows = $this->workingSetQuery->summaryRowsAfter(
                    $workingSet, $summary->search, $summary->filters, $cursor, self::BATCH_SIZE
                );
            } else {
                $sync = VieFundTransactionMirrorSync::query()->sharedLock()->find(VieFundTransactionMirrorSync::SINGLETON_ID);
                ksort($source);
                if (!$sync?->isReady() || $this->mirrorSnapshot($sync) !== $source) {
                    throw new RuntimeException('The mirror changed. Refresh the page to calculate a current summary.');
                }
                $rows = $this->mirrorQuery->summaryRowsAfter(
                    $summary->search, $summary->filters, $cursor, self::BATCH_SIZE
                );
            }

            $keys = [];
            $key = static fn(string $status, string $field, string $record): string =>
                hash('sha256', json_encode([$status, $field, $record], JSON_THROW_ON_ERROR));
            $collect = new VieFundMatchStatusSummaryAccumulator(
                static function (string $status, string $field, string $record) use (&$keys, $key): bool {
                    $keys[$key($status, $field, $record)] = true;
                    return false;
                }
            );
            $collect->addRows($rows);
            $seen = [];
            foreach (array_chunk(array_keys($keys), 500) as $chunk) {
                foreach (DB::table('viefund_transaction_summary_records')->where('summary_id', $id)
                    ->whereIn('record_hash', $chunk)->pluck('record_hash') as $hash) {
                    $seen[$hash] = true;
                }
            }
            $new = [];
            $accumulator = new VieFundMatchStatusSummaryAccumulator(
                static function (string $status, string $field, string $record) use (&$seen, &$new, $key): bool {
                    $hash = $key($status, $field, $record);
                    if (isset($seen[$hash])) {
                        return false;
                    }
                    $seen[$hash] = true;
                    $new[] = $hash;
                    return true;
                },
                $summary->totals
            );
            $accumulator->addRows($rows);
            foreach (array_chunk($new, 500) as $chunk) {
                DB::table('viefund_transaction_summary_records')->insert(array_map(
                    static fn(string $hash): array => ['summary_id' => $id, 'record_hash' => $hash], $chunk
                ));
            }
            $complete = $rows->count() < self::BATCH_SIZE;
            $next = $rows->isEmpty() ? $cursor : (int) $rows->last()->cash_transaction_id;
            $summary->forceFill([
                'state' => $complete ? 'complete' : 'processing',
                'cursor' => $next,
                'processed_rows' => $summary->processed_rows + $rows->count(),
                'totals' => $accumulator->totals(),
                'result' => $complete ? $accumulator->result() : null,
                'last_error' => null,
            ])->save();
            return $complete ? null : $next;
        });
    }

    public function prewarm(VieFundTransactionWorkingSet $workingSet): void
    {
        $this->forWorkingSet($workingSet, null, [
            'date_basis' => $workingSet->date_basis,
            'date_from' => $workingSet->date_from->toDateString(),
            'date_to' => $workingSet->date_to->toDateString(),
            'currency_code' => $workingSet->currency_code,
            'status_ids' => $workingSet->status_ids,
        ]);
    }

    public function prune(): int
    {
        return VieFundTransactionSummary::query()->where('updated_at', '<', now()->subDays(14))->delete();
    }

    private function ensure(array $source, ?string $search, array $filters, bool $retry, ?int $expected): VieFundTransactionSummary
    {
        $search = trim((string) $search);
        $signature = hash('sha256', json_encode(['version' => 1, $source, $search, $filters], JSON_THROW_ON_ERROR));
        if (!$retry) {
            $existing = VieFundTransactionSummary::query()->where('signature', $signature)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $dispatch = false;
        $summary = DB::transaction(function () use ($source, $search, $filters, $signature, $expected, $retry, &$dispatch) {
            $summary = VieFundTransactionSummary::query()->firstOrCreate(['signature' => $signature], [
                'working_set_id' => $source['source'] === 'working_set' ? $source['id'] : null,
                'source_snapshot' => $source, 'search' => $search, 'filters' => $filters,
                'dispatch_token' => (string) Str::uuid(), 'expected_rows' => $expected,
                'totals' => (new VieFundMatchStatusSummaryAccumulator())->totals(),
            ]);
            $dispatch = $summary->wasRecentlyCreated;
            $summary = VieFundTransactionSummary::query()->lockForUpdate()->findOrFail($summary->id);
            if ($retry && ($summary->state === 'failed'
                || in_array($summary->state, ['queued', 'processing'], true)
                    && $summary->updated_at->lt(now()->subMinutes(10)))) {
                $summary->forceFill([
                    'state' => 'queued', 'dispatch_token' => (string) Str::uuid(), 'last_error' => null,
                ])->save();
                $dispatch = true;
            }
            return $summary;
        });
        if ($dispatch) {
            BuildVieFundTransactionSummary::dispatch($summary->id, $summary->dispatch_token, $summary->cursor);
        }
        return $summary;
    }

    private function mirrorSnapshot(VieFundTransactionMirrorSync $sync): array
    {
        $snapshot = [
            'source' => 'mirror',
            'generation' => $sync->sync_generation,
            'verified_at' => $sync->verified_at?->toIso8601String(),
            'synced_through_at' => $sync->synced_through_at?->toIso8601String(),
            'fund_ids_verified_at' => $sync->fund_ids_verified_at?->toIso8601String(),
            'cashagch_verified_at' => $sync->cashagch_verified_at?->toIso8601String(),
        ];
        ksort($snapshot);
        return $snapshot;
    }

    private function normalize(array $filters): array
    {
        $filters = array_intersect_key($filters, array_flip([
            'customer_name', 'plan_account_id', 'trx_id', 'source_id', 'fund_id', 'date_from', 'date_to',
            'date_basis', 'currency_code', 'status_ids', 'trx_type', 'has_reconciliation_match',
            'has_agra_fsp_match', 'has_7960_fsp_match', 'has_asm_match',
        ]));
        $filters = array_filter($filters, static fn($value) => $value !== null && $value !== ''
            && $value !== [] && $value !== false);
        foreach ($filters as &$value) {
            if (is_array($value)) {
                $value = array_values(array_unique($value));
                sort($value);
            }
        }
        unset($value);
        ksort($filters);
        return $filters;
    }
}
