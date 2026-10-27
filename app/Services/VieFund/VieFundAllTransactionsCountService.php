<?php

namespace App\Services\VieFund;

use App\Jobs\CountVieFundAllTransactions;
use App\Models\SettlementInstruction;
use App\Models\VieFundTransactionMirrorSync;
use App\Models\VieFundTransactionWorkingSet;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VieFundAllTransactionsCountService
{
    private const WAIT_SECONDS = 600;
    private const STATUS_SECONDS = 720;

    public function __construct(
        private readonly VieFundTransactionWorkingSetQuery $workingSetQuery,
        private readonly VieFundTransactionMirrorQuery $mirrorQuery,
        private readonly VieFundRemoteService $remoteService
    ) {}

    public function forWorkingSet(VieFundTransactionWorkingSet $workingSet, ?string $search, array $filters): array
    {
        $metadata = [
            'source' => 'working_set',
            'rows_cached' => (int) $workingSet->rows_cached,
            'cache_state' => $workingSet->state,
        ];
        $total = $this->workingSetQuery->savedCount($workingSet, $search, $filters);
        if ($total !== null) {
            return $metadata + [
                'state' => $workingSet->isReady() ? 'complete' : 'partial', 'total' => $total, 'cached' => true,
            ];
        }

        return $metadata + $this->status($this->workingSetSnapshot($workingSet), $search, $filters);
    }

    public function forMirror(VieFundTransactionMirrorSync $sync, ?string $search, array $filters): array
    {
        if (!$sync->isReady()) {
            return ['state' => 'processing', 'message' => 'Waiting for mirror synchronization before calculating the total.'];
        }

        return ['source' => 'mirror'] + $this->status($this->mirrorSnapshot($sync), $search, $filters);
    }

    public function forRemote(?string $search, array $filters): array
    {
        return $this->status(['source' => 'remote'], $search, $filters);
    }

    private function status(array $source, ?string $search, array $filters): array
    {
        ksort($filters);
        $payload = ['version' => 2, 'source' => $source, 'search' => trim((string) $search), 'filters' => $filters];
        $signature = sha1(json_encode($payload, JSON_THROW_ON_ERROR));
        $result = Cache::get($this->valueKey($signature));
        if (is_array($result)) {
            return $result + ['cached' => true];
        }

        $status = Cache::get($this->statusKey($signature));
        if (is_array($status) && $status['state'] === 'processing') {
            $deadline = isset($status['started_at']) ? $status['started_at'] + 75 : $status['queued_at'] + self::WAIT_SECONDS;
            if (time() > $deadline) {
                $this->fail($signature, $status['token'], 'The transaction count worker stopped responding.');
                $status = Cache::get($this->statusKey($signature));
            }
        }
        if (is_array($status) && in_array($status['state'], ['processing', 'failed'], true)) {
            return ['state' => $status['state'], 'message' => $status['message']];
        }

        $token = (string) Str::uuid();
        $lock = Cache::lock($this->lockKey($signature), self::STATUS_SECONDS, $token);
        if ($lock->get()) {
            try {
                $result = Cache::get($this->valueKey($signature));
                $status = Cache::get($this->statusKey($signature));
                if (is_array($result)) {
                    $lock->release();
                    return $result + ['cached' => true];
                }
                if (is_array($status) && in_array($status['state'], ['processing', 'failed'], true)) {
                    $lock->release();
                    return ['state' => $status['state'], 'message' => $status['message']];
                }
                Cache::put($this->statusKey($signature), [
                    'state' => 'processing', 'token' => $token, 'queued_at' => time(),
                    'message' => 'Calculating the filtered transaction total in the background...',
                ], self::STATUS_SECONDS);
                if (config('queue.connections.database_viefund_cache.driver') !== 'database') {
                    throw new RuntimeException('Transaction totals require the database cache queue, not synchronous dispatch.');
                }
                Bus::dispatch(new CountVieFundAllTransactions($signature, $payload, $token));
                Log::channel('viefund_transaction_counts')->info('Transaction count queued.', [
                    'count_signature' => $signature, 'source' => $source,
                ]);
            } catch (Throwable $exception) {
                report($exception);
                $this->fail($signature, $token, $exception->getMessage());

                return ['state' => 'failed', 'message' => 'The background total could not be started. Please try again later.'];
            }
        }

        return ['state' => 'processing', 'message' => 'Calculating the filtered transaction total in the background...'];
    }

    public function build(string $signature, array $payload, string $token): void
    {
        $status = Cache::get($this->statusKey($signature));
        if (!is_array($status) || $status['state'] !== 'processing' || $status['token'] !== $token) {
            return;
        }
        try {
            if (time() > $status['queued_at'] + self::WAIT_SECONDS) {
                throw new RuntimeException('The transaction count waited too long for the cache worker.');
            }
            $status['started_at'] = time();
            Cache::put($this->statusKey($signature), $status, self::STATUS_SECONDS);
            $started = microtime(true);
            Log::channel('viefund_transaction_counts')->info('Transaction count started.', [
                'count_signature' => $signature, 'source' => $payload['source'],
                'queue_wait_seconds' => time() - $status['queued_at'],
            ]);
            $total = $this->calculate($payload['source'], $payload['search'], $payload['filters']);
            $current = Cache::get($this->statusKey($signature));
            if (!is_array($current) || $current['state'] !== 'processing' || $current['token'] !== $token) {
                return;
            }
            Cache::put($this->valueKey($signature), [
                'state' => ($payload['source']['partial'] ?? false) ? 'partial' : 'complete', 'total' => $total,
            ], ($payload['source']['partial'] ?? false) ? 5 : 3600);
            Cache::forget($this->statusKey($signature));
            Log::channel('viefund_transaction_counts')->info('Transaction count completed.', [
                'count_signature' => $signature, 'source' => $payload['source'],
                'total' => $total, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } finally {
            Cache::restoreLock($this->lockKey($signature), $token)->release();
        }
    }

    public function fail(string $signature, string $token, string $reason): void
    {
        $status = Cache::get($this->statusKey($signature));
        if (!is_array($status) || $status['state'] !== 'processing' || $status['token'] !== $token) {
            return;
        }
        Cache::put($this->statusKey($signature), [
            'state' => 'failed', 'message' => 'The transaction total is unavailable. You can still browse transactions. Refresh later to retry.',
        ], 120);
        Cache::restoreLock($this->lockKey($signature), $token)->release();
        Log::channel('viefund_transaction_counts')->error('Transaction count failed.', [
            'count_signature' => $signature, 'reason' => $reason,
        ]);
    }

    public function calculate(array $source, ?string $search, array $filters): int
    {
        $search = trim((string) $search);
        if ($source['source'] === 'working_set') {
            $workingSet = VieFundTransactionWorkingSet::query()->find($source['id']);
            if (!$workingSet || $this->workingSetSnapshot($workingSet) !== $source) {
                throw new RuntimeException('The period cache changed before the count started.');
            }
            $total = $this->workingSetQuery->count($workingSet, $search !== '' ? $search : null, $filters, bounded: true);
            $current = $workingSet->fresh();
            if (!$current || $this->workingSetSnapshot($current) !== $source) {
                throw new RuntimeException('The period cache changed while its total was being calculated.');
            }
        } elseif ($source['source'] === 'mirror') {
            $sync = VieFundTransactionMirrorSync::query()->find(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (!$sync?->isReady() || $this->mirrorSnapshot($sync) !== $source) {
                throw new RuntimeException('The transaction mirror changed before the count started.');
            }
            $total = $this->mirrorQuery->count($search !== '' ? $search : null, $filters, bounded: true);
            if (!$sync->refresh()->isReady() || $this->mirrorSnapshot($sync) !== $source) {
                throw new RuntimeException('The transaction mirror changed while its total was being calculated.');
            }
        } elseif ($source['source'] === 'remote') {
            $hasAgraFspMatch = (bool) ($filters['has_agra_fsp_match'] ?? false);
            $has7960FspMatch = (bool) ($filters['has_7960_fsp_match'] ?? false);
            unset($filters['has_agra_fsp_match'], $filters['has_7960_fsp_match']);
            foreach (['agra_fsp_source_ids_json' => [$hasAgraFspMatch, 'fundserv_agra'],
                'fsp_7960_source_ids_json' => [$has7960FspMatch, 'ltm']] as $key => [$enabled, $type]) {
                if ($enabled) {
                    $filters[$key] = $this->fspSourceIdsJson($type, $filters);
                }
            }
            $total = $this->remoteService->countAllTransactionsWithDeadline($search !== '' ? $search : null, $filters);
        } else {
            throw new RuntimeException('Unknown transaction count source.');
        }

        return $total;
    }

    private function workingSetSnapshot(VieFundTransactionWorkingSet $workingSet): array
    {
        return [
            'source' => 'working_set', 'id' => (int) $workingSet->id,
            'generation' => $workingSet->readableGeneration(), 'partial' => !$workingSet->isReady(),
        ];
    }

    private function mirrorSnapshot(VieFundTransactionMirrorSync $sync): array
    {
        return [
            'source' => 'mirror', 'generation' => $sync->sync_generation,
            'verified_at' => $sync->verified_at?->toIso8601String(),
            'synced_through_at' => $sync->synced_through_at?->toIso8601String(),
            'fund_ids_verified_at' => $sync->fund_ids_verified_at?->toIso8601String(),
            'cashagch_verified_at' => $sync->cashagch_verified_at?->toIso8601String(),
        ];
    }

    private function fspSourceIdsJson(string $sourceType, array $filters): string
    {
        $query = SettlementInstruction::query()->where('source_type', $sourceType)
            ->whereNotNull('source_id')->where('source_id', '<>', '');
        if (($filters['date_basis'] ?? 'settlement_date') === 'settlement_date') {
            if ($filters['date_from'] ?? null) {
                $query->where('settlement_date', '>=', $filters['date_from']);
            }
            if ($filters['date_to'] ?? null) {
                $query->where('settlement_date', '<=', $filters['date_to']);
            }
        }
        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->selectRaw('/*+ MAX_EXECUTION_TIME(30000) */ source_id');
        }

        return $query->distinct()->orderBy('source_id')->pluck('source_id')
            ->map(fn($sourceId) => trim((string) $sourceId))->filter()->values()->toJson(JSON_THROW_ON_ERROR);
    }

    private function valueKey(string $signature): string
    {
        return "viefund_transaction_count_v2:{$signature}";
    }

    private function statusKey(string $signature): string
    {
        return "viefund_transaction_count_v2_status:{$signature}";
    }

    private function lockKey(string $signature): string
    {
        return "viefund_transaction_count_v2_lock:{$signature}";
    }
}
