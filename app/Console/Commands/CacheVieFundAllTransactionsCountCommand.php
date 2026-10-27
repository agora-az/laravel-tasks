<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundAllTransactionsCountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheVieFundAllTransactionsCountCommand extends Command
{
    protected $signature = 'report:cache-viefund-all-transactions-count
        {--cache-key= : Filter signature used for the cached result}
        {--payload= : Base64-encoded JSON search and filter payload}';

    protected $description = 'Calculate and cache the filtered All Transactions total outside the web request';

    public function handle(VieFundAllTransactionsCountService $counts): int
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
            $total = $counts->calculate($payload['source'] ?? ['source' => 'remote'], $search, $filters);
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

}
