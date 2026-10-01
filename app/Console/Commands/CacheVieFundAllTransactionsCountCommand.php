<?php

namespace App\Console\Commands;

use App\Models\SettlementInstruction;
use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheVieFundAllTransactionsCountCommand extends Command
{
    protected $signature = 'report:cache-viefund-all-transactions-count
        {--cache-key= : Filter signature used for the cached result}
        {--payload= : Base64-encoded JSON search and filter payload}';

    protected $description = 'Calculate and cache the filtered All Transactions total outside the web request';

    public function handle(VieFundRemoteService $remoteService): int
    {
        $cacheKey = trim((string) $this->option('cache-key'));
        $payload = json_decode((string) base64_decode((string) $this->option('payload'), true), true);
        $valueKey = "viefund_all_transactions_total:{$cacheKey}";
        $statusKey = "viefund_all_transactions_total_status:{$cacheKey}";
        $lockKey = "viefund_all_transactions_total_lock:{$cacheKey}";

        if (!preg_match('/^[a-f0-9]{40}$/', $cacheKey) || !is_array($payload)) {
            $this->error('A valid cache key and payload are required.');
            Cache::forget($lockKey);

            return self::FAILURE;
        }

        try {
            $search = trim((string) ($payload['search'] ?? ''));
            $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
            $hasAgraFspMatch = (bool) ($filters['has_agra_fsp_match'] ?? false);
            $has7960FspMatch = (bool) ($filters['has_7960_fsp_match'] ?? false);
            unset($filters['has_agra_fsp_match'], $filters['has_7960_fsp_match']);

            if ($hasAgraFspMatch) {
                $filters['agra_fsp_source_ids_json'] = $this->fspSourceIdsJson(
                    'fundserv_agra',
                    (string) ($filters['date_basis'] ?? 'settlement_date'),
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }
            if ($has7960FspMatch) {
                $filters['fsp_7960_source_ids_json'] = $this->fspSourceIdsJson(
                    'ltm',
                    (string) ($filters['date_basis'] ?? 'settlement_date'),
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            }

            $total = $remoteService->countAllTransactions($search !== '' ? $search : null, $filters);
            Cache::put($valueKey, $total, now()->addHour());
            Cache::put($statusKey, [
                'state' => 'complete',
                'total' => $total,
                'updated_at' => now()->toIso8601String(),
            ], now()->addHour());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($statusKey, [
                'state' => 'failed',
                'message' => 'The transaction total could not be calculated.',
                'updated_at' => now()->toIso8601String(),
            ], now()->addMinutes(2));

            return self::FAILURE;
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function fspSourceIdsJson(
        string $sourceType,
        string $dateBasis,
        ?string $dateFrom,
        ?string $dateTo
    ): string
    {
        $query = SettlementInstruction::query()
            ->where('source_type', $sourceType)
            ->whereNotNull('source_id')
            ->where('source_id', '<>', '');

        if ($dateBasis === 'settlement_date') {
            if ($dateFrom) {
                $query->where('settlement_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('settlement_date', '<=', $dateTo);
            }
        }

        return $query->distinct()
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(fn($sourceId) => trim((string) $sourceId))
            ->filter()
            ->values()
            ->toJson(JSON_THROW_ON_ERROR);
    }
}
