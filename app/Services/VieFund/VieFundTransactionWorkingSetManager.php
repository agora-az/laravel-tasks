<?php

namespace App\Services\VieFund;

use App\Jobs\HydrateVieFundTransactionWorkingSet;
use App\Models\VieFundTransactionWorkingSet;
use App\Services\RuntimeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class VieFundTransactionWorkingSetManager
{
    private const CACHE_VERSION = 3;

    public function __construct(private readonly RuntimeSettings $runtimeSettings)
    {
    }

    public function ensure(array $filters): ?VieFundTransactionWorkingSet
    {
        if (!$this->available()) {
            return null;
        }

        $scope = $this->scope($filters);
        $scopeHash = $this->scopeHash($filters);
        $shouldDispatch = false;
        $generation = null;

        $workingSet = DB::transaction(function () use ($scope, $scopeHash, &$shouldDispatch, &$generation) {
            $workingSet = VieFundTransactionWorkingSet::query()->firstOrCreate(
                ['scope_hash' => $scopeHash],
                array_merge($scope, [
                    'state' => 'warming',
                    'expires_at' => now()->addMinutes($this->ttlMinutes()),
                ])
            );
            $workingSet = VieFundTransactionWorkingSet::query()->lockForUpdate()->findOrFail($workingSet->id);
            $fresh = $workingSet->active_generation
                && $workingSet->expires_at
                && $workingSet->expires_at->isFuture();

            if ($fresh || in_array($workingSet->state, ['warming', 'refreshing'], true) && $workingSet->build_generation) {
                return $workingSet;
            }
            if (in_array($workingSet->state, ['paused', 'failed'], true) && $workingSet->build_generation) {
                return $workingSet;
            }

            $generation = (string) Str::uuid();
            $shouldDispatch = true;
            $workingSet->forceFill([
                'state' => $workingSet->active_generation ? 'refreshing' : 'warming',
                'build_generation' => $generation,
                'hydration_cursor' => null,
                'rows_cached' => 0,
                'total_rows' => null,
                'last_error' => null,
                'started_at' => now(),
                'paused_at' => null,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ])->save();

            return $workingSet;
        });

        if ($shouldDispatch && $generation !== null) {
            HydrateVieFundTransactionWorkingSet::dispatch($workingSet->id, $generation)
                ->onConnection('database');
        }

        return $workingSet->fresh();
    }

    public function pause(VieFundTransactionWorkingSet $workingSet): VieFundTransactionWorkingSet
    {
        DB::transaction(function () use ($workingSet): void {
            $locked = VieFundTransactionWorkingSet::query()->lockForUpdate()->findOrFail($workingSet->id);
            if (!$locked->build_generation || !in_array($locked->state, ['warming', 'refreshing'], true)) {
                return;
            }

            $locked->forceFill([
                'state' => 'paused',
                'paused_at' => now(),
            ])->save();
        });

        return $workingSet->fresh();
    }

    public function resume(VieFundTransactionWorkingSet $workingSet): VieFundTransactionWorkingSet
    {
        $dispatch = DB::transaction(function () use ($workingSet): ?array {
            $locked = VieFundTransactionWorkingSet::query()->lockForUpdate()->findOrFail($workingSet->id);
            if (!$locked->build_generation || !in_array($locked->state, ['paused', 'failed'], true)) {
                return null;
            }

            $pausedSince = $locked->state === 'paused' ? $locked->paused_at : $locked->updated_at;
            $startedAt = $locked->started_at;
            if ($startedAt && $pausedSince) {
                $startedAt = $startedAt->copy()->addSeconds($pausedSince->diffInSeconds(now()));
            }

            $locked->forceFill([
                'state' => $locked->active_generation ? 'refreshing' : 'warming',
                'paused_at' => null,
                'started_at' => $startedAt,
                'last_error' => null,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ])->save();

            return [
                'generation' => $locked->build_generation,
                'cursor' => $locked->hydration_cursor,
            ];
        });

        if ($dispatch) {
            HydrateVieFundTransactionWorkingSet::dispatch(
                $workingSet->id,
                $dispatch['generation'],
                $dispatch['cursor']
            )->onConnection('database');
        }

        return $workingSet->fresh();
    }

    public function scope(array $filters): array
    {
        $statusIds = array_values(array_unique(array_map('intval', (array) ($filters['status_ids'] ?? [6]))));
        sort($statusIds);

        return [
            'date_basis' => (string) ($filters['date_basis'] ?? 'settlement_date'),
            'date_from' => (string) ($filters['date_from'] ?? now()->toDateString()),
            'date_to' => (string) ($filters['date_to'] ?? now()->toDateString()),
            'currency_code' => (string) ($filters['currency_code'] ?? '00'),
            'status_ids' => $statusIds ?: [6],
        ];
    }

    public function findReady(array $filters): ?VieFundTransactionWorkingSet
    {
        if (!$this->available()) {
            return null;
        }

        $workingSet = VieFundTransactionWorkingSet::query()
            ->where('scope_hash', $this->scopeHash($filters))
            ->first();

        return $workingSet?->isReady() ? $workingSet : null;
    }

    public function findQueryable(array $filters): ?VieFundTransactionWorkingSet
    {
        if (!$this->available()) {
            return null;
        }

        $workingSet = VieFundTransactionWorkingSet::query()
            ->where('scope_hash', $this->scopeHash($filters))
            ->first();

        return $workingSet?->hasQueryableGeneration() ? $workingSet : null;
    }

    public function status(?VieFundTransactionWorkingSet $workingSet): array
    {
        if (!$workingSet) {
            return ['state' => 'unavailable'];
        }

        $queueState = $this->hydrationQueueState($workingSet);
        if ($queueState === 'queued'
            && $workingSet->updated_at
            && $workingSet->updated_at->isAfter(now()->subSeconds(15))) {
            $queueState = 'processing';
        }
        $rowsCached = (int) $workingSet->rows_cached;
        $totalRows = $workingSet->total_rows !== null ? (int) $workingSet->total_rows : null;
        $progressPct = $totalRows !== null
            ? ($totalRows === 0 ? 100.0 : round(min(1, $rowsCached / $totalRows) * 100, 1))
            : null;
        $etaSeconds = null;
        if (in_array($workingSet->state, ['warming', 'refreshing'], true)
            && $totalRows !== null
            && $totalRows > $rowsCached
            && $rowsCached > 0
            && $workingSet->started_at) {
            $elapsedSeconds = max(1, $workingSet->started_at->diffInSeconds(now()));
            $rowsPerSecond = $rowsCached / $elapsedSeconds;
            $etaSeconds = $rowsPerSecond > 0
                ? (int) ceil(($totalRows - $rowsCached) / $rowsPerSecond)
                : null;
        }

        return [
            'id' => $workingSet->id,
            'state' => $workingSet->state,
            'ready' => $workingSet->isReady(),
            'queryable' => $workingSet->hasQueryableGeneration(),
            'can_pause' => in_array($workingSet->state, ['warming', 'refreshing'], true)
                && $workingSet->build_generation !== null,
            'can_resume' => in_array($workingSet->state, ['paused', 'failed'], true)
                && $workingSet->build_generation !== null,
            'queue_state' => $queueState,
            'stalled' => in_array($workingSet->state, ['warming', 'refreshing'], true)
                && $workingSet->build_generation !== null
                && $queueState === 'missing',
            'rows_cached' => $rowsCached,
            'total_rows' => $totalRows,
            'progress_pct' => $progressPct,
            'eta_seconds' => $etaSeconds,
            'ready_at' => optional($workingSet->ready_at)->toIso8601String(),
            'paused_at' => optional($workingSet->paused_at)->toIso8601String(),
            'last_error' => $workingSet->last_error,
        ];
    }

    private function hydrationQueueState(VieFundTransactionWorkingSet $workingSet): ?string
    {
        if (!$workingSet->build_generation || !in_array($workingSet->state, ['warming', 'refreshing'], true)) {
            return null;
        }

        try {
            $connection = DB::connection(config('queue.connections.database.connection'));
            $table = (string) config('queue.connections.database.table', 'jobs');
            $jobs = $connection->table($table)
                ->where('payload', 'like', '%HydrateVieFundTransactionWorkingSet%')
                ->get(['payload', 'reserved_at']);
            $queued = false;

            foreach ($jobs as $queuedJob) {
                $payload = json_decode((string) $queuedJob->payload, true);
                $serialized = $payload['data']['command'] ?? null;
                if (!is_string($serialized)) {
                    continue;
                }

                $job = @unserialize($serialized, [
                    'allowed_classes' => [HydrateVieFundTransactionWorkingSet::class],
                ]);
                if (!$job instanceof HydrateVieFundTransactionWorkingSet
                    || $job->workingSetId !== $workingSet->id
                    || $job->generation !== $workingSet->build_generation) {
                    continue;
                }

                if ($queuedJob->reserved_at !== null) {
                    return 'processing';
                }
                $queued = true;
            }

            return $queued ? 'queued' : 'missing';
        } catch (Throwable) {
            return null;
        }
    }

    public function pruneExpired(): int
    {
        if (!$this->available()) {
            return 0;
        }

        return VieFundTransactionWorkingSet::query()
            ->where(function ($query): void {
                $query->where(function ($completed): void {
                    $completed->whereNull('build_generation')
                        ->where('expires_at', '<', now());
                })->orWhere(function ($failed): void {
                    $failed->where('state', 'failed')
                        ->where('updated_at', '<', now()->subDay());
                });
            })
            ->delete();
    }

    private function available(): bool
    {
        return Schema::hasTable('viefund_transaction_working_sets')
            && Schema::hasTable('viefund_transaction_working_set_rows');
    }

    private function scopeHash(array $filters): string
    {
        return sha1(json_encode([
            'version' => self::CACHE_VERSION,
            'scope' => $this->scope($filters),
        ], JSON_THROW_ON_ERROR));
    }

    private function ttlMinutes(): int
    {
        return $this->runtimeSettings->get('viefund.working_set.ttl_minutes');
    }
}
