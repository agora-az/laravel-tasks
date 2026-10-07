<?php

namespace App\Services\VieFund;

use App\Jobs\HydrateVieFundTransactionWorkingSet;
use App\Models\VieFundTransactionWorkingSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VieFundTransactionWorkingSetManager
{
    private const CACHE_VERSION = 2;

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

            $generation = (string) Str::uuid();
            $shouldDispatch = true;
            $workingSet->forceFill([
                'state' => $workingSet->active_generation ? 'refreshing' : 'warming',
                'build_generation' => $generation,
                'rows_cached' => 0,
                'last_error' => null,
                'started_at' => now(),
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

        return [
            'id' => $workingSet->id,
            'state' => $workingSet->state,
            'ready' => $workingSet->isReady(),
            'queryable' => $workingSet->hasQueryableGeneration(),
            'rows_cached' => (int) $workingSet->rows_cached,
            'total_rows' => $workingSet->total_rows !== null ? (int) $workingSet->total_rows : null,
            'ready_at' => optional($workingSet->ready_at)->toIso8601String(),
            'last_error' => $workingSet->last_error,
        ];
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
        return max(15, (int) config('viefund.all_transactions_working_set.ttl_minutes', 240));
    }
}
