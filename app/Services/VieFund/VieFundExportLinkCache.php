<?php

namespace App\Services\VieFund;

use App\Services\RuntimeSettings;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class VieFundExportLinkCache
{
    private const CACHE_KEYS_TABLE = 'viefund_export_link_cache_keys';
    private const EFT_ITEMS_TABLE = 'viefund_export_cached_eft_items';
    private const FUND_SOURCES_TABLE = 'viefund_export_cached_fund_sources';
    // Increment when the cached EFT payload shape changes.
    private const EFT_CACHE_TYPE = 'eft_items_v2';
    private const FUND_SOURCE_CACHE_TYPE = 'fund_sources';

    private ?bool $available = null;

    private array $statistics = [
        'cache_available' => false,
        'eft_requested_ids' => 0,
        'eft_cache_hits' => 0,
        'eft_refreshed_ids' => 0,
        'fund_source_requested_ids' => 0,
        'fund_source_cache_hits' => 0,
        'fund_source_refreshed_ids' => 0,
    ];

    public function __construct(private readonly RuntimeSettings $runtimeSettings)
    {
    }

    public function eftItemsByLinkedIds(
        array $linkedIds,
        SqlServerEftRemoteRepository $remoteRepository
    ): Collection {
        $ids = $this->normalizeIds($linkedIds);
        if ($ids->isEmpty()) {
            return collect();
        }

        $this->statistics['eft_requested_ids'] += $ids->count();
        if (!$this->cacheAvailable()) {
            return $remoteRepository->exportItemsByLinkedIds($ids->all());
        }

        try {
            $freshIds = $this->freshEntityIds(self::EFT_CACHE_TYPE, $ids);
            $staleIds = $ids->diff($freshIds)->values();
            $this->statistics['eft_cache_hits'] += $freshIds->count();
            $this->statistics['eft_refreshed_ids'] += $staleIds->count();

            foreach ($staleIds->chunk($this->remoteBatchSize()) as $chunk) {
                $items = $remoteRepository->exportItemsByLinkedIds($chunk->all());
                $this->storeEftItems($chunk, $items);
                unset($items);
            }

            return $this->loadEftItems($ids);
        } catch (Throwable $exception) {
            $this->disableAfterFailure('EFT', $exception);

            return $remoteRepository->exportItemsByLinkedIds($ids->all());
        }
    }

    public function fundSourceIdsForCashTransactions(
        array $cashTransactionIds,
        VieFundRemoteService $remoteService
    ): Collection {
        $ids = $this->normalizeIds($cashTransactionIds);
        if ($ids->isEmpty()) {
            return collect();
        }

        $this->statistics['fund_source_requested_ids'] += $ids->count();
        if (!$this->cacheAvailable()) {
            return $remoteService->fetchFundSourceIdsForCashTransactions($ids->all());
        }

        try {
            $freshIds = $this->freshEntityIds(self::FUND_SOURCE_CACHE_TYPE, $ids);
            $staleIds = $ids->diff($freshIds)->values();
            $this->statistics['fund_source_cache_hits'] += $freshIds->count();
            $this->statistics['fund_source_refreshed_ids'] += $staleIds->count();

            foreach ($staleIds->chunk($this->remoteBatchSize()) as $chunk) {
                $mappings = $remoteService->fetchFundSourceIdsForCashTransactions($chunk->all());
                $this->storeFundSources($chunk, $mappings);
                unset($mappings);
            }

            return $this->loadFundSources($ids);
        } catch (Throwable $exception) {
            $this->disableAfterFailure('fund Source ID', $exception);

            return $remoteService->fetchFundSourceIdsForCashTransactions($ids->all());
        }
    }

    public function statistics(): array
    {
        return $this->statistics;
    }

    public function pruneExpired(): array
    {
        if (!$this->cacheAvailable()) {
            return ['cache_keys' => 0, 'eft_items' => 0, 'fund_sources' => 0];
        }

        $cutoff = now()->subDays($this->runtimeSettings->get('viefund.link_cache.retention_days'));

        return [
            'cache_keys' => DB::table(self::CACHE_KEYS_TABLE)->where('cached_at', '<', $cutoff)->delete(),
            'eft_items' => DB::table(self::EFT_ITEMS_TABLE)->where('cached_at', '<', $cutoff)->delete(),
            'fund_sources' => DB::table(self::FUND_SOURCES_TABLE)->where('cached_at', '<', $cutoff)->delete(),
        ];
    }

    private function storeEftItems(Collection $requestedIds, Collection $items): void
    {
        $cachedAt = now();
        DB::transaction(function () use ($requestedIds, $items, $cachedAt) {
            foreach ($requestedIds->chunk($this->localWriteBatchSize()) as $chunk) {
                DB::table(self::EFT_ITEMS_TABLE)->whereIn('linked_id', $chunk->all())->delete();
            }

            $items
                ->unique(fn($item) => (int) $item->id)
                ->map(fn($item) => [
                    'remote_id' => (int) $item->id,
                    'linked_id' => (int) $item->linked_id,
                    'payload' => json_encode((array) $item, JSON_THROW_ON_ERROR),
                    'cached_at' => $cachedAt,
                ])
                ->chunk($this->localWriteBatchSize())
                ->each(fn(Collection $rows) => DB::table(self::EFT_ITEMS_TABLE)->upsert(
                    $rows->all(),
                    ['remote_id'],
                    ['linked_id', 'payload', 'cached_at']
                ));

            $this->markFresh(self::EFT_CACHE_TYPE, $requestedIds, $cachedAt);
        });
    }

    private function storeFundSources(Collection $requestedIds, Collection $mappings): void
    {
        $cachedAt = now();
        DB::transaction(function () use ($requestedIds, $mappings, $cachedAt) {
            foreach ($requestedIds->chunk($this->localWriteBatchSize()) as $chunk) {
                DB::table(self::FUND_SOURCES_TABLE)
                    ->whereIn('cash_transaction_id', $chunk->all())
                    ->delete();
            }

            $mappings
                ->filter(fn($mapping) => (int) ($mapping->cash_transaction_id ?? 0) > 0
                    && trim((string) ($mapping->source_id ?? '')) !== '')
                ->map(fn($mapping) => [
                    'cash_transaction_id' => (int) $mapping->cash_transaction_id,
                    'source_id' => trim((string) $mapping->source_id),
                    'cached_at' => $cachedAt,
                ])
                ->unique(fn(array $mapping) => $mapping['cash_transaction_id'] . '|' . $mapping['source_id'])
                ->chunk($this->localWriteBatchSize())
                ->each(fn(Collection $rows) => DB::table(self::FUND_SOURCES_TABLE)->upsert(
                    $rows->all(),
                    ['cash_transaction_id', 'source_id'],
                    ['cached_at']
                ));

            $this->markFresh(self::FUND_SOURCE_CACHE_TYPE, $requestedIds, $cachedAt);
        });
    }

    private function loadEftItems(Collection $ids): Collection
    {
        $cutoff = $this->freshnessCutoff();

        return $ids
            ->chunk($this->remoteBatchSize())
            ->flatMap(fn(Collection $chunk) => DB::table(self::EFT_ITEMS_TABLE)
                ->whereIn('linked_id', $chunk->all())
                ->where('cached_at', '>=', $cutoff)
                ->get(['payload']))
            ->map(fn($row) => json_decode((string) $row->payload, false, 512, JSON_THROW_ON_ERROR))
            ->sortBy(fn($item) => (int) $item->id)
            ->values();
    }

    private function loadFundSources(Collection $ids): Collection
    {
        $cutoff = $this->freshnessCutoff();

        return $ids
            ->chunk($this->remoteBatchSize())
            ->flatMap(fn(Collection $chunk) => DB::table(self::FUND_SOURCES_TABLE)
                ->whereIn('cash_transaction_id', $chunk->all())
                ->where('cached_at', '>=', $cutoff)
                ->get(['cash_transaction_id', 'source_id']))
            ->values();
    }

    private function freshEntityIds(string $cacheType, Collection $ids): Collection
    {
        $cutoff = $this->freshnessCutoff();

        return $ids
            ->chunk($this->remoteBatchSize())
            ->flatMap(fn(Collection $chunk) => DB::table(self::CACHE_KEYS_TABLE)
                ->where('cache_type', $cacheType)
                ->whereIn('entity_id', $chunk->all())
                ->where('cached_at', '>=', $cutoff)
                ->pluck('entity_id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function markFresh(string $cacheType, Collection $ids, mixed $cachedAt): void
    {
        $ids
            ->map(fn($id) => [
                'cache_type' => $cacheType,
                'entity_id' => (int) $id,
                'cached_at' => $cachedAt,
            ])
            ->chunk($this->localWriteBatchSize())
            ->each(fn(Collection $rows) => DB::table(self::CACHE_KEYS_TABLE)->upsert(
                $rows->all(),
                ['cache_type', 'entity_id'],
                ['cached_at']
            ));
    }

    private function cacheAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $this->available = Schema::hasTable(self::CACHE_KEYS_TABLE)
                && Schema::hasTable(self::EFT_ITEMS_TABLE)
                && Schema::hasTable(self::FUND_SOURCES_TABLE);
        } catch (Throwable $exception) {
            $this->available = false;
            Log::warning('Unable to inspect the VieFund export relationship cache.', [
                'exception' => $exception,
            ]);
        }

        $this->statistics['cache_available'] = $this->available;

        return $this->available;
    }

    private function disableAfterFailure(string $relationship, Throwable $exception): void
    {
        $this->available = false;
        $this->statistics['cache_available'] = false;
        Log::warning("VieFund export {$relationship} cache failed; using the remote source directly.", [
            'exception' => $exception,
        ]);
    }

    private function normalizeIds(array $ids): Collection
    {
        return collect($ids)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values();
    }

    private function freshnessCutoff(): mixed
    {
        return now()->subMinutes($this->runtimeSettings->get('viefund.link_cache.ttl_minutes'));
    }

    private function remoteBatchSize(): int
    {
        return $this->runtimeSettings->get('viefund.link_cache.remote_batch_size');
    }

    private function localWriteBatchSize(): int
    {
        return $this->runtimeSettings->get('viefund.link_cache.write_batch_size');
    }
}
