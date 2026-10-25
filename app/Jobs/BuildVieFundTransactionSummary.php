<?php

namespace App\Jobs;

use App\Models\VieFundTransactionSummary;
use App\Services\VieFund\VieFundTransactionSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildVieFundTransactionSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 110;

    public function __construct(
        public readonly int $summaryId,
        public readonly string $dispatchToken,
        public readonly int $cursor = 0
    ) {
        $this->onConnection('database_viefund_cache');
    }

    public function handle(VieFundTransactionSummaryService $service): void
    {
        $next = $service->advance($this->summaryId, $this->dispatchToken, $this->cursor);
        if ($next !== null) {
            self::dispatch($this->summaryId, $this->dispatchToken, $next);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?? 'Background summary calculation failed.';
        $updated = VieFundTransactionSummary::query()
            ->whereKey($this->summaryId)
            ->where('dispatch_token', $this->dispatchToken)
            ->where('cursor', $this->cursor)
            ->whereIn('state', ['queued', 'processing'])
            ->update(['state' => 'failed', 'last_error' => $message, 'updated_at' => now()]);
        if ($updated) {
            Log::error('Background transaction summary failed.', ['summary_id' => $this->summaryId, 'exception' => $exception]);
        }
    }
}
